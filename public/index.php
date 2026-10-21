<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/app.php';
try {
    $uri=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/'); $base=base_path();
    $path=$base && str_starts_with($uri,$base.'/') ? substr($uri,strlen($base)) : $uri;
    $path=rtrim($path,'/') ?: '/';
    if (!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)) { http_response_code(405); header('Allow: GET, POST'); exit; }
    if ($path==='/health') { try { App\Models\DB::query('SELECT 1'); header('Content-Type: application/json'); echo '{"status":"ok"}'; } catch (Throwable) { http_response_code(503); echo '{"status":"unavailable"}'; } exit; }
    $routes=require ROOT.'/routes/web.php';
    if (in_array($path,$routes['auth'],true)) App\Controllers\AuthController::handle($path);
    elseif ($path==='/admin' || str_starts_with($path,'/admin/')) App\Controllers\AdminController::handle($path);
    else App\Controllers\PublicController::handle($path);
} catch (DomainException $e) {
    http_response_code(400); view('error',['title'=>'Revisa los datos de la solicitud','message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log($e->__toString()); http_response_code(503); view('error',['title'=>'El servicio necesita atención','message'=>flag('APP_DEBUG') && env('APP_ENV')!=='production' ? $e->getMessage():'No se pudo completar la solicitud. Revisa la instalación o contacta a la administración.']);
}
