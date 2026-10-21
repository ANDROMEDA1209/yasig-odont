<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\DB;
use App\Validators\Input as V;

/** Metadata over the existing private clinical attachment store. */
final class OrthodonticFiles {
    public const CATEGORIES=['frontal_rest'=>'Frontal en reposo','frontal_smile'=>'Frontal sonriendo','profile'=>'Perfil facial','intraoral_front'=>'Intraoral frontal','lateral_right'=>'Lateral derecha','lateral_left'=>'Lateral izquierda','occlusal_upper'=>'Oclusal superior','occlusal_lower'=>'Oclusal inferior','additional'=>'Adicional'];

    public static function upload(array $d,array $files): int {
        $case=Orthodontics::assertCase(V::id($d,'case_id'));
        if (!Orthodontics::detail((int)$case['id'])['can_assist']) throw new \DomainException('No tienes autorización para adjuntar archivos.',403);
        $category=V::choice($d,'category',['photo','xray','document','other']);
        $photoCategory=$category==='photo' ? V::choice(['photo_category'=>$d['photo_category'] ?? 'additional'],'photo_category',array_keys(Orthodontics::options()['photo_categories'])):null;
        $captured=empty($d['captured_at']) ? date('Y-m-d'):V::date((string)$d['captured_at']);
        if ($captured>date('Y-m-d')) throw new \DomainException('La fecha de captura no puede ser futura.');
        $phase=V::text($d,'phase',100,false); $description=V::text($d,'description',500,false);
        $control=empty($d['control_id']) ? null:V::id($d,'control_id'); $session=null;
        if ($control) {
            $row=DB::one('SELECT session_id FROM orth_controls WHERE id=? AND case_id=?',[$control,$case['id']]);
            if (!$row) throw new \DomainException('El control no pertenece a este expediente.');
            $session=$row['session_id'];
        }
        $batch=self::normalize($files);
        if (!$batch || count($batch)>12) throw new \DomainException('Selecciona entre 1 y 12 archivos. Máximo 5 MB por archivo.');
        if (array_sum(array_column($batch,'size'))>35*1024*1024) throw new \DomainException('La selección completa no puede superar 35 MB. Carga los archivos en grupos más pequeños.');
        // Validate the whole selection before persisting the first original.
        foreach ($batch as $file) {
            $mime=Uploads::validate($file);
            if ($category==='photo' && !str_starts_with($mime,'image/')) throw new \DomainException('Las fotografías deben ser JPG o PNG.');
        }
        $saved=0;
        try {
            foreach ($batch as $file) {
                Uploads::save((int)$case['patient_id'],$file,['category'=>$category,'captured_at'=>$captured,'description'=>$description,'treatment_plan_item_id'=>$case['treatment_plan_item_id'],'session_id'=>$session],function(int $attachment) use($case,$control,$photoCategory,$phase,$description) {
                    Orthodontics::assertCase((int)$case['id']);
                    DB::insert('orth_attachments',['case_id'=>$case['id'],'attachment_id'=>$attachment,'control_id'=>$control,'photo_category'=>$photoCategory,'phase'=>$phase,'description'=>$description]);
                    Orthodontics::logEvent((int)$case['id'],'file_added',$attachment);
                });
                $saved++;
            }
        } catch (\Throwable $e) {
            if ($saved) throw new \DomainException($saved.' archivo(s) guardado(s). La carga restante falló; revisa la galería antes de volver a adjuntar.');
            throw $e;
        }
        return (int)$case['id'];
    }
    private static function normalize(array $files): array {
        if (!isset($files['name'])) return [];
        if (!is_array($files['name'])) return [$files];
        $out=[];
        foreach ($files['name'] as $i=>$name) $out[]=['name'=>$name,'tmp_name'=>$files['tmp_name'][$i] ?? '', 'error'=>$files['error'][$i] ?? UPLOAD_ERR_NO_FILE,'size'=>$files['size'][$i] ?? 0];
        return $out;
    }
}
