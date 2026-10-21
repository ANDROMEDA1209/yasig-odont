<?php
declare(strict_types=1);

// Included by run.php after the isolated schema, seeders and the shared check/rejects/asUser helpers.
// Every patient and clinical statement below is an intentionally fictitious fixture.
use App\Models\DB;
use App\Services\Auth;
use App\Services\Clinic;
use App\Services\Orthodontics as Ortho;
use App\Services\OrthodonticAgenda;
use App\Services\OrthodonticAssistant;
use App\Services\OrthodonticFiles;
use App\Services\Treatment;
use App\Services\Uploads;

if (env('DB_DATABASE') !== 'yasig_odont_test') {
    throw new RuntimeException('Las pruebas de ortodoncia requieren la base aislada.');
}

function orthoTestAction(string $action, array $data = []): mixed {
    return Ortho::action(['action' => $action, ...$data]);
}

asUser('admin@yasig.demo');
$orthoDentists = DB::all('SELECT d.id,d.user_id,u.email FROM dentists d JOIN users u ON u.id=d.user_id WHERE d.active=1 ORDER BY d.id LIMIT 2');
$orthoPatient = Clinic::patient(['first_name'=>'Ortodoncia QA','last_name'=>'Paciente ficticio','document'=>'ORTH-QA-A','phone'=>'TEST','status'=>'active']);
$orthoOtherPatient = Clinic::patient(['first_name'=>'Ortodoncia QA','last_name'=>'Otro paciente ficticio','document'=>'ORTH-QA-B','phone'=>'TEST','status'=>'active']);
$orthoDentist = (int)$orthoDentists[0]['id'];
$orthoOtherDentist = (int)$orthoDentists[1]['id'];
$orthoCase = 0;
$orthoCasePayload = ['patient_id'=>$orthoPatient,'dentist_id'=>$orthoDentist,'type_key'=>'metal','appliance'=>'Aparatología de prueba','start_date'=>date('Y-m-d',strtotime('-2 months')),'estimated_months'=>'1','control_interval_days'=>'30','price'=>'125.00'];

check('Ortodoncia: administrador y profesional reciben permisos del módulo', function() use($orthoDentists) {
    $admin=can('orthodontics');
    asUser($orthoDentists[0]['email']);$dentist=can('orthodontics');
    asUser('admin@yasig.demo');
    return $admin && $dentist;
});
check('Ortodoncia: expediente enlaza paciente y profesional institucionales', function() use(&$orthoCase,$orthoCasePayload,$orthoPatient,$orthoDentist) {
    $orthoCase=(int)orthoTestAction('create_case',$orthoCasePayload);
    $row=DB::one('SELECT c.*,r.patient_id FROM orth_cases c JOIN clinical_records r ON r.id=c.clinical_record_id WHERE c.id=?',[$orthoCase]);
    return $orthoCase>0 && (int)$row['patient_id']===$orthoPatient && (int)$row['dentist_id']===$orthoDentist;
});
if (!$orthoCase) {
    asUser('admin@yasig.demo');
    return;
}

check('Ortodoncia: reabrir paciente recupera expediente y no duplica', function() use($orthoCase,$orthoCasePayload) {
    $before=(int)DB::query('SELECT COUNT(*) FROM orth_cases')->fetchColumn();
    return (int)orthoTestAction('create_case',$orthoCasePayload)===$orthoCase
        && (int)DB::query('SELECT COUNT(*) FROM orth_cases')->fetchColumn()===$before;
});
check('Ortodoncia: consultar varias veces no crea registros clínicos', function() use($orthoCase) {
    $before=DB::one('SELECT (SELECT COUNT(*) FROM orth_cases) cases_count,(SELECT COUNT(*) FROM orth_diagnoses) diagnoses_count,(SELECT COUNT(*) FROM orth_controls) controls_count');
    for($i=0;$i<3;$i++) Ortho::detail($orthoCase);
    return $before===DB::one('SELECT (SELECT COUNT(*) FROM orth_cases) cases_count,(SELECT COUNT(*) FROM orth_diagnoses) diagnoses_count,(SELECT COUNT(*) FROM orth_controls) controls_count');
});
check('Ortodoncia: duración estimada vencida no finaliza clínicamente', function() use($orthoCase) {
    Ortho::detail($orthoCase);
    return DB::one('SELECT status FROM orth_cases WHERE id=?',[$orthoCase])['status']!=='completed';
});
check('Ortodoncia: paciente inexistente rechazado', fn()=>rejects(fn()=>orthoTestAction('create_case',[...$orthoCasePayload,'patient_id'=>999999])));
check('Ortodoncia: profesional inexistente rechazado', fn()=>rejects(fn()=>orthoTestAction('create_case',[...$orthoCasePayload,'patient_id'=>$orthoOtherPatient,'dentist_id'=>999999])));
check('Ortodoncia: finalizar sin plan autorizado es rechazado', fn()=>rejects(fn()=>orthoTestAction('status',['case_id'=>$orthoCase,'status'=>'completed','reason'=>'Cierre ficticio','confirm_completion'=>'1','completion_criteria'=>'Criterio ficticio'])));

