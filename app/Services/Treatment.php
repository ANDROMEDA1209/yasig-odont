<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\DB;
use App\Validators\Input as V;
// The clinical layer on top of treatment_plan_items: diagnoses tied to a tooth, a configurable stage
// checklist per specialty (Endodoncia ships seeded; others start empty until a protocol is configured),
// session-by-session evolution, and the aggregation that feeds the treatment detail screen.
final class Treatment {
    private const SURFACES = ['mesial','distal','vestibular','lingual','occlusal'];
    private const CLINICAL_STATUSES = ['proposed','pending_acceptance','accepted','scheduled','in_progress','paused','completed','needs_followup','referred','rejected','cancelled'];
    private const STAGE_STATUSES = ['pending','in_progress','completed','not_applicable','needs_review'];

    public static function diagnose(array $d): int {
        $patientId=V::id($d,'patient_id'); $toothFdi=V::id($d,'tooth_fdi'); $diagnosisId=V::id($d,'diagnosis_id');
        $surfaces=[]; foreach ((array)($d['surfaces'] ?? []) as $s) { if (!in_array($s,self::SURFACES,true)) throw new \DomainException('Superficie no válida.'); $surfaces[$s]=$s; }
        $notes=V::text($d,'notes',1000,false);
        $odontogramId=empty($d['odontogram_id']) ? null : V::id($d,'odontogram_id');
        return DB::transaction(function() use($patientId,$toothFdi,$diagnosisId,$surfaces,$notes,$odontogramId) {
            $record=DB::one('SELECT id FROM clinical_records WHERE patient_id=?',[$patientId]); if (!$record) throw new \DomainException('Historia no encontrada.');
            if (!DB::one('SELECT fdi FROM teeth WHERE fdi=?',[$toothFdi])) throw new \DomainException('Pieza dental no válida.');
            if (!DB::one('SELECT id FROM diagnosis_catalog WHERE id=? AND active=1',[$diagnosisId])) throw new \DomainException('Diagnóstico no disponible en el catálogo.');
            if ($odontogramId && !DB::one('SELECT id FROM odontograms WHERE id=? AND clinical_record_id=?',[$odontogramId,$record['id']])) throw new \DomainException('El odontograma indicado no corresponde a este paciente.');
            $id=DB::insert('clinical_diagnoses',['clinical_record_id'=>$record['id'],'odontogram_id'=>$odontogramId,'tooth_fdi'=>$toothFdi,'diagnosis_id'=>$diagnosisId,'notes'=>$notes,'user_id'=>user()['id']]);
            foreach ($surfaces as $surface) DB::insert('clinical_diagnosis_surfaces',['clinical_diagnosis_id'=>$id,'surface'=>$surface]);
            DB::audit('diagnosis_registered','clinical_diagnoses',$id,json_encode(['tooth_fdi'=>$toothFdi,'diagnosis_id'=>$diagnosisId],JSON_THROW_ON_ERROR));
            return $id;
        });
    }

    public static function resolveDiagnosis(array $d): void {
        $id=V::id($d,'id'); $notes=V::text($d,'notes',500,false);
        DB::transaction(function() use($id,$notes) {
            if (!DB::one('SELECT id FROM clinical_diagnoses WHERE id=? AND status="active" FOR UPDATE',[$id])) throw new \DomainException('El diagnóstico no está activo o no existe.');
            DB::query('UPDATE clinical_diagnoses SET status="resolved" WHERE id=?',[$id]);
            DB::audit('diagnosis_resolved','clinical_diagnoses',$id,$notes);
        });
    }

    // Independent from treatment_plan_items.status (the financial/billing status payments and reports
    // already rely on): this is the clinical workflow the dentist actually walks through.
    public static function clinicalStatus(array $d): void {
        $id=V::id($d,'id'); $to=V::choice($d,'clinical_status',self::CLINICAL_STATUSES); $reason=V::text($d,'reason',1000,false);
        self::guardOrthodonticItem($id,true);
        DB::transaction(function() use($id,$to,$reason) {
            $item=DB::one('SELECT clinical_status FROM treatment_plan_items WHERE id=? FOR UPDATE',[$id]); if (!$item) throw new \DomainException('Tratamiento no encontrado.');
            if ($item['clinical_status']===$to) return;
            DB::query('UPDATE treatment_plan_items SET clinical_status=? WHERE id=?',[$to,$id]);
            DB::insert('treatment_status_history',['treatment_plan_item_id'=>$id,'field'=>'clinical_status','from_status'=>$item['clinical_status'],'to_status'=>$to,'reason'=>$reason,'user_id'=>user()['id']]);
            DB::audit('treatment_clinical_status_changed','treatment_plan_items',$id,json_encode(['from'=>$item['clinical_status'],'to'=>$to],JSON_THROW_ON_ERROR));
        });
    }

