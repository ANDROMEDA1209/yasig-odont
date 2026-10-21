<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\DB;
final class Uploads {
    public static function validate(array $file): string {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || ($file['size'] ?? 0)>5242880 || ($file['size'] ?? 0)<1) throw new \DomainException('Adjunta un archivo de hasta 5 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']); $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
        $allowed=['application/pdf'=>['pdf'],'image/jpeg'=>['jpg','jpeg'],'image/png'=>['png']];
        if (!isset($allowed[$mime]) || !in_array($ext,$allowed[$mime],true)) throw new \DomainException('Solo se admiten PDF, JPG y PNG con tipo de archivo válido.');
        if (str_starts_with($mime,'image/') && !getimagesize($file['tmp_name'])) throw new \DomainException('La imagen no es válida.');
        return $mime;
    }
    // $context pins the file to the exact tooth, treatment, session or stage it documents — every key is
    // optional so the original patient-level "Archivos de la historia" upload keeps working unchanged.
    public static function save(int $patient, array $file, array $context=[], ?callable $onSaved=null): int {
        $record=DB::one('SELECT id FROM clinical_records WHERE patient_id=?',[$patient]); if (!$record) throw new \DomainException('Historia no encontrada.');
        $mime=self::validate($file); $name=bin2hex(random_bytes(24)).'.bin'; $target=ROOT.'/storage/private/'.$name;
        $data=['clinical_record_id'=>$record['id'],'user_id'=>user()['id'],'original_name'=>mb_substr(basename($file['name']),0,190),'stored_name'=>$name,'mime'=>$mime,'size'=>$file['size']];
        $data['tooth_fdi']=empty($context['tooth_fdi']) ? null : \App\Validators\Input::id($context,'tooth_fdi');
        $data['treatment_plan_item_id']=empty($context['treatment_plan_item_id']) ? null : \App\Validators\Input::id($context,'treatment_plan_item_id');
        $data['session_id']=empty($context['session_id']) ? null : \App\Validators\Input::id($context,'session_id');
        $data['stage_id']=empty($context['stage_id']) ? null : \App\Validators\Input::id($context,'stage_id');
        $data['category']=isset($context['category']) && $context['category']!=='' ? \App\Validators\Input::choice($context,'category',['photo','xray','document','other']) : 'document';
        $data['captured_at']=empty($context['captured_at']) ? null : \App\Validators\Input::date($context['captured_at']);
        $data['description']=\App\Validators\Input::text($context,'description',500,false);
        self::validateContext($patient,$data);
        if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'],$target)) throw new \RuntimeException('No se pudo guardar el adjunto.');
        try { return DB::transaction(function() use($data,$onSaved) { $id=DB::insert('attachments',$data); if ($onSaved) $onSaved($id); DB::audit('attachment_uploaded','attachments',$id); return $id; }); } catch (\Throwable $e) { unlink($target); throw $e; }
    }
    public static function validateContext(int $patient,array $data): void {
        if (!empty($data['tooth_fdi']) && !DB::one('SELECT fdi FROM teeth WHERE fdi=?',[$data['tooth_fdi']])) throw new \DomainException('Pieza dental no válida.');
        $item=(int)($data['treatment_plan_item_id'] ?? 0);
        if ($item && !DB::one('SELECT i.id FROM treatment_plan_items i JOIN treatment_plans p ON p.id=i.plan_id WHERE i.id=? AND p.patient_id=?',[$item,$patient])) throw new \DomainException('El tratamiento no pertenece al paciente.');
        foreach (['session_id'=>'treatment_sessions','stage_id'=>'treatment_stages'] as $key=>$table) {
            if (empty($data[$key])) continue;
            $row=DB::one('SELECT s.treatment_plan_item_id FROM '.$table.' s JOIN treatment_plan_items i ON i.id=s.treatment_plan_item_id JOIN treatment_plans p ON p.id=i.plan_id WHERE s.id=? AND p.patient_id=?',[$data[$key],$patient]);
            if (!$row || ($item && (int)$row['treatment_plan_item_id']!==$item)) throw new \DomainException('El control o la fase no pertenece al tratamiento del paciente.');
            $item=(int)$row['treatment_plan_item_id'];
        }
        if ($item && DB::one("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orth_cases'")) {
            $case=DB::one('SELECT id FROM orth_cases WHERE treatment_plan_item_id=?',[$item]);
            if ($case) Orthodontics::assertCase((int)$case['id']);
        }
    }
}