$orthoDiagnosisPayload=['case_id'=>$orthoCase,'previous_id'=>'0','evaluation_date'=>date('Y-m-d'),'clinical_diagnosis'=>'Diagnóstico ortodóntico ficticio inicial','chief_complaint'=>'Motivo ficticio de evaluación','overjet_mm'=>'3.5','overbite_mm'=>'2.0','observations'=>'No es un diagnóstico de una persona real.'];
orthoTestAction('diagnosis',$orthoDiagnosisPayload);
$orthoDiagnosis=(int)DB::query('SELECT id FROM orth_diagnoses WHERE case_id=? ORDER BY id DESC LIMIT 1',[$orthoCase])->fetchColumn();
$orthoDiagnosisBefore=DB::one('SELECT * FROM orth_diagnoses WHERE id=?',[$orthoDiagnosis]);
check('Ortodoncia: diagnóstico persistido con autor y fecha', fn()=>$orthoDiagnosis>0 && (int)$orthoDiagnosisBefore['user_id']===(int)user()['id']);
check('Ortodoncia: medidas conservan valor numérico y unidad explícita', function() use($orthoDiagnosisBefore) {
    $data=json_decode($orthoDiagnosisBefore['data_json'],true,512,JSON_THROW_ON_ERROR);
    return (float)$data['overjet_mm']===3.5 && (float)$data['overbite_mm']===2.0;
});
check('Ortodoncia: medida clínica mal formada rechazada', fn()=>rejects(fn()=>orthoTestAction('diagnosis',[...$orthoDiagnosisPayload,'previous_id'=>$orthoDiagnosis,'overjet_mm'=>'3.5<script>'])));
orthoTestAction('diagnosis',[...$orthoDiagnosisPayload,'previous_id'=>$orthoDiagnosis,'clinical_diagnosis'=>'Diagnóstico ficticio actualizado']);
$orthoLatestDiagnosis=(int)DB::query('SELECT id FROM orth_diagnoses WHERE case_id=? ORDER BY id DESC LIMIT 1',[$orthoCase])->fetchColumn();
check('Ortodoncia: nueva evaluación conserva íntegro el diagnóstico anterior', fn()=>$orthoLatestDiagnosis!==$orthoDiagnosis && DB::one('SELECT * FROM orth_diagnoses WHERE id=?',[$orthoDiagnosis])===$orthoDiagnosisBefore);
check('Ortodoncia: evaluación obsoleta no sobrescribe una versión posterior', fn()=>rejects(fn()=>orthoTestAction('diagnosis',[...$orthoDiagnosisPayload,'previous_id'=>$orthoDiagnosis])));
check('Ortodoncia: evaluación vacía rechazada sin versión parcial', function() use($orthoCase,$orthoDiagnosisPayload,$orthoLatestDiagnosis) {
    $before=(int)DB::query('SELECT COUNT(*) FROM orth_diagnoses WHERE case_id=?',[$orthoCase])->fetchColumn();
    return rejects(fn()=>orthoTestAction('diagnosis',['case_id'=>$orthoCase,'previous_id'=>$orthoLatestDiagnosis,'evaluation_date'=>date('Y-m-d')]))
        && (int)DB::query('SELECT COUNT(*) FROM orth_diagnoses WHERE case_id=?',[$orthoCase])->fetchColumn()===$before;
});

$orthoFinanceBefore=DB::one('SELECT (SELECT COUNT(*) FROM payments) payment_count,(SELECT COALESCE(SUM(amount),0) FROM payments) payment_total,(SELECT COUNT(*) FROM payment_receipts) receipt_count');
$orthoPlanPayload=['case_id'=>$orthoCase,'objectives'=>'Objetivo ficticio de alineación','phases'=>"Evaluación\nNivelación\nTerminación",'procedures'=>'Procedimientos ficticios para comprobar persistencia','completion_criteria'=>'Evaluación y confirmación clínica explícita de los objetivos','retention_plan'=>'Retención ficticia, pendiente de evaluación profesional','estimated_months'=>'12','control_interval_days'=>'30','status'=>'draft'];
orthoTestAction('plan',$orthoPlanPayload);
$orthoPlan=(int)DB::query('SELECT id FROM orth_plan_versions WHERE case_id=? ORDER BY id DESC LIMIT 1',[$orthoCase])->fetchColumn();
$orthoPlanBefore=DB::one('SELECT * FROM orth_plan_versions WHERE id=?',[$orthoPlan]);
check('Ortodoncia: plan permanece borrador hasta autorización explícita', fn()=>$orthoPlanBefore['status']==='draft');
check('Ortodoncia: autorización sin confirmación rechazada', fn()=>rejects(fn()=>orthoTestAction('authorize_plan',['case_id'=>$orthoCase,'plan_id'=>$orthoPlan])));
orthoTestAction('authorize_plan',['case_id'=>$orthoCase,'plan_id'=>$orthoPlan,'confirm_authorization'=>'1']);
check('Ortodoncia: autorización de plan queda persistida', fn()=>DB::one('SELECT status FROM orth_plan_versions WHERE id=?',[$orthoPlan])['status']==='authorized');
$orthoAuthorizedPlanBefore=DB::one('SELECT * FROM orth_plan_versions WHERE id=?',[$orthoPlan]);
orthoTestAction('plan',[...$orthoPlanPayload,'previous_id'=>$orthoPlan,'objectives'=>'Objetivo actualizado ficticio']);
$orthoNewPlan=(int)DB::query('SELECT id FROM orth_plan_versions WHERE case_id=? ORDER BY id DESC LIMIT 1',[$orthoCase])->fetchColumn();
check('Ortodoncia: revisión genera versión sin alterar plan autorizado', fn()=>$orthoNewPlan!==$orthoPlan && DB::one('SELECT * FROM orth_plan_versions WHERE id=?',[$orthoPlan])===$orthoAuthorizedPlanBefore);
check('Ortodoncia: autorización de versión anterior rechazada', fn()=>rejects(fn()=>orthoTestAction('authorize_plan',['case_id'=>$orthoCase,'plan_id'=>$orthoPlan,'confirm_authorization'=>'1'])));
orthoTestAction('authorize_plan',['case_id'=>$orthoCase,'plan_id'=>$orthoNewPlan,'confirm_authorization'=>'1']);
check('Ortodoncia: edición de plan obsoleta rechazada sin sobrescritura', fn()=>rejects(fn()=>orthoTestAction('plan',[...$orthoPlanPayload,'previous_id'=>$orthoPlan,'objectives'=>'Cambio desde pestaña obsoleta ficticia'])));

