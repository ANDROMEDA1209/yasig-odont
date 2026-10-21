<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\DB;

final class OrthodonticDocuments {
    public static function html(int $id,array $query,bool $forPdf=false): string {
        $ortho=Orthodontics::detail($id);
        $allowed=['diagnosis','plan','controls','photos','retention','assistant'];
        $selection=$query['include'] ?? $query['sections'] ?? ['diagnosis','plan','controls'];
        if (!is_array($selection)) throw new \DomainException('Selecciona las secciones del informe.');
        $sections=array_values(array_intersect($allowed,$selection));
        if (!$sections) throw new \DomainException('Selecciona al menos una sección del informe.');
        $report=in_array($query['report'] ?? '',['summary','diagnosis','evolution','controls','photos','completion','retention'],true) ? $query['report']:'summary';
        $titles=['summary'=>'Resumen del expediente ortodóntico','diagnosis'=>'Diagnóstico y plan de tratamiento','evolution'=>'Informe de evolución','controls'=>'Historial de controles','photos'=>'Comparativa fotográfica','completion'=>'Informe de finalización','retention'=>'Plan de retención'];
        if ($report==='completion' && empty($ortho['case']['completed_at'])) throw new \DomainException('El caso todavía no tiene una finalización clínica confirmada.');
        $title=$titles[$report];
        $clinic=[]; foreach (DB::all('SELECT name,value FROM settings WHERE name IN ("clinic_address","clinic_phone","clinic_email")') as $setting) $clinic[$setting['name']]=$setting['value'];
        $drafts=DB::all('SELECT p.*,u.name reviewer FROM orth_assistant_proposals p LEFT JOIN users u ON u.id=p.reviewed_by WHERE p.case_id=? AND p.status="accepted" ORDER BY p.id DESC LIMIT 3',[$id]);
        $photos=[];
        if (in_array('photos',$sections,true)) {
            $media=$ortho['photos'] ?? [];
            $selected=$query['photo_ids'] ?? [];
            if (!is_array($selected)) throw new \DomainException('Selección de fotografías no válida.');
            if ($selected) $media=array_values(array_filter($media,fn($p)=>in_array((int)($p['attachment_id'] ?? $p['id']),array_map('intval',$selected),true)));
            foreach (array_slice($media,0,4) as $photo) {
                $attachment=DB::one('SELECT * FROM attachments WHERE id=?',[(int)($photo['attachment_id'] ?? $photo['id'])]);
                if (!$attachment || !in_array($attachment['mime'],['image/jpeg','image/png'],true)) continue;
                Orthodontics::authorizeAttachment((int)$attachment['id']);
                $path=ROOT.'/storage/private/'.$attachment['stored_name'];
                if (!is_file($path)) continue;
                // A derivative for the document only; the uploaded original remains unmodified.
                $binary=file_get_contents($path); $mime=$attachment['mime'];
                if (function_exists('imagecreatefromstring')) {
                    $source=@imagecreatefromstring($binary);
                    if ($source) {
                        $width=imagesx($source);$height=imagesy($source);$scale=min(1,1000/max($width,$height));
                        $target=imagecreatetruecolor(max(1,(int)($width*$scale)),max(1,(int)($height*$scale)));
                        $white=imagecolorallocate($target,255,255,255);imagefill($target,0,0,$white);
                        imagecopyresampled($target,$source,0,0,0,0,imagesx($target),imagesy($target),$width,$height);
                        ob_start();imagejpeg($target,null,80);$binary=ob_get_clean();$mime='image/jpeg';imagedestroy($source);imagedestroy($target);
                    }
                }
                $photos[]=[...$photo,'src'=>'data:'.$mime.';base64,'.base64_encode($binary),'captured_at'=>$attachment['captured_at']];
            }
        }
        ob_start(); view('orthodontics/report',compact('ortho','sections','title','clinic','drafts','photos','forPdf')); return ob_get_clean();
    }
    public static function output(int $id,array $query): never {
        $format=$query['format'] ?? 'print'; if (!in_array($format,['pdf','print'],true)) throw new \DomainException('Formato de informe no válido.');
        $html=self::html($id,$query,$format==='pdf');
        DB::audit('orthodontic_report_export','orth_cases',$id,$format);
        header('Cache-Control: no-store, private');
        if ($format==='print') { echo $html; exit; }
        $pdf=new \Dompdf\Dompdf(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'chroot'=>ROOT.'/storage/cache','tempDir'=>ROOT.'/storage/cache','fontCache'=>ROOT.'/storage/cache']);
        $pdf->loadHtml($html); $pdf->setPaper('A4'); $pdf->render(); $pdf->stream('ortodoncia-'.$id.'.pdf',['Attachment'=>true]); exit;
    }
}
