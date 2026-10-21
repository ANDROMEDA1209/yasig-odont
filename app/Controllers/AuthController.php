<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\DB;
use App\Services\Auth;
use App\Validators\Input as V;
final class AuthController {
    public static function handle(string $path): void {
        $error='';
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            \App\Middleware\Guard::csrf();
            try {
                if ($path==='/login') { Auth::login(V::email($_POST),V::text($_POST,'password',72),!empty($_POST['remember'])); redirect('/admin'); }
                if ($path==='/logout') { DB::audit('logout','users',(int)user()['id']); Auth::logout(); redirect('/login'); }
                if ($path==='/reset') { Auth::reset(V::text($_POST,'token',64),V::text($_POST,'password',72)); flash('Contraseña actualizada. Ya puedes iniciar sesión.'); redirect('/login'); }
                if ($path==='/forgot') {
                    Auth::throttle('recovery',$_SERVER['REMOTE_ADDR'] ?? '',5); $email=V::email($_POST); $u=DB::one('SELECT id,name FROM users WHERE email=? AND active=1',[$email]);
                    if ($u && env('MAIL_HOST') && env('MAIL_FROM')) {
                        $token=Auth::resetToken((int)$u['id']); $mail=new \PHPMailer\PHPMailer\PHPMailer(true); $mail->isSMTP(); $mail->Host=env('MAIL_HOST'); $mail->Port=(int)env('MAIL_PORT','587'); $mail->SMTPAuth=true; $mail->Username=env('MAIL_USERNAME'); $mail->Password=env('MAIL_PASSWORD'); $mail->SMTPSecure=$mail->Port===465 ? 'ssl':'tls'; $mail->CharSet='UTF-8'; $mail->setFrom(env('MAIL_FROM'),'Yasig Odont'); $mail->addAddress($email); $mail->Subject='Restablecer acceso'; $mail->Body='Enlace de un solo uso, válido 30 minutos: '.rtrim(env('APP_URL'),'/').'/reset?token='.$token; $mail->send();
                    }
                    flash('Si la cuenta existe y hay correo configurado, recibirás un enlace. También puedes solicitar el restablecimiento a la administración.'); redirect('/forgot');
                }
            } catch (\DomainException $e) { $error=$e->getMessage(); \App\Services\WorkspaceContext::feedback('error',\App\Services\WorkspaceContext::errors($e)); } catch (\Throwable $e) { error_log($e->__toString()); $error='No se pudo completar la solicitud. Contacta a la administración.'; }
        }
        if ($path==='/logout') redirect('/login');
        view('auth/login',['path'=>$path,'error'=>$error]);
    }
}