    // Completing or skipping a stage is a deliberate clinical act — never inferred from a payment, an
    // appointment, or a file upload happening to exist.
    public static function stage(array $d): void {
        $id=V::id($d,'id'); $status=V::choice($d,'status',self::STAGE_STATUSES);
        $notes=V::text($d,'notes',2000,false); $procedures=V::text($d,'procedures',2000,false);
        $materials=V::text($d,'materials',500,false); $incidents=V::text($d,'incidents',2000,false);
        $nextAction=V::text($d,'next_action',500,false);
        $sessionId=empty($d['session_id']) ? null : V::id($d,'session_id');
        DB::transaction(function() use($id,$status,$notes,$procedures,$materials,$incidents,$nextAction,$sessionId) {
            $stage=DB::one('SELECT * FROM treatment_stages WHERE id=? FOR UPDATE',[$id]); if (!$stage) throw new \DomainException('Etapa no encontrada.');
            self::guardOrthodonticItem((int)$stage['treatment_plan_item_id'],true);
            if ($sessionId && !DB::one('SELECT id FROM treatment_sessions WHERE id=? AND treatment_plan_item_id=?',[$sessionId,$stage['treatment_plan_item_id']])) throw new \DomainException('La sesión indicada no corresponde a este tratamiento.');
            $startedAt=$stage['started_at']; $completedAt=null;
            if (in_array($status,['in_progress','completed','needs_review'],true) && !$startedAt) $startedAt=gmdate('Y-m-d H:i:s');
            if ($status==='completed') $completedAt=gmdate('Y-m-d H:i:s');
            $professionalId=DB::one('SELECT id FROM dentists WHERE user_id=?',[user()['id']])['id'] ?? $stage['professional_id'];
            DB::query('UPDATE treatment_stages SET status=?,started_at=?,completed_at=?,professional_id=?,notes=?,procedures=?,materials=?,incidents=?,next_action=?,session_id=? WHERE id=?',
                [$status,$startedAt,$completedAt,$professionalId,$notes,$procedures,$materials,$incidents,$nextAction,$sessionId,$id]);
            DB::audit('treatment_stage_updated','treatment_stages',$id,json_encode(['stage_key'=>$stage['stage_key'],'from'=>$stage['status'],'to'=>$status],JSON_THROW_ON_ERROR));
        });
    }

    // Percent complete counts only the stages that actually apply to this case — "not_applicable" stages
    // are excluded from the denominator instead of silently counting as progress or as missing work.
    public static function progress(int $itemId): array {
        $stages=DB::all('SELECT status FROM treatment_stages WHERE treatment_plan_item_id=?',[$itemId]);
        $applicable=array_values(array_filter($stages,fn($s)=>$s['status']!=='not_applicable'));
        $completed=array_filter($applicable,fn($s)=>$s['status']==='completed');
        $total=count($applicable);
        return ['total'=>$total,'completed'=>count($completed),'percent'=>$total ? (int)round(count($completed)/$total*100):0,'has_stages'=>count($stages)>0];
    }