orthoTestAction('status',['case_id'=>$orthoCase,'status'=>'active','reason'=>'Inicio de seguimiento ficticio']);
$orthoControlPayload=['case_id'=>$orthoCase,'date'=>date('Y-m-d',strtotime('-1 day')),'time'=>'09:00','phase'=>'Nivelación','observations'=>'Hallazgos ficticios del primer control','procedures'=>'Procedimiento ficticio documentado','appliance_changes'=>'Cambio ficticio','incidents'=>'Sin incidencias ficticias','recommendations'=>'Indicaciones ficticias de prueba','next_control'=>date('Y-m-d',strtotime('+30 days')),'confirm_sign'=>'1','request_key'=>'orthodontics-test-control'];
check('Ortodoncia: control sin firma explícita rechazado', fn()=>rejects(fn()=>orthoTestAction('control',[...$orthoControlPayload,'confirm_sign'=>''])));
orthoTestAction('control',$orthoControlPayload);
$orthoControl=(int)DB::query('SELECT id FROM orth_controls WHERE case_id=? ORDER BY id DESC LIMIT 1',[$orthoCase])->fetchColumn();
$orthoControlBefore=DB::one('SELECT * FROM orth_controls WHERE id=?',[$orthoControl]);
check('Ortodoncia: control persiste como visita de la historia existente', fn()=>$orthoControl>0 && !empty($orthoControlBefore['session_id']) && (bool)DB::one('SELECT id FROM treatment_sessions WHERE id=?',[$orthoControlBefore['session_id']]));
check('Ortodoncia: reenviar control idéntico no duplica visita ni evolución', function() use($orthoCase,$orthoControlPayload) {
    $before=(int)DB::query('SELECT COUNT(*) FROM orth_controls WHERE case_id=?',[$orthoCase])->fetchColumn();
    orthoTestAction('control',$orthoControlPayload);
    return (int)DB::query('SELECT COUNT(*) FROM orth_controls WHERE case_id=?',[$orthoCase])->fetchColumn()===$before;
});
check('Ortodoncia: reutilizar envío con hallazgos diferentes rechazado', fn()=>rejects(fn()=>orthoTestAction('control',[...$orthoControlPayload,'observations'=>'Otro hallazgo ficticio con el mismo identificador'])));
check('Ortodoncia: visita futura rechazada', fn()=>rejects(fn()=>orthoTestAction('control',[...$orthoControlPayload,'date'=>date('Y-m-d',strtotime('+7 days'))])));
orthoTestAction('addendum',['case_id'=>$orthoCase,'control_id'=>$orthoControl,'notes'=>'Aclaración ficticia añadida posteriormente','reason'=>'Corrección documental de prueba']);
check('Ortodoncia: aclaración conserva la visita firmada intacta', fn()=>DB::one('SELECT * FROM orth_controls WHERE id=?',[$orthoControl])===$orthoControlBefore && (int)DB::query('SELECT COUNT(*) FROM orth_control_addenda WHERE control_id=?',[$orthoControl])->fetchColumn()===1);

