<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\DB;
final class PublicController {
    public static function handle(string $path): void {
        $pages=['/'=>'Inicio','/nosotros'=>'Nosotros','/servicios'=>'Servicios','/equipo'=>'Nuestro equipo','/galeria'=>'Nuestro espacio','/contacto'=>'Contacto','/solicitar-cita'=>'Solicitar una cita','/privacidad'=>'Privacidad'];
        $service=null;
        if (preg_match('#^/servicios/(\d+)$#',$path,$m)) { $service=DB::one('SELECT * FROM services WHERE id=? AND active=1',[$m[1]]); if ($service) $pages[$path]=$service['name']; }
        if (!isset($pages[$path])) { http_response_code(404); view('error',['title'=>'No encontramos esta página','message'=>'Puedes volver al inicio y continuar desde allí.']); return; }
        $error='';
        if ($_SERVER['REQUEST_METHOD']==='POST' && $path==='/solicitar-cita') {
            \App\Middleware\Guard::csrf();
            try { \App\Services\Auth::throttle('booking',$_SERVER['REMOTE_ADDR'] ?? '',10); \App\Services\Clinic::appointment($_POST,0,true); \App\Services\WorkspaceContext::feedback('success'); flash('Solicitud recibida. El equipo confirmará el horario contigo.'); redirect('/solicitar-cita'); }
            catch (\DomainException $e) { $error=$e->getMessage(); \App\Services\WorkspaceContext::feedback('error',\App\Services\WorkspaceContext::errors($e)); }
        }
        view('public/home',['path'=>$path,'title'=>$pages[$path],'service'=>$service,'services'=>DB::all('SELECT * FROM services WHERE active=1'),'dentists'=>DB::all('SELECT name,specialty FROM dentists WHERE active=1'),'error'=>$error]);
    }
}
