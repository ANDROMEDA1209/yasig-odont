<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\DB;
use App\Validators\Input as V;

/** Useful local record retrieval, explicitly distinguished from the pending external AI adapter. */
final class OrthodonticAssistant {
    public static function state(int $caseId): array {
        Orthodontics::assertCase($caseId);
        return ['status'=>['available'=>false,'message'=>'IA externa pendiente de configuración. El resumen de registros funciona localmente, sin IA ni envío de datos a terceros.'],
            'proposals'=>DB::all('SELECT p.*,u.name author,r.name reviewer FROM orth_assistant_proposals p JOIN users u ON u.id=p.user_id LEFT JOIN users r ON r.id=p.reviewed_by WHERE p.case_id=? ORDER BY p.id DESC',[$caseId])];
    }
    public static function action(array $d): int {
        $id=V::id($d,'case_id'); Orthodontics::assertCase($id,true);
        $action=$d['assistant_action'] ?? (($d['action'] ?? '')==='assistant_review' ? ($d['decision'] ?? ''):'generate');
        if ($action==='generate') {
            $kind=V::choice(['kind'=>$d['kind'] ?? 'summary'],'kind',['summary','evolution','document','question']);
            $question=V::text(['question'=>$d['question'] ?? $d['prompt'] ?? ''],'question',1000,false);
            $detail=Orthodontics::detail($id);
            $content=self::localDraft($detail,$kind,$question);
            DB::transaction(function() use($id,$kind,$question,$content) {
                $proposal=DB::insert('orth_assistant_proposals',['case_id'=>$id,'user_id'=>user()['id'],'kind'=>$kind,'prompt'=>$question,'content'=>$content,'original_content'=>$content,'model'=>'local-records / sin IA externa']);
                Orthodontics::logEvent($id,'document_draft',$proposal,'Resumen local de datos registrados.');
            });
        } elseif (in_array($action,['accept','accepted','discard','discarded'],true)) {
            $accept=in_array($action,['accept','accepted'],true);
            DB::transaction(function() use($d,$id,$accept) {
                $proposal=DB::one('SELECT * FROM orth_assistant_proposals WHERE id=? AND case_id=? FOR UPDATE',[V::id($d,'proposal_id'),$id]);
                if (!$proposal || $proposal['status']!=='draft') throw new \DomainException('El borrador ya fue revisado o no pertenece al expediente.');
                if ($accept && empty($d['confirm_review'])) throw new \DomainException('Confirma que revisaste el documento antes de validarlo.');
                $content=$accept ? V::text($d,'content',40000):$proposal['content'];
                DB::query('UPDATE orth_assistant_proposals SET content=?,status=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=?',[$content,$accept ? 'accepted':'discarded',user()['id'],$proposal['id']]);
                Orthodontics::logEvent($id,$accept ? 'document_accepted':'document_discarded',(int)$proposal['id'],'Original conservado; revisión profesional.');
            });
        } else throw new \DomainException('Acción de documentación no disponible.');
        return $id;
    }
    public static function localDraft(array $detail,string $kind,string $question=''): string {
        $case=$detail['case']; $diagnosis=$detail['diagnosis']['latest']['data'] ?? $detail['diagnosis']['data'] ?? [];
        $plan=$detail['plan']['latest']['data'] ?? $detail['plan']['data'] ?? [];
        $last=$detail['controls'][0] ?? null;
        $options=$detail['options']['states'] ?? [];
        $lines=['BORRADOR PARA REVISIÓN PROFESIONAL','Origen: extracción local de registros. No utiliza IA externa.','Fecha de preparación: '.date('d/m/Y H:i'),'Expediente: '.($case['code'] ?? $case['patient_code'] ?? $case['id']), 'Estado registrado: '.($options[$case['status']] ?? $case['status'])];
        if ($kind==='question' && $question!=='') {
            $lines[]='Consulta: '.$question;
            $lines[]='Se muestran los hechos disponibles; el sistema local no interpreta preguntas clínicas ni formula inferencias.';
        }
        if ($kind!=='evolution') {
            foreach (['chief_complaint'=>'Motivo de consulta','medical_history'=>'Antecedentes médicos','dental_history'=>'Antecedentes odontológicos','clinical_diagnosis'=>'Diagnóstico registrado','objectives'=>'Objetivos registrados','prognosis'=>'Pronóstico registrado'] as $key=>$label) {
                if (!empty($diagnosis[$key])) $lines[]=$label.': '.$diagnosis[$key];
            }
            if (!$diagnosis) $lines[]='Diagnóstico: no registrado.';
            foreach (['objectives'=>'Objetivos del plan','procedures'=>'Procedimientos planificados','completion_criteria'=>'Criterios de finalización','retention_plan'=>'Plan de retención'] as $key=>$label) if (!empty($plan[$key])) $lines[]=$label.': '.$plan[$key];
            if (!$plan) $lines[]='Plan: no registrado.';
        }
        if ($last) {
            $lines[]='Último control registrado: '.local_date($last['occurred_at'] ?? $last['created_at']);
            foreach (['phase'=>'Fase','observations'=>'Hallazgos','procedures'=>'Procedimientos realizados','incidents'=>'Incidencias','instructions'=>'Indicaciones'] as $key=>$label) if (!empty($last[$key])) $lines[]=$label.': '.$last[$key];
            if ($kind==='evolution') foreach (array_slice($detail['controls'],1,4) as $previous) $lines[]='Control anterior '.local_date($previous['occurred_at'] ?? $previous['created_at']).': '.($previous['observations'] ?? 'Sin hallazgos registrados').'; procedimientos: '.($previous['procedures'] ?? 'No registrados');
        } else $lines[]='Controles: no registrados.';
        $lines[]='Próximo control indicado: '.($case['next_control'] ?? 'No registrado').'. Consultar la agenda para confirmar hora y asistencia.';
        $tasks=array_filter($detail['tasks'] ?? [],fn($t)=>$t['status']==='pending');
        $lines[]='Tareas pendientes registradas: '.count($tasks);
        foreach ($tasks as $task) $lines[]='• '.$task['title'].(!empty($task['due_date']) ? ' · '.$task['due_date']:'');
        $lines[]='Objetivos pendientes: revisar las fases y metas documentadas con el profesional. No se infiere cumplimiento a partir del tiempo transcurrido.';
        $lines[]='Validación y firma del profesional: __________________________';
        return implode("\n",$lines);
    }
}