$orthoOtherCase=(int)orthoTestAction('create_case',[...$orthoCasePayload,'patient_id'=>$orthoOtherPatient,'dentist_id'=>$orthoOtherDentist]);
check('Ortodoncia: aclaración no acepta control de otro expediente', fn()=>rejects(fn()=>orthoTestAction('addendum',['case_id'=>$orthoOtherCase,'control_id'=>$orthoControl,'notes'=>'Cruce de ID de prueba','reason'=>'Prueba de integridad'])));
check('Ortodoncia: autorización no acepta plan de otro expediente', fn()=>rejects(fn()=>orthoTestAction('authorize_plan',['case_id'=>$orthoOtherCase,'plan_id'=>$orthoNewPlan,'confirm_authorization'=>'1'])));
$orthoOtherAppointment=DB::insert('appointments',['patient_id'=>$orthoOtherPatient,'dentist_id'=>$orthoOtherDentist,'service_id'=>DB::query('SELECT id FROM services ORDER BY id LIMIT 1')->fetchColumn(),'starts_at'=>gmdate('Y-m-d H:i:s',strtotime('-1 day')),'ends_at'=>gmdate('Y-m-d H:i:s',strtotime('-1 day +30 minutes')),'status'=>'attended','reason'=>'Cita ficticia para comprobar integridad']);
check('Ortodoncia: visita no acepta cita perteneciente a otro paciente', fn()=>rejects(fn()=>orthoTestAction('control',[...$orthoControlPayload,'appointment_id'=>$orthoOtherAppointment])));
check('Ortodoncia: agenda no vincula cita de otro paciente', fn()=>rejects(fn()=>OrthodonticAgenda::link(['case_id'=>$orthoCase,'appointment_id'=>$orthoOtherAppointment])));
$orthoAppointmentDate=(new DateTimeImmutable('next monday'))->modify('+350 days')->modify('monday this week')->format('Y-m-d');
$orthoSchedule=['case_id'=>$orthoCase,'service_id'=>DB::query('SELECT id FROM services ORDER BY id LIMIT 1')->fetchColumn(),'date'=>$orthoAppointmentDate,'time'=>'08:00','status'=>'confirmed','reason'=>'Control ortodóntico ficticio'];
OrthodonticAgenda::schedule($orthoSchedule);
$orthoAppointment=(int)DB::query('SELECT a.id FROM appointments a JOIN orth_case_appointments l ON l.appointment_id=a.id WHERE l.case_id=? ORDER BY a.id DESC LIMIT 1',[$orthoCase])->fetchColumn();
check('Ortodoncia: programación usa la agenda institucional y conserva profesional', function() use($orthoAppointment,$orthoPatient,$orthoDentist,$orthoAppointmentDate,$orthoCase) {
    $row=DB::one('SELECT patient_id,dentist_id,starts_at FROM appointments WHERE id=?',[$orthoAppointment]);
    return (int)$row['patient_id']===$orthoPatient && (int)$row['dentist_id']===$orthoDentist
        && local_date($row['starts_at'],'Y-m-d')===$orthoAppointmentDate
        && DB::one('SELECT next_control FROM orth_cases WHERE id=?',[$orthoCase])['next_control']===$orthoAppointmentDate;
});
check('Ortodoncia: programación respeta conflictos reales del calendario', fn()=>rejects(fn()=>OrthodonticAgenda::schedule($orthoSchedule)));
check('Ortodoncia: asociar dos veces la misma cita no duplica el vínculo', function() use($orthoCase,$orthoAppointment) {
    OrthodonticAgenda::link(['case_id'=>$orthoCase,'appointment_id'=>$orthoAppointment]);
    return (int)DB::query('SELECT COUNT(*) FROM orth_case_appointments WHERE case_id=? AND appointment_id=?',[$orthoCase,$orthoAppointment])->fetchColumn()===1;
});
check('Ortodoncia: reprogramar otra cita por ID rechazado', fn()=>rejects(fn()=>OrthodonticAgenda::schedule([...$orthoSchedule,'appointment_id'=>$orthoOtherAppointment,'time'=>'11:00'])));
check('Ortodoncia: firma y autorización clínicas no alteran cobros ni recibos', fn()=>$orthoFinanceBefore===DB::one('SELECT (SELECT COUNT(*) FROM payments) payment_count,(SELECT COALESCE(SUM(amount),0) FROM payments) payment_total,(SELECT COUNT(*) FROM payment_receipts) receipt_count'));

