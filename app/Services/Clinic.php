<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\DB;
use App\Validators\Input as V;
final class Clinic {
    public static function patient(array $d, int $id=0): int {
        $data=['first_name'=>V::text($d,'first_name',100),'last_name'=>V::text($d,'last_name',100),'document'=>V::text($d,'document',40),'phone'=>V::text($d,'phone',40),'email'=>V::email($d,'email',false),'address'=>V::text($d,'address',255,false),'emergency_contact'=>V::text($d,'emergency_contact',255,false),'status'=>V::choice($d,'status',['active','inactive'])];
        $data['birth_date']=empty($d['birth_date']) ? null : V::date($d['birth_date']);
        if ($data['birth_date'] && $data['birth_date']>date('Y-m-d')) throw new \DomainException('La fecha de nacimiento no puede ser futura.');
        return DB::transaction(function() use($data,$id) {
            if (DB::one('SELECT id FROM patients WHERE document=? AND id<>?',[$data['document'],$id])) throw new \DomainException('Ya existe un paciente con ese documento.');
            if ($id) { if (!DB::one('SELECT id FROM patients WHERE id=? FOR UPDATE',[$id])) throw new \DomainException('Paciente no encontrado.'); DB::query('UPDATE patients SET '.implode(',',array_map(fn($k)=>"$k=?",array_keys($data))).' WHERE id=?',[...array_values($data),$id]); }
            else { $id=DB::insert('patients',['code'=>'YO-'.strtoupper(bin2hex(random_bytes(5))),...$data]); DB::insert('clinical_records',['patient_id'=>$id]); }
            DB::audit($id ? 'patient_saved':'patient_created','patients',$id); return $id;
        });
    }
    public static function appointment(array $d, int $id=0, bool $public=false, ?callable $onSaved=null): int {
        $service=DB::one('SELECT * FROM services WHERE id=? AND active=1',[V::id($d,'service_id')]);
        if (!$service) throw new \DomainException('Selecciona un servicio disponible.');
        $date=V::date(V::text($d,'date',10)); $time=V::text($d,'time',5);
        if (!preg_match('/^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/D',$time)) throw new \DomainException('Hora no válida.');
        $local=new \DateTimeImmutable($date.' '.$time); $end=$local->modify('+'.(int)$service['duration_minutes'].' minutes');
        if ($local<new \DateTimeImmutable('now') && !$id) throw new \DomainException('Selecciona una fecha y hora futuras.');
        $utc=new \DateTimeZone('UTC');
        $data=['service_id'=>$service['id'],'starts_at'=>$local->setTimezone($utc)->format('Y-m-d H:i:s'),'ends_at'=>$end->setTimezone($utc)->format('Y-m-d H:i:s'),'reason'=>V::text($d,'reason',2000,false)];
        if ($public) {
            if (empty($d['consent'])) throw new \DomainException('Debes aceptar el uso de tus datos para gestionar la solicitud.');
            $data+=['patient_id'=>null,'dentist_id'=>null,'contact_name'=>V::text($d,'contact_name',150),'contact_phone'=>V::text($d,'contact_phone',40),'contact_email'=>V::email($d,'contact_email'),'status'=>'pending','source'=>'public','request_key'=>hash('sha256',V::text($d,'request_key',100))];
        } else {
            $data+=['patient_id'=>V::id($d,'patient_id'),'dentist_id'=>V::id($d,'dentist_id'),'status'=>V::choice($d,'status',['pending','confirmed','attended','completed','cancelled','no_show'])];
        }
        return DB::transaction(function() use($data,$id,$public,$local,$end,$onSaved) {
            $before=$id ? DB::one('SELECT starts_at,ends_at,status FROM appointments WHERE id=? FOR UPDATE',[$id]):null;
            if ($public) { $existing=DB::one('SELECT id FROM appointments WHERE request_key=?',[$data['request_key']]); if ($existing) return (int)$existing['id']; }
            if (!$public) {
                // Lock the professional row before checking ranges: all competing bookings serialize.
                $dentist=DB::one('SELECT * FROM dentists WHERE id=? AND active=1 FOR UPDATE',[$data['dentist_id']]);
                if (!$dentist) throw new \DomainException('El profesional no está disponible.');
                if (!DB::one("SELECT id FROM patients WHERE id=? AND status='active'",[$data['patient_id']])) throw new \DomainException('El paciente no está activo.');
                if ($local->format('N')>=7 || $local->format('H:i:s')<$dentist['work_start'] || $end->format('H:i:s')>$dentist['work_end'] || $local->format('Y-m-d')!==$end->format('Y-m-d')) throw new \DomainException('Horario no disponible. Atención de lunes a sábado dentro del horario del profesional.');
                if (!in_array($data['status'],['cancelled','no_show'],true) && DB::one("SELECT id FROM appointments WHERE dentist_id=? AND id<>? AND status NOT IN ('cancelled','no_show') AND starts_at<? AND ends_at>?",[$data['dentist_id'],$id,$data['ends_at'],$data['starts_at']])) throw new \DomainException('Existe un conflicto de horario con otra cita.');
            }
            if ($id) { if (!DB::one('SELECT id FROM appointments WHERE id=? FOR UPDATE',[$id])) throw new \DomainException('Cita no encontrada.'); DB::query('UPDATE appointments SET '.implode(',',array_map(fn($k)=>"$k=?",array_keys($data))).' WHERE id=?',[...array_values($data),$id]); }
            else $id=DB::insert('appointments',$data);
            $action=$public ? 'appointment_requested':'appointment_saved';
            if ($before) {
                if ($before['status']!==$data['status']) $action=['cancelled'=>'appointment_cancelled','attended'=>'appointment_attended','completed'=>'appointment_completed'][$data['status']] ?? 'appointment_status_changed';
                elseif ($before['starts_at']!==$data['starts_at'] || $before['ends_at']!==$data['ends_at']) $action='appointment_rescheduled';
            }
            DB::audit($action,'appointments',$id,$before ? json_encode(['before'=>$before,'after'=>array_intersect_key($data,$before)],JSON_THROW_ON_ERROR):'');
            // Internal integrations join this transaction so a linked clinical appointment is atomic.
            if ($onSaved) $onSaved($id);
            return $id;
        });
    }
    public static function clinical(array $d): int {
        return DB::transaction(function() use($d) {
            $record=DB::one('SELECT * FROM clinical_records WHERE patient_id=? FOR UPDATE',[V::id($d,'patient_id')]); if (!$record) throw new \DomainException('Historia no encontrada.');
            if (empty($d['confirm_sign'])) throw new \DomainException('Confirma la firma de la entrada clínica.');
            $correction=empty($d['correction_of']) ? null : V::id($d,'correction_of');
            if ($correction && !DB::one('SELECT id FROM clinical_record_entries WHERE id=? AND clinical_record_id=?',[$correction,$record['id']])) throw new \DomainException('La corrección no corresponde a esta historia.');
            $data=['clinical_record_id'=>$record['id'],'user_id'=>user()['id'],'correction_of'=>$correction];
            foreach (['medical_history','dental_history','allergies'] as $field) $data[$field]=V::text($d,$field,$field==='allergies' ? 2000:10000,false);
            foreach (['consultation_reason','evaluation','diagnosis','plan','procedures','observation'] as $field) $data[$field]=V::text($d,$field,10000,!in_array($field,['procedures','observation'],true));
            DB::query('UPDATE clinical_records SET medical_history=?,dental_history=?,allergies=? WHERE id=?',[V::text($d,'medical_history',10000,false),V::text($d,'dental_history',10000,false),V::text($d,'allergies',2000,false),$record['id']]);
            $id=DB::insert('clinical_record_entries',$data); DB::audit($correction ? 'clinical_correction':'clinical_signed','clinical_record_entries',$id); return $id;
        });
    }
    // Dental development does not follow a hard cutoff; these ages only seed an initial suggestion
    // that the dentist is always free to override from direct clinical observation.
    public static function dentitionStage(?string $birthDate): ?array {
        if (!$birthDate) return null;
        $birth=\DateTimeImmutable::createFromFormat('!Y-m-d',$birthDate); $today=new \DateTimeImmutable('today');
        if (!$birth || $birth>$today) return null;
        $age=$birth->diff($today)->y;
        $stage=$age<6 ? 'pediatric' : ($age<12 ? 'mixed' : 'permanent');
        return ['age'=>$age,'stage'=>$stage];
    }
    public static function odontogramState(int $patientId, ?int $version=null): array {
        $record=DB::one('SELECT r.*,p.first_name,p.last_name,p.code,p.birth_date FROM clinical_records r JOIN patients p ON p.id=r.patient_id WHERE r.patient_id=?',[$patientId]);
        if (!$record) {
            if ($patientId) throw new \DomainException('El paciente no tiene una historia clínica disponible.');
            return ['record'=>null];
        }
        $versions=DB::all('SELECT o.*,u.name author,EXISTS(SELECT 1 FROM audit_logs a WHERE a.entity="odontograms" AND a.entity_id=o.id AND a.action="odontogram_evaluation") AS new_evaluation FROM odontograms o JOIN users u ON u.id=o.user_id WHERE o.clinical_record_id=? ORDER BY o.id DESC',[$record['id']]);
        $latest=$versions[0] ?? null;
        // An explicit version must belong to this patient; zero must never open a blank chart.
        if ($version!==null && !in_array($version,array_map('intval',array_column($versions,'id')),true)) throw new \DomainException('Versión no disponible para este paciente.');
        $version=$version ?? (int)($latest['id'] ?? 0);
        $findings=self::odontogramFindings($version);
        $treatmentHistory=DB::all('SELECT i.id,i.tooth_fdi,i.notes,i.completed_at,t.name treatment,d.name professional,pl.name plan FROM treatment_plan_items i JOIN treatment_plans pl ON pl.id=i.plan_id JOIN treatments t ON t.id=i.treatment_id JOIN dentists d ON d.id=pl.dentist_id WHERE pl.patient_id=? AND i.status="completed" ORDER BY i.completed_at DESC,i.id DESC',[$patientId]);
        $recommendation=self::dentitionStage($record['birth_date'] ?? null);
        // The chart always shows the type of whichever version is actually open — the latest one when
        // editing, or that specific record when browsing history — never silently swapped for a fresh guess.
        $current=current(array_filter($versions,static fn($v)=>(int)$v['id']===$version)) ?: $latest;
        $suggestedType=$current['dentition_type'] ?? $recommendation['stage'] ?? 'permanent';
        return ['record'=>$record,'versions'=>$versions,'latest'=>$latest,'version'=>$version,'findings'=>$findings,'treatment_history'=>$treatmentHistory,'recommendation'=>$recommendation,'suggested_type'=>$suggestedType];
    }
    private static function odontogramFindings(int $version): array {
        return array_map(static fn($f)=>[...$f,'tooth_fdi'=>(int)$f['tooth_fdi'],'observation'=>$f['observation'] ?? ''],DB::all('SELECT tooth_fdi,surface,state,observation FROM odontogram_findings WHERE odontogram_id=? ORDER BY tooth_fdi,surface',[$version]));
    }
    public static function odontogram(array $d): int {
        $action=V::choice(['action'=>$d['action'] ?? 'save'],'action',['save','create','new_evaluation']);
        if ($action!=='new_evaluation' && !array_key_exists('findings',$d)) throw new \DomainException('No se recibieron los datos del odontograma. Recarga antes de guardar.');
        $findings=$action==='new_evaluation' ? [] : json_decode((string)$d['findings'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($findings) || !array_is_list($findings) || count($findings)>312) throw new \DomainException('Odontograma no válido.');
        $normalized=[];
        foreach ($findings as $f) {
            if (!is_array($f)) throw new \DomainException('Hallazgo dental no válido.');
            $finding=['tooth_fdi'=>V::id($f,'tooth_fdi'),'surface'=>V::choice($f,'surface',['whole','mesial','distal','vestibular','lingual','occlusal']),'state'=>V::choice($f,'state',['healthy','caries','restoration','missing','planned_procedure','performed']),'observation'=>V::text($f,'observation',1000,false)];
            $key=$finding['tooth_fdi'].':'.$finding['surface'];
            if (isset($normalized[$key])) throw new \DomainException('La misma superficie está repetida en el odontograma.');
            $normalized[$key]=$finding;
        }
        $findings=array_values($normalized);
        usort($findings,fn($a,$b)=>[$a['tooth_fdi'],$a['surface']]<=>[$b['tooth_fdi'],$b['surface']]);
        $patientId=V::id($d,'patient_id');
        // Optional on the wire — callers that don't care about the dentition assistant (older forms, scripts,
        // tests) simply inherit the chart's existing type instead of being forced to declare one.
        $dentitionTypeInput=isset($d['dentition_type']) && $d['dentition_type']!=='' ? V::choice($d,'dentition_type',['pediatric','mixed','permanent']) : null;
        $overrideReason=V::text($d,'override_reason',500,false);
        return DB::transaction(function() use($d,$findings,$action,$patientId,$dentitionTypeInput,$overrideReason) {
            $record=DB::one('SELECT id FROM clinical_records WHERE patient_id=? FOR UPDATE',[$patientId]); if (!$record) throw new \DomainException('Historia no encontrada.');
            $previous=DB::one('SELECT id,dentition_type FROM odontograms WHERE clinical_record_id=? ORDER BY id DESC LIMIT 1',[$record['id']]);
            if ($action==='create' && $previous) throw new \DomainException('Este paciente ya tiene un odontograma. Abre el actual para actualizarlo.');
            if ($action==='new_evaluation' && !$previous) throw new \DomainException('Registra el primer odontograma antes de iniciar otra evaluación.');
            if (!array_key_exists('previous_id',$d) || !preg_match('/^\d+$/D',(string)$d['previous_id'])) throw new \DomainException('Falta la versión de referencia del odontograma. Recarga la página.');
            if ((int)($d['previous_id'] ?? 0)!==(int)($previous['id'] ?? 0)) throw new \DomainException('Otra persona actualizó el odontograma. Recarga para revisar los cambios.');
            $dentitionType=$dentitionTypeInput ?? $previous['dentition_type'] ?? 'permanent';
            $saved=self::odontogramFindings((int)($previous['id'] ?? 0));
            if ($action==='new_evaluation') {
                // Start the next consultation from the known state, never from an empty chart.
                $findings=$saved;
            } elseif ($previous) {
                foreach ($saved as $f) {
                    if (!array_filter($findings,fn($incoming)=>(int)$incoming['tooth_fdi']===(int)$f['tooth_fdi'] && ($incoming['surface']===$f['surface'] || $incoming['surface']==='whole'))) throw new \DomainException('Faltan hallazgos guardados. Actualiza las piezas correspondientes sin vaciar el odontograma.');
                }
                // Repeated submits with nothing changed — findings or the chart type — must not produce duplicate versions.
                if ($findings===$saved && $dentitionType===$previous['dentition_type']) return (int)$previous['id'];
            }
            // The recommendation is recomputed from the patient's current age at the moment of saving, kept only
            // as a clinical breadcrumb: it never overwrites the chart type the dentist actually chose.
            $birthDate=DB::one('SELECT birth_date FROM patients WHERE id=?',[$patientId])['birth_date'] ?? null;
            $recommendedType=self::dentitionStage($birthDate)['stage'] ?? null;
            $id=DB::insert('odontograms',['clinical_record_id'=>$record['id'],'user_id'=>user()['id'],'previous_id'=>$previous['id'] ?? null,'dentition_type'=>$dentitionType,'recommended_type'=>$recommendedType,'override_reason'=>$recommendedType && $recommendedType!==$dentitionType ? $overrideReason : null]);
            foreach ($findings as $f) DB::insert('odontogram_findings',['odontogram_id'=>$id,...$f]);
            DB::audit($action==='new_evaluation' ? 'odontogram_evaluation':'odontogram_version','odontograms',$id,json_encode(['dentition_type'=>$dentitionType,'recommended_type'=>$recommendedType],JSON_THROW_ON_ERROR)); return $id;
        });
    }
    public static function plan(array $d): int {
        return DB::transaction(function() use($d) {
            $id=DB::insert('treatment_plans',['patient_id'=>V::id($d,'patient_id'),'dentist_id'=>V::id($d,'dentist_id'),'name'=>V::text($d,'name',150)]);
            self::planItem($d,$id); DB::audit('plan_created','treatment_plans',$id); return $id;
        });
    }
    public static function planItem(array $d,int $planId): int {
        $t=DB::one('SELECT * FROM treatments WHERE id=?',[V::id($d,'treatment_id')]); if (!$t) throw new \DomainException('Tratamiento no encontrado.');
        $specialty=isset($d['specialty']) && $d['specialty']!=='' ? V::choice($d,'specialty',['general','endodontics','restoration','periodontics','orthodontics','prosthetics','surgery']) : 'general';
        $diagnosisId=empty($d['clinical_diagnosis_id']) ? null : V::id($d,'clinical_diagnosis_id');
        if ($diagnosisId && !DB::one('SELECT id FROM clinical_diagnoses WHERE id=?',[$diagnosisId])) throw new \DomainException('El diagnóstico clínico indicado no existe.');
        $id=DB::insert('treatment_plan_items',[
            'plan_id'=>$planId,'treatment_id'=>$t['id'],'tooth_fdi'=>empty($d['tooth_fdi']) ? null : V::id($d,'tooth_fdi'),
            'clinical_diagnosis_id'=>$diagnosisId,'specialty'=>$specialty,
            'priority'=>isset($d['priority']) && $d['priority']!=='' ? V::choice($d,'priority',['low','normal','high','urgent']):'normal',
            'justification'=>V::text($d,'justification',1000,false),
            'estimated_sessions'=>empty($d['estimated_sessions']) ? null : V::id($d,'estimated_sessions'),
            'price'=>$t['price'],'notes'=>V::text($d,'notes',2000,false),
        ]);
        // A specialty with a configured protocol gets its checklist seeded once, right away — stages belong
        // to this specific treatment from here on, so later edits to the template never touch this copy.
        $template=DB::all('SELECT stage_key,stage_name,sort_order FROM treatment_stage_templates WHERE specialty=? AND active=1 ORDER BY sort_order',[$specialty]);
        foreach ($template as $stage) DB::insert('treatment_stages',['treatment_plan_item_id'=>$id,'stage_key'=>$stage['stage_key'],'stage_name'=>$stage['stage_name'],'sort_order'=>$stage['sort_order']]);
        return $id;
    }
    public static function payment(array $d): int {
        $cents=V::cents($d,'amount'); if ($cents<=0) throw new \DomainException('El pago debe ser mayor que cero.');
        return DB::transaction(function() use($d,$cents) {
            $plan=DB::one('SELECT * FROM treatment_plans WHERE id=? FOR UPDATE',[V::id($d,'plan_id')]); if (!$plan || $plan['status']==='cancelled') throw new \DomainException('Plan no disponible.');
            $key=hash('sha256',V::text($d,'request_key',100));
            if ($previous=DB::one('SELECT * FROM payments WHERE request_key=?',[$key])) {
                if ((int)$previous['plan_id']!==(int)$plan['id'] || V::cents($previous,'amount')!==$cents || $previous['method']!==V::choice($d,'method',['cash','card','transfer']) || ($previous['reference'] ?? '')!==V::text($d,'reference',150,false)) throw new \DomainException('Este envío ya se utilizó para otro pago. Recarga el formulario antes de continuar.');
                return (int)$previous['id'];
            }
            $total=(int)round((float)DB::query("SELECT COALESCE(SUM(price),0) FROM treatment_plan_items WHERE plan_id=? AND status<>'cancelled'",[$plan['id']])->fetchColumn()*100);
            $paid=(int)round((float)DB::query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE plan_id=? AND voided_at IS NULL',[$plan['id']])->fetchColumn()*100);
            if ($cents>$total-$paid) throw new \DomainException('El importe supera el saldo pendiente.');
            $id=DB::insert('payments',['plan_id'=>$plan['id'],'user_id'=>user()['id'],'amount'=>number_format($cents/100,2,'.',''),'method'=>V::choice($d,'method',['cash','card','transfer']),'reference'=>V::text($d,'reference',150,false),'request_key'=>$key]);
            DB::insert('payment_receipts',['payment_id'=>$id,'number'=>'YO-'.str_pad((string)$id,8,'0',STR_PAD_LEFT)]); DB::audit('payment_registered','payments',$id); return $id;
        });
    }
    public static function voidPayment(array $d): void {
        DB::transaction(function() use($d) {
            $id=V::id($d,'id'); $payment=DB::one('SELECT plan_id FROM payments WHERE id=?',[$id]); if (!$payment) throw new \DomainException('Pago no encontrado.');
            DB::one('SELECT id FROM treatment_plans WHERE id=? FOR UPDATE',[$payment['plan_id']]);
            if (!DB::query('UPDATE payments SET voided_at=UTC_TIMESTAMP(),voided_by=?,void_reason=? WHERE id=? AND voided_at IS NULL',[user()['id'],V::text($d,'void_reason',255),$id])->rowCount()) throw new \DomainException('El pago ya fue anulado.');
            DB::audit('payment_voided','payments',$id);
        });
    }
    public static function inventory(array $d): int {
        $raw=V::text($d,'quantity',20); if (!preg_match('/^-?\d{1,7}(\.\d{1,2})?$/D',$raw) || (float)$raw==0) throw new \DomainException('Cantidad no válida. Usa valores positivos para entradas y negativos para salidas.');
        return DB::transaction(function() use($d,$raw) {
            $item=DB::one('SELECT * FROM inventory_items WHERE id=? FOR UPDATE',[V::id($d,'item_id')]); if (!$item) throw new \DomainException('Material no encontrado.');
            $lotName=V::text($d,'lot',100); $lot=DB::one('SELECT * FROM inventory_lots WHERE item_id=? AND lot=? FOR UPDATE',[$item['id'],$lotName]);
            if (!$lot) { if ((float)$raw<0) throw new \DomainException('El lote no existe.'); $lotId=DB::insert('inventory_lots',['item_id'=>$item['id'],'lot'=>$lotName,'expires_on'=>empty($d['expires_on']) ? null : V::date($d['expires_on'])]); $lot=['id'=>$lotId,'stock'=>0,'expires_on'=>$d['expires_on'] ?? null]; }
            if ((float)$raw<0 && $lot['expires_on'] && $lot['expires_on']<date('Y-m-d')) throw new \DomainException('No se permite consumir un lote vencido.');
            if ((float)$lot['stock']+(float)$raw<0 || (float)$item['stock']+(float)$raw<0) throw new \DomainException('Stock insuficiente en el lote.');
            DB::query('UPDATE inventory_lots SET stock=stock+? WHERE id=?',[$raw,$lot['id']]); DB::query('UPDATE inventory_items SET stock=stock+? WHERE id=?',[$raw,$item['id']]);
            $id=DB::insert('inventory_movements',['item_id'=>$item['id'],'lot_id'=>$lot['id'],'user_id'=>user()['id'],'quantity'=>$raw,'reason'=>V::text($d,'reason',255)]); DB::audit('stock_movement','inventory_movements',$id); return $id;
        });
    }
}