    public static function session(array $d): int {
        $itemId=V::id($d,'treatment_plan_item_id'); $dentistId=V::id($d,'dentist_id');
        self::guardOrthodonticItem($itemId,true);
        $date=V::date(V::text($d,'date',10)); $time=V::text($d,'time',5);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$time)) throw new \DomainException('Hora no válida.');
        if ((new \DateTimeImmutable($date.' '.$time))>new \DateTimeImmutable('+1 hour')) throw new \DomainException('La sesión no puede registrarse en el futuro.');
        $occurredAt=(new \DateTimeImmutable($date.' '.$time))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $fields=['objective'=>500,'tooth_state_start'=>500,'symptoms'=>1000,'findings'=>5000,'procedures_done'=>5000,'materials_used'=>1000,'incidents'=>2000,'tooth_state_end'=>500,'instructions'=>2000,'notes'=>2000];
        $data=[]; foreach ($fields as $k=>$max) $data[$k]=V::text($d,$k,$max,false);
        $nextAppointmentId=empty($d['next_appointment_id']) ? null : V::id($d,'next_appointment_id');
        return DB::transaction(function() use($itemId,$dentistId,$occurredAt,$data,$nextAppointmentId) {
            if (!DB::one('SELECT id FROM treatment_plan_items WHERE id=? FOR UPDATE',[$itemId])) throw new \DomainException('Tratamiento no encontrado.');
            if (!DB::one('SELECT id FROM dentists WHERE id=? AND active=1',[$dentistId])) throw new \DomainException('Profesional no disponible.');
            $next=(int)DB::query('SELECT COALESCE(MAX(session_number),0)+1 FROM treatment_sessions WHERE treatment_plan_item_id=?',[$itemId])->fetchColumn();
            $id=DB::insert('treatment_sessions',[...$data,'treatment_plan_item_id'=>$itemId,'dentist_id'=>$dentistId,'occurred_at'=>$occurredAt,'next_appointment_id'=>$nextAppointmentId,'user_id'=>user()['id'],'session_number'=>$next]);
            DB::audit('treatment_session_recorded','treatment_sessions',$id,json_encode(['session_number'=>$next],JSON_THROW_ON_ERROR));
            return $id;
        });
    }

    public static function detail(int $itemId): ?array {
        self::guardOrthodonticItem($itemId);
        $item=DB::one('SELECT i.*,t.name treatment_name,t.code treatment_code,pl.id plan_id,pl.patient_id,pl.name plan_name,CONCAT(p.first_name," ",p.last_name) patient_name,p.code patient_code FROM treatment_plan_items i JOIN treatments t ON t.id=i.treatment_id JOIN treatment_plans pl ON pl.id=i.plan_id JOIN patients p ON p.id=pl.patient_id WHERE i.id=?',[$itemId]);
        if (!$item) return null;
        $diagnosis=$item['clinical_diagnosis_id'] ? DB::one('SELECT cd.*,dc.name diagnosis_name,dc.category FROM clinical_diagnoses cd JOIN diagnosis_catalog dc ON dc.id=cd.diagnosis_id WHERE cd.id=?',[$item['clinical_diagnosis_id']]) : null;
        $stages=DB::all('SELECT st.*,d.name professional FROM treatment_stages st LEFT JOIN dentists d ON d.id=st.professional_id WHERE st.treatment_plan_item_id=? ORDER BY st.sort_order',[$itemId]);
        $sessions=DB::all('SELECT s.*,d.name dentist,u.name author FROM treatment_sessions s JOIN dentists d ON d.id=s.dentist_id JOIN users u ON u.id=s.user_id WHERE s.treatment_plan_item_id=? ORDER BY s.session_number DESC',[$itemId]);
        $history=DB::all('SELECT h.*,u.name author FROM treatment_status_history h JOIN users u ON u.id=h.user_id WHERE h.treatment_plan_item_id=? ORDER BY h.id DESC',[$itemId]);
        $files=DB::all('SELECT * FROM attachments WHERE treatment_plan_item_id=? ORDER BY COALESCE(captured_at,DATE(created_at)) DESC,id DESC',[$itemId]);
        return ['item'=>$item,'diagnosis'=>$diagnosis,'stages'=>$stages,'sessions'=>$sessions,'history'=>$history,'files'=>$files,'progress'=>self::progress($itemId)];
    }
    private static function guardOrthodonticItem(int $id,bool $write=false): void {
        if (!DB::one("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orth_cases'")) return;
        $case=DB::one('SELECT id FROM orth_cases WHERE treatment_plan_item_id=?',[$id]);
        if (!$case) return;
        Orthodontics::assertCase((int)$case['id'],$write);
        if ($write) throw new \DomainException('Registra las evoluciones y fases desde el expediente de ortodoncia.');
    }

    // One query, reused by the odontogram screen: every active/resolved diagnosis for the patient, each
    // with its surfaces — the view groups them by tooth client-side instead of one query per tooth.
    public static function patientDiagnoses(int $clinicalRecordId): array {
        $rows=DB::all('SELECT cd.id,cd.tooth_fdi,cd.diagnosis_id,dc.name diagnosis_name,dc.category,cd.notes,cd.status,cd.created_at,u.name author FROM clinical_diagnoses cd JOIN diagnosis_catalog dc ON dc.id=cd.diagnosis_id JOIN users u ON u.id=cd.user_id WHERE cd.clinical_record_id=? ORDER BY cd.id DESC',[$clinicalRecordId]);
        foreach ($rows as &$row) { $row['tooth_fdi']=(int)$row['tooth_fdi']; $row['surfaces']=array_column(DB::all('SELECT surface FROM clinical_diagnosis_surfaces WHERE clinical_diagnosis_id=?',[$row['id']]),'surface'); }
        return $rows;
    }

    // A chronological view of everything clinical that happened to a patient, for the "Evolución clínica" tab:
    // odontogram versions, signed clinical entries, diagnoses, treatment sessions and clinical files — merged
    // and sorted by date without touching or re-ordering any of the underlying records.
    public static function timeline(int $patientId): array {
        $record=DB::one('SELECT id FROM clinical_records WHERE patient_id=?',[$patientId]); if (!$record) return [];
        $events=[];
        foreach (DB::all('SELECT o.id,o.created_at,o.dentition_type,u.name author FROM odontograms o JOIN users u ON u.id=o.user_id WHERE o.clinical_record_id=?',[$record['id']]) as $o)
            $events[]=['type'=>'odontogram','at'=>$o['created_at'],'title'=>'Odontograma versión #'.$o['id'],'detail'=>dentition_stage_label($o['dentition_type']),'author'=>$o['author'],'ref'=>$o['id']];
        foreach (DB::all('SELECT e.id,e.signed_at,e.consultation_reason,e.correction_of,u.name author FROM clinical_record_entries e JOIN users u ON u.id=e.user_id WHERE e.clinical_record_id=?',[$record['id']]) as $e)
            $events[]=['type'=>'clinical_entry','at'=>$e['signed_at'],'title'=>($e['correction_of'] ? 'Corrección de entrada #'.$e['correction_of'] : 'Evolución clínica firmada'),'detail'=>$e['consultation_reason'],'author'=>$e['author'],'ref'=>$e['id']];
        foreach (self::patientDiagnoses($record['id']) as $diag)
            $events[]=['type'=>'diagnosis','at'=>$diag['created_at'],'title'=>'Diagnóstico · Pieza '.$diag['tooth_fdi'],'detail'=>$diag['diagnosis_name'],'author'=>$diag['author'],'ref'=>$diag['id']];
        foreach (DB::all('SELECT s.id,s.occurred_at,s.session_number,s.objective,t.name treatment,i.tooth_fdi,i.id item_id,u.name author FROM treatment_sessions s JOIN treatment_plan_items i ON i.id=s.treatment_plan_item_id JOIN treatment_plans pl ON pl.id=i.plan_id JOIN treatments t ON t.id=i.treatment_id JOIN users u ON u.id=s.user_id WHERE pl.patient_id=?',[$patientId]) as $s)
            $events[]=['type'=>'session','at'=>$s['occurred_at'],'title'=>$s['treatment'].' · Sesión '.$s['session_number'].($s['tooth_fdi'] ? ' · Pieza '.$s['tooth_fdi']:''),'detail'=>$s['objective'],'author'=>$s['author'],'ref'=>$s['item_id']];
        foreach (DB::all('SELECT a.id,a.created_at,a.original_name,a.category,a.description,u.name author FROM attachments a JOIN users u ON u.id=a.user_id WHERE a.clinical_record_id=?',[$record['id']]) as $a)
            $events[]=['type'=>'attachment','at'=>$a['created_at'],'title'=>'Archivo · '.ucfirst($a['category']),'detail'=>$a['description'] ?: $a['original_name'],'author'=>$a['author'],'ref'=>$a['id']];
        usort($events,fn($a,$b)=>$b['at']<=>$a['at']);
        return $events;
    }
}