$orthoItem=(int)DB::query('SELECT treatment_plan_item_id FROM orth_cases WHERE id=?',[$orthoCase])->fetchColumn();
$orthoOtherItem=(int)DB::query('SELECT treatment_plan_item_id FROM orth_cases WHERE id=?',[$orthoOtherCase])->fetchColumn();
$orthoStage=(int)DB::query('SELECT id FROM treatment_stages WHERE treatment_plan_item_id=? ORDER BY id LIMIT 1',[$orthoItem])->fetchColumn();
$orthoSeparatePlan=Clinic::plan(['patient_id'=>$orthoPatient,'dentist_id'=>$orthoDentist,'name'=>'Tratamiento general ficticio para integridad','treatment_id'=>DB::query('SELECT id FROM treatments WHERE code<>"ORT" ORDER BY id LIMIT 1')->fetchColumn()]);
$orthoSeparateItem=(int)DB::query('SELECT id FROM treatment_plan_items WHERE plan_id=? ORDER BY id LIMIT 1',[$orthoSeparatePlan])->fetchColumn();
$orthoSeparateStage=DB::insert('treatment_stages',['treatment_plan_item_id'=>$orthoSeparateItem,'stage_key'=>'qa_context','stage_name'=>'Fase ficticia para integridad','sort_order'=>0]);
check('Ortodoncia: contexto sin tratamiento explícito no mezcla visita y fase diferentes', fn()=>rejects(fn()=>Uploads::validateContext($orthoPatient,['session_id'=>$orthoControlBefore['session_id'],'stage_id'=>$orthoSeparateStage])));
check('Ortodoncia: contexto de adjunto propio es válido', function() use($orthoPatient,$orthoItem,$orthoControlBefore) {
    Uploads::validateContext($orthoPatient,['treatment_plan_item_id'=>$orthoItem,'session_id'=>$orthoControlBefore['session_id']]);
    return true;
});
check('Ortodoncia: archivo no puede asociarse a tratamiento de otro paciente', fn()=>rejects(fn()=>Uploads::validateContext($orthoPatient,['treatment_plan_item_id'=>$orthoOtherItem])));
check('Ortodoncia: archivo no puede asociarse a una visita de otro paciente', fn()=>rejects(fn()=>Uploads::validateContext($orthoOtherPatient,['session_id'=>$orthoControlBefore['session_id']])));
check('Ortodoncia: archivo no puede asociarse a una pieza FDI inexistente', fn()=>rejects(fn()=>Uploads::validateContext($orthoPatient,['tooth_fdi'=>99])));
check('Ortodoncia: carga fotográfica no acepta control de otro expediente', fn()=>rejects(fn()=>OrthodonticFiles::upload(['case_id'=>$orthoOtherCase,'category'=>'photo','photo_category'=>'frontal_rest','control_id'=>$orthoControl],[])));
check('Ortodoncia: fecha futura de captura rechazada', fn()=>rejects(fn()=>OrthodonticFiles::upload(['case_id'=>$orthoCase,'category'=>'photo','photo_category'=>'frontal_rest','captured_at'=>date('Y-m-d',strtotime('+1 day'))],[])));
$orthoAttachment=DB::insert('attachments',['clinical_record_id'=>DB::query('SELECT clinical_record_id FROM orth_cases WHERE id=?',[$orthoCase])->fetchColumn(),'user_id'=>user()['id'],'original_name'=>'orthodontics-qa-ficticio.jpg','stored_name'=>'orthodontics-qa-metadata-only.bin','mime'=>'image/jpeg','size'=>100,'treatment_plan_item_id'=>$orthoItem,'category'=>'photo']);
DB::insert('orth_attachments',['case_id'=>$orthoCase,'attachment_id'=>$orthoAttachment,'control_id'=>$orthoControl,'photo_category'=>'frontal_rest','description'=>'Solo metadatos ficticios para probar autorización; no existe archivo binario.']);
check('Ortodoncia: administrador puede autorizar adjunto de expediente', function() use($orthoAttachment) {
    Ortho::authorizeAttachment($orthoAttachment);
    return true;
});
orthoTestAction('consent',['case_id'=>$orthoCase,'kind'=>'photographs','status'=>'granted','signed_on'=>date('Y-m-d'),'notes'=>'Consentimiento ficticio para prueba']);
$orthoConsent=DB::one('SELECT * FROM orth_consents WHERE case_id=? ORDER BY id DESC LIMIT 1',[$orthoCase]);
orthoTestAction('consent',['case_id'=>$orthoCase,'kind'=>'photographs','status'=>'revoked','signed_on'=>date('Y-m-d'),'notes'=>'Revocación ficticia para prueba']);
check('Ortodoncia: revocación de consentimiento conserva registro original', fn()=>DB::one('SELECT * FROM orth_consents WHERE id=?',[$orthoConsent['id']])===$orthoConsent && DB::one('SELECT status FROM orth_consents WHERE case_id=? AND kind=? ORDER BY id DESC LIMIT 1',[$orthoCase,'photographs'])['status']==='revoked');
check('Ortodoncia: consentimiento no admite documento de otro expediente', fn()=>rejects(fn()=>orthoTestAction('consent',['case_id'=>$orthoOtherCase,'kind'=>'treatment','status'=>'granted','attachment_id'=>$orthoAttachment])));

