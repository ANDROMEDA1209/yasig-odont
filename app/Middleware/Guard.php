<?php
declare(strict_types=1);
namespace App\Middleware;
final class Guard {
    public static function csrf(): void { if (!isset($_POST['_csrf']) || !hash_equals(csrf(),(string)$_POST['_csrf'])) { http_response_code(419); view('error',['title'=>'Formulario vencido','message'=>'Recarga la página y vuelve a intentarlo.']); exit; } }
    public static function permission(string $permission): void {
        if (!user()) redirect('/login');
        if (!can($permission)) { http_response_code(403); view('error',['title'=>'Acceso restringido','message'=>'Tu rol no tiene permiso para esta sección.']); exit; }
    }
}
