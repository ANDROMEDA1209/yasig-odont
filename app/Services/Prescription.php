<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\DB;
use App\Validators\Input as V;
// A prescription is append-only, like a signed clinical entry: once issued it is never edited, only
// cancelled with a reason if it was a mistake. Medication lines travel as parallel arrays (drug_name[],
// dosage[]...) so the repeater on the form can add or remove rows with plain HTML, no index bookkeeping.
final class Prescription {
    public static function create(array $d): int {
        $patientId=V::id($d,'patient_id');
        $dentistId=V::id($d,'dentist_id');
        $treatmentPlanItemId=empty($d['treatment_plan_item_id']) ? null : V::id($d,'treatment_plan_item_id');
        $sessionId=empty($d['session_id']) ? null : V::id($d,'session_id');
        $diagnosisSummary=V::text($d,'diagnosis_summary',500,false);
        $instructions=V::text($d,'instructions',1000,false);
        $items=[];
        foreach ((array)($d['drug_name'] ?? []) as $i=>$name) {
            $name=trim((string)$name); if ($name==='') continue;
            $dosage=trim((string)($d['dosage'][$i] ?? '')); $frequency=trim((string)($d['frequency'][$i] ?? ''));
            if ($dosage==='' || $frequency==='') throw new \DomainException('Cada medicamento necesita dosis y frecuencia.');
            $items[]=['drug_name'=>mb_substr($name,0,150),'presentation'=>mb_substr(trim((string)($d['presentation'][$i] ?? '')),0,100),'dosage'=>mb_substr($dosage,0,100),'frequency'=>mb_substr($frequency,0,100),'duration'=>mb_substr(trim((string)($d['duration'][$i] ?? '')),0,100),'route'=>mb_substr(trim((string)($d['route'][$i] ?? '')),0,50),'instructions'=>mb_substr(trim((string)($d['item_instructions'][$i] ?? '')),0,500)];
        }
        if (!$items) throw new \DomainException('Agrega al menos un medicamento con dosis y frecuencia.');
        if (count($items)>20) throw new \DomainException('Demasiados medicamentos en una sola receta.');
        return DB::transaction(function() use($patientId,$dentistId,$treatmentPlanItemId,$sessionId,$diagnosisSummary,$instructions,$items) {
            $record=DB::one('SELECT id FROM clinical_records WHERE patient_id=?',[$patientId]); if (!$record) throw new \DomainException('Historia no encontrada.');
            if (!DB::one('SELECT id FROM dentists WHERE id=? AND active=1',[$dentistId])) throw new \DomainException('Profesional no disponible.');
            if ($treatmentPlanItemId && !DB::one('SELECT i.id FROM treatment_plan_items i JOIN treatment_plans pl ON pl.id=i.plan_id WHERE i.id=? AND pl.patient_id=?',[$treatmentPlanItemId,$patientId])) throw new \DomainException('El tratamiento indicado no corresponde a este paciente.');
            if ($sessionId && !DB::one('SELECT id FROM treatment_sessions WHERE id=? AND treatment_plan_item_id=?',[$sessionId,$treatmentPlanItemId])) throw new \DomainException('La sesión indicada no corresponde al tratamiento.');
            $id=DB::insert('prescriptions',['clinical_record_id'=>$record['id'],'dentist_id'=>$dentistId,'user_id'=>user()['id'],'treatment_plan_item_id'=>$treatmentPlanItemId,'session_id'=>$sessionId,'diagnosis_summary'=>$diagnosisSummary,'instructions'=>$instructions]);
            foreach ($items as $order=>$item) DB::insert('prescription_items',['prescription_id'=>$id,'sort_order'=>$order,...$item]);
            DB::audit('prescription_issued','prescriptions',$id,json_encode(['items'=>count($items)],JSON_THROW_ON_ERROR));
            return $id;
        });
    }
    public static function cancel(array $d): void {
        $id=V::id($d,'id'); $reason=V::text($d,'cancel_reason',500);
        DB::transaction(function() use($id,$reason) {
            if (!DB::one('SELECT id FROM prescriptions WHERE id=? AND status="active" FOR UPDATE',[$id])) throw new \DomainException('La receta no está activa o no existe.');
            DB::query('UPDATE prescriptions SET status="cancelled",cancelled_at=UTC_TIMESTAMP(),cancelled_by=?,cancel_reason=? WHERE id=?',[user()['id'],$reason,$id]);
            DB::audit('prescription_cancelled','prescriptions',$id,$reason);
        });
    }
    public static function forPatient(int $patientId): array {
        return DB::all('SELECT p.*,d.name dentist,u.name author FROM prescriptions p JOIN dentists d ON d.id=p.dentist_id JOIN users u ON u.id=p.user_id JOIN clinical_records r ON r.id=p.clinical_record_id WHERE r.patient_id=? ORDER BY p.id DESC',[$patientId]);
    }
    public static function detail(int $id): ?array {
        $p=DB::one('SELECT p.*,d.name dentist,d.specialty dentist_specialty,u.name author,r.patient_id,CONCAT(pt.first_name," ",pt.last_name) patient_name,pt.code patient_code,pt.birth_date,pt.document patient_document FROM prescriptions p JOIN dentists d ON d.id=p.dentist_id JOIN users u ON u.id=p.user_id JOIN clinical_records r ON r.id=p.clinical_record_id JOIN patients pt ON pt.id=r.patient_id WHERE p.id=?',[$id]);
        if (!$p) return null;
        return ['prescription'=>$p,'items'=>DB::all('SELECT * FROM prescription_items WHERE prescription_id=? ORDER BY sort_order',[$id])];
    }
    // Renders straight to a real PDF with the clinic's own logo and streams it inline, so issuing a
    // prescription ends with the document itself on screen — no extra clicks to find and open it.
    // The logo travels as a base64 data URI instead of a file path: dompdf's chroot stays locked to
    // storage/cache (same as Reports::export), it never needs filesystem access beyond that directory.
    public static function pdf(array $detail): never {
        $rx=$detail['prescription']; $items=$detail['items'];
        DB::audit('prescription_printed','prescriptions',(int)$rx['id']);
        $logoHtml='';
        if ($path=brand_logo()) {
            $full=ROOT.'/public'.$path;
            $mime=strtolower(pathinfo($full,PATHINFO_EXTENSION))==='png' ? 'image/png':'image/jpeg';
            $logoHtml='<img src="data:'.$mime.';base64,'.base64_encode((string)file_get_contents($full)).'" style="height:64px">';
        }
        $address=setting('clinic_address',env('CLINIC_ADDRESS')) ?: 'Dirección pendiente de configurar';
        $phone=setting('clinic_phone',env('CLINIC_PHONE')) ?: 'Teléfono pendiente';
        $email=setting('clinic_email',env('CLINIC_EMAIL')) ?: 'Correo pendiente';
        $itemsHtml='';
        foreach ($items as $i=>$item) {
            $itemsHtml.='<div style="margin-bottom:13px;padding-bottom:11px;'.($i<count($items)-1 ? 'border-bottom:1px solid #e4eae8;':'').'">'
                .'<strong>'.($i+1).'. '.e($item['drug_name']).($item['presentation'] ? ' &middot; '.e($item['presentation']):'').'</strong><br>'
                .'<span style="font-size:11px;color:#52646a">'.e($item['dosage']).' &middot; '.e($item['frequency']).($item['duration'] ? ' &middot; Durante '.e($item['duration']):'').($item['route'] ? ' &middot; V&iacute;a '.e($item['route']):'').'</span>'
                .($item['instructions'] ? '<br><span style="font-size:10px;color:#7a8a86;font-style:italic">'.e($item['instructions']).'</span>':'')
                .'</div>';
        }
        $demoHtml=flag('APP_DEMO') ? '<p style="background:#fff9ed;border:1px solid #eee0be;color:#7c5c26;padding:8px 12px;font-size:10px;border-radius:5px">ENTORNO DE DEMOSTRACI&Oacute;N &mdash; DATOS FICTICIOS. No es un documento m&eacute;dico v&aacute;lido.</p>' : '';
        $cancelledHtml=$rx['status']==='cancelled' ? '<div style="margin:18px 0;padding:11px 15px;background:#fbf0f2;border:1px solid #e9cbd2;color:#8b2637;font-size:11px;border-radius:5px">RECETA ANULADA el '.e(date('d/m/Y',strtotime((string)$rx['cancelled_at']))).'. Motivo: '.e((string)$rx['cancel_reason']).'</div>' : '';
        $html='<html><head><meta charset="UTF-8"><style>
            body{font-family:DejaVu Sans;color:#202b32;font-size:12px}
            table{border-collapse:collapse}
            .muted{color:#52646a;font-size:10px}
            .rule{border-top:3px double #066466;margin:14px 0 16px}
            .patient td{padding:4px 12px 4px 0;font-size:11px}
            .label{text-transform:uppercase;color:#52646a;font-size:9px;letter-spacing:.04em}
        </style></head><body>
        <table width="100%"><tr><td>'.$logoHtml.'</td><td style="text-align:right">
            <strong style="color:#066466;font-size:12px">YASIG ODONT &middot; FAMILY DENTAL CARE</strong><br>
            <span class="muted">'.e($address).'</span><br>
            <span class="muted">'.e($phone).' &middot; '.e($email).'</span>
        </td></tr></table>
        <div class="rule"></div>
        <table><tr><td style="font-size:38px;color:#066466;font-family:serif;padding-right:14px"><strong>&#8478;</strong></td><td>
            <h1 style="color:#066466;font-size:19px;margin:0">Receta m&eacute;dica</h1>
            <span class="muted">Folio #'.(int)$rx['id'].' &middot; '.e(date('d/m/Y H:i',strtotime((string)$rx['created_at']))).'</span>
        </td></tr></table>
        <br>'.$demoHtml.'
        <table class="patient" width="100%" style="margin:16px 0;border-top:1px solid #dfe7e7;border-bottom:1px solid #dfe7e7;padding:8px 0"><tr>
            <td><span class="label">Paciente</span><br><strong>'.e($rx['patient_name']).'</strong></td>
            <td><span class="label">C&oacute;digo</span><br><strong>'.e($rx['patient_code']).'</strong></td>
            <td><span class="label">Documento</span><br><strong>'.e($rx['patient_document']).'</strong></td>
            <td><span class="label">Nacimiento</span><br><strong>'.($rx['birth_date'] ? e(date('d/m/Y',strtotime((string)$rx['birth_date']))) : 'No registrada').'</strong></td>
        </tr></table>'
        .($rx['diagnosis_summary'] ? '<p><span class="label">Diagn&oacute;stico / motivo</span><br>'.nl2br(e((string)$rx['diagnosis_summary'])).'</p>' : '')
        .'<div style="background:#f8faf9;border:1px solid #edf1ef;border-radius:8px;padding:15px 16px;margin:14px 0">'.$itemsHtml.'</div>'
        .($rx['instructions'] ? '<p><span class="label">Indicaciones generales</span><br>'.nl2br(e((string)$rx['instructions'])).'</p>' : '')
        .$cancelledHtml.
        '<table width="100%" style="margin-top:55px"><tr>
            <td width="47%" style="border-top:1px solid #202b32;text-align:center;padding-top:6px">
                <strong>'.e($rx['dentist']).'</strong><br><span class="muted">'.e((string)$rx['dentist_specialty']).'</span><br><span class="muted" style="font-size:9px">Firma y sello profesional</span>
            </td>
            <td width="6%"></td>
            <td width="47%" style="border-top:1px solid #202b32;text-align:center;padding-top:6px">
                <span class="muted" style="font-size:9px">Emitida por '.e($rx['author']).'</span>
            </td>
        </tr></table>
        </body></html>';
        $pdf=new \Dompdf\Dompdf(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'chroot'=>ROOT.'/storage/cache','tempDir'=>ROOT.'/storage/cache','fontCache'=>ROOT.'/storage/cache']);
        $pdf->loadHtml($html); $pdf->setPaper('A4','portrait'); $pdf->render();
        $pdf->stream('receta-'.(int)$rx['id'].'.pdf',['Attachment'=>false]); exit;
    }
}