check('Ortodoncia: IA externa se identifica como pendiente', fn()=>OrthodonticAssistant::state($orthoCase)['status']['available']===false);
$orthoSignedBefore=DB::one('SELECT (SELECT COUNT(*) FROM orth_diagnoses) diagnoses_count,(SELECT COUNT(*) FROM orth_controls) controls_count');
OrthodonticAssistant::action(['case_id'=>$orthoCase,'assistant_action'=>'generate','kind'=>'summary']);
$orthoProposal=DB::one('SELECT * FROM orth_assistant_proposals WHERE case_id=? ORDER BY id DESC LIMIT 1',[$orthoCase]);
check('Ortodoncia: resumen local queda como borrador de registros reales', fn()=>$orthoProposal['status']==='draft' && str_contains($orthoProposal['model'],'sin IA externa') && str_contains($orthoProposal['content'],'Diagnóstico ficticio actualizado'));
check('Ortodoncia: resumen local no firma controles ni diagnósticos', fn()=>$orthoSignedBefore===DB::one('SELECT (SELECT COUNT(*) FROM orth_diagnoses) diagnoses_count,(SELECT COUNT(*) FROM orth_controls) controls_count'));
check('Ortodoncia: aceptar borrador exige revisión confirmada', fn()=>rejects(fn()=>OrthodonticAssistant::action(['case_id'=>$orthoCase,'assistant_action'=>'accept','proposal_id'=>$orthoProposal['id'],'content'=>'Documento ficticio revisado'])));
check('Ortodoncia: documento no puede revisarse desde otro expediente', fn()=>rejects(fn()=>OrthodonticAssistant::action(['case_id'=>$orthoOtherCase,'assistant_action'=>'accept','proposal_id'=>$orthoProposal['id'],'content'=>'Documento ficticio','confirm_review'=>'1'])));
OrthodonticAssistant::action(['case_id'=>$orthoCase,'assistant_action'=>'accept','proposal_id'=>$orthoProposal['id'],'content'=>'Documento ficticio corregido y validado profesionalmente','confirm_review'=>'1']);
check('Ortodoncia: aceptar edición preserva borrador original y revisor', function() use($orthoProposal) {
    $row=DB::one('SELECT * FROM orth_assistant_proposals WHERE id=?',[$orthoProposal['id']]);
    return $row['status']==='accepted' && $row['content']==='Documento ficticio corregido y validado profesionalmente'
        && $row['original_content']===$orthoProposal['content'] && (int)$row['reviewed_by']===(int)user()['id'];
});
check('Ortodoncia: documento ya validado no puede cambiarse otra vez', fn()=>rejects(fn()=>OrthodonticAssistant::action(['case_id'=>$orthoCase,'assistant_action'=>'accept','proposal_id'=>$orthoProposal['id'],'content'=>'Segundo cambio ficticio','confirm_review'=>'1'])));

asUser($orthoDentists[0]['email']);
check('Ortodoncia: profesional responsable abre su expediente', fn()=>is_array(Ortho::assertCase($orthoCase)));
check('Ortodoncia: vía clínica genérica no puede alterar fases del caso propio', fn()=>rejects(fn()=>Treatment::stage(['id'=>$orthoStage,'status'=>'completed'])));
check('Ortodoncia: vía clínica genérica no crea sesiones fuera del expediente propio', fn()=>rejects(fn()=>Treatment::session(['treatment_plan_item_id'=>$orthoItem,'dentist_id'=>$orthoDentist,'date'=>date('Y-m-d',strtotime('-1 day')),'time'=>'08:00'])));
check('Ortodoncia: vía clínica genérica no cambia estado clínico del caso propio', fn()=>rejects(fn()=>Treatment::clinicalStatus(['id'=>$orthoItem,'clinical_status'=>'completed'])));
check('Ortodoncia: listado y gráficas del profesional respetan sus expedientes', function() use($orthoCase) {
    $listing=Ortho::listing(['q'=>'ORTH-QA']);
    return $listing['total']===1 && (int)$listing['cases'][0]['id']===$orthoCase
        && array_sum(array_column($listing['charts']['states'],'value'))===1;
});
check('Ortodoncia: búsqueda no revela paciente de expediente ajeno', fn()=>Ortho::listing(['q'=>'ORTH-QA-B'])['total']===0);
check('Ortodoncia: profesional responsable puede autorizar su adjunto privado', function() use($orthoAttachment) {
    Ortho::authorizeAttachment($orthoAttachment);
    return true;
});
check('Ortodoncia: profesional ajeno no puede consultar por cambio de ID', fn()=>rejects(fn()=>Ortho::detail($orthoOtherCase)));
check('Ortodoncia: profesional ajeno no puede escribir por cambio de ID', fn()=>rejects(fn()=>orthoTestAction('control',[...$orthoControlPayload,'case_id'=>$orthoOtherCase])));
asUser($orthoDentists[1]['email']);
check('Ortodoncia: cambiar ID del archivo no permite descargar caso ajeno', fn()=>rejects(fn()=>Ortho::authorizeAttachment($orthoAttachment)));
check('Ortodoncia: vía clínica genérica no consulta sesiones de caso ajeno', fn()=>rejects(fn()=>Treatment::detail($orthoItem)));
check('Ortodoncia: vía clínica genérica no modifica fase de caso ajeno', fn()=>rejects(fn()=>Treatment::stage(['id'=>$orthoStage,'status'=>'completed'])));
check('Ortodoncia: contexto de upload genérico no evade autorización del caso', fn()=>rejects(fn()=>Uploads::validateContext($orthoPatient,['session_id'=>$orthoControlBefore['session_id']])));
asUser('recepcion@yasig.demo');
check('Ortodoncia: recepción no obtiene datos clínicos del expediente', fn()=>rejects(fn()=>Ortho::detail($orthoCase)));
asUser('caja@yasig.demo');
check('Ortodoncia: caja no obtiene datos clínicos del expediente', fn()=>rejects(fn()=>Ortho::detail($orthoCase)));
asUser('asistente@yasig.demo');
check('Ortodoncia: asistente sin asignación no obtiene expediente', fn()=>rejects(fn()=>Ortho::detail($orthoCase)));
asUser('admin@yasig.demo');
$orthoAssistantId=(int)DB::query('SELECT id FROM users WHERE email=?',['asistente@yasig.demo'])->fetchColumn();
orthoTestAction('member',['case_id'=>$orthoCase,'user_id'=>$orthoAssistantId,'active'=>'1']);
asUser('asistente@yasig.demo');
check('Ortodoncia: asignación permite al asistente leer con alcance limitado', function() use($orthoCase) {
    $detail=Ortho::detail($orthoCase);
    return $detail['can_assist']===true && $detail['can_write']===false;
});
check('Ortodoncia: asistente asignado puede consultar archivos del caso', function() use($orthoAttachment) {
    Ortho::authorizeAttachment($orthoAttachment);
    return true;
});
check('Ortodoncia: asistente asignado no puede firmar controles', fn()=>rejects(fn()=>orthoTestAction('control',$orthoControlPayload)));
check('Ortodoncia: asistente asignado no puede autorizar un plan', fn()=>rejects(fn()=>orthoTestAction('authorize_plan',['case_id'=>$orthoCase,'plan_id'=>$orthoNewPlan,'confirm_authorization'=>'1'])));
check('Ortodoncia: asistente asignado puede registrar tarea administrativa', function() use($orthoCase) {
    orthoTestAction('task',['case_id'=>$orthoCase,'title'=>'Tarea ficticia de asistente asignado','priority'=>'normal']);
    return (bool)DB::one('SELECT id FROM orth_tasks WHERE case_id=? AND title=? AND user_id=?',[$orthoCase,'Tarea ficticia de asistente asignado',user()['id']]);
});
asUser('admin@yasig.demo');
orthoTestAction('member',['case_id'=>$orthoCase,'user_id'=>$orthoAssistantId,'active'=>'0']);
asUser('asistente@yasig.demo');
check('Ortodoncia: revocar asignación bloquea el acceso inmediatamente', fn()=>rejects(fn()=>Ortho::detail($orthoCase)) && rejects(fn()=>Ortho::authorizeAttachment($orthoAttachment)));
Auth::logout();
check('Ortodoncia: sesión ausente no obtiene expediente', fn()=>rejects(fn()=>Ortho::detail($orthoCase)));
check('Ortodoncia: sesión ausente no autoriza archivos privados', fn()=>rejects(fn()=>Ortho::authorizeAttachment($orthoAttachment)));
asUser('admin@yasig.demo');
check('Ortodoncia: indicadores y gráficas cuentan expedientes reales filtrados', function() {
    $listing=Ortho::listing(['q'=>'ORTH-QA']);
    return $listing['total']===2 && count($listing['cases'])===2
        && array_sum(array_column($listing['charts']['states'],'value'))===2
        && array_sum(array_column($listing['charts']['types'],'value'))===2
        && $listing['stats']['active']===1;
});
check('Ortodoncia: finalizar exige confirmación clínica explícita', fn()=>rejects(fn()=>orthoTestAction('status',['case_id'=>$orthoCase,'status'=>'completed','reason'=>'Cierre ficticio','completion_criteria'=>'Criterios documentados ficticios'])));
check('Ortodoncia: finalizar exige criterios documentados', fn()=>rejects(fn()=>orthoTestAction('status',['case_id'=>$orthoCase,'status'=>'completed','reason'=>'Cierre ficticio','confirm_completion'=>'1','completion_criteria'=>''])));
orthoTestAction('status',['case_id'=>$orthoCase,'status'=>'completed','reason'=>'Finalización ficticia confirmada por profesional','confirm_completion'=>'1','completion_criteria'=>'Objetivos ficticios revisados y confirmados']);
check('Ortodoncia: finalización explícita se conserva al reabrir', function() use($orthoCase) {
    Ortho::detail($orthoCase);
    return DB::one('SELECT status FROM orth_cases WHERE id=?',[$orthoCase])['status']==='completed';
});
check('Ortodoncia: reapertura de caso cerrado no crea otro episodio automáticamente', function() use($orthoCasePayload,$orthoCase) {
    $before=(int)DB::query('SELECT COUNT(*) FROM orth_cases')->fetchColumn();
    return (int)orthoTestAction('create_case',$orthoCasePayload)===$orthoCase
        && (int)DB::query('SELECT COUNT(*) FROM orth_cases')->fetchColumn()===$before;
});
check('Ortodoncia: retención requiere tipo de retenedor e indicaciones', fn()=>rejects(fn()=>orthoTestAction('status',['case_id'=>$orthoCase,'status'=>'retention','reason'=>'Retención ficticia','confirm_completion'=>'1','completion_criteria'=>'Objetivos ficticios revisados'])));
orthoTestAction('status',['case_id'=>$orthoCase,'status'=>'retention','reason'=>'Seguimiento ficticio de retención','confirm_completion'=>'1','completion_criteria'=>'Objetivos ficticios revisados','retainer_type'=>'Retenedor ficticio de prueba','retention_instructions'=>'Indicaciones ficticias de prueba']);
check('Ortodoncia: retención persiste tipo e indicaciones sin perder controles', function() use($orthoCase,$orthoControl) {
    $case=DB::one('SELECT status,retainer_type,retention_instructions FROM orth_cases WHERE id=?',[$orthoCase]);
    return $case['status']==='retention' && $case['retainer_type']==='Retenedor ficticio de prueba'
        && $case['retention_instructions']==='Indicaciones ficticias de prueba'
        && (bool)DB::one('SELECT id FROM orth_controls WHERE id=? AND case_id=?',[$orthoControl,$orthoCase]);
});
orthoTestAction('status',['case_id'=>$orthoCase,'status'=>'completed','reason'=>'Cierre ficticio de seguimiento','confirm_completion'=>'1','completion_criteria'=>'Objetivos ficticios revisados']);
check('Ortodoncia: nuevo episodio requiere acción explícita y conserva el anterior', function() use($orthoCasePayload,$orthoCase,$orthoControl) {
    $new=(int)orthoTestAction('create_case',[...$orthoCasePayload,'confirm_new_episode'=>'1','name'=>'Segundo episodio ficticio']);
    return $new!==$orthoCase && (bool)DB::one('SELECT id FROM orth_cases WHERE id=? AND closed_at IS NOT NULL',[$orthoCase])
        && (bool)DB::one('SELECT id FROM orth_controls WHERE id=? AND case_id=?',[$orthoControl,$orthoCase]);
});
check('Ortodoncia: acciones clínicas generan historial atribuible', fn()=>(int)DB::query('SELECT COUNT(*) FROM orth_history WHERE case_id=? AND user_id IS NOT NULL',[$orthoCase])->fetchColumn()>=5);
check('Ortodoncia: cierre sincroniza estado clínico institucional conservando estado financiero',function() use($orthoItem) {
    $item=DB::one('SELECT clinical_status,status FROM treatment_plan_items WHERE id=?',[$orthoItem]);
    return $item['clinical_status']==='completed' && $item['status']==='planned';
});
asUser('admin@yasig.demo');

check('Ortodoncia: modificar criterios del mismo estado conserva cierre e indicaciones anteriores',function() use($orthoCase) {
    $before=DB::one('SELECT * FROM orth_cases WHERE id=?',[$orthoCase]);
    orthoTestAction('status',['case_id'=>$orthoCase,'status'=>'completed','reason'=>'Revisión ficticia de criterios','confirm_completion'=>'1','completion_criteria'=>'Criterios actualizados ficticios QA']);
    $after=DB::one('SELECT * FROM orth_cases WHERE id=?',[$orthoCase]);
    $history=DB::one('SELECT reason FROM orth_history WHERE case_id=? AND event_type="status_details_changed" ORDER BY id DESC LIMIT 1',[$orthoCase]);
    $snapshot=json_decode($history['reason'],true);
    return $after['completion_criteria']==='Criterios actualizados ficticios QA' && $after['closed_at']===$before['closed_at']
        && $after['retention_instructions']===$before['retention_instructions']
        && $snapshot['previous']['completion_criteria']===$before['completion_criteria'];
});
check('Ortodoncia: fases personales conservan notas previas completas en historial',function() use($orthoCase,$orthoItem) {
    $original=str_repeat('Nota ficticia previa. ',120).'FIN-ORIGINAL-QA';
    orthoTestAction('create_stage',['case_id'=>$orthoCase,'stage_name'=>'Fase individual ficticia QA','notes'=>$original]);
    $stage=DB::query('SELECT id FROM treatment_stages WHERE treatment_plan_item_id=? ORDER BY id DESC LIMIT 1',[$orthoItem])->fetchColumn();
    orthoTestAction('phase',['case_id'=>$orthoCase,'stage_id'=>$stage,'status'=>'in_progress','notes'=>'Nota posterior ficticia QA']);
    $history=DB::one('SELECT reason FROM orth_history WHERE case_id=? AND event_type="phase_changed" AND entity_id=? ORDER BY id DESC LIMIT 1',[$orthoCase,$stage]);
    return json_decode($history['reason'],true)['previous_notes']===$original;
});
check('Ortodoncia: asistente asignado puede reservar únicamente el caso autorizado',function() use($orthoCase,$orthoOtherCase,$orthoSchedule) {
    $assistant=(int)DB::query('SELECT id FROM users WHERE email="asistente@yasig.demo"')->fetchColumn();
    orthoTestAction('member',['case_id'=>$orthoCase,'user_id'=>$assistant,'active'=>'1']);
    asUser('asistente@yasig.demo');
    try {
        $schedule=[...$orthoSchedule,'date'=>(new DateTimeImmutable('next monday'))->modify('+700 days')->format('Y-m-d'),'time'=>'09:00'];
        $saved=OrthodonticAgenda::schedule($schedule)===$orthoCase;
        return $saved && rejects(fn()=>OrthodonticAgenda::schedule([...$schedule,'case_id'=>$orthoOtherCase,'time'=>'10:00']));
    } finally { asUser('admin@yasig.demo');orthoTestAction('member',['case_id'=>$orthoCase,'user_id'=>$assistant,'active'=>'0']); }
});
