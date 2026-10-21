<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\DB;
final class Auth {
    private static ?array $current = null;
    public static function user(): ?array {
        if (self::$current) return self::$current;
        if (!isset($_SESSION['user_id']) && !empty($_COOKIE['yasig_remember'])) {
            $token=DB::one('SELECT * FROM remember_tokens WHERE token_hash=? AND expires_at>UTC_TIMESTAMP()',[hash('sha256',$_COOKIE['yasig_remember'])]);
            if ($token) { self::establish((int)$token['user_id']); DB::query('DELETE FROM remember_tokens WHERE id=?',[$token['id']]); self::remember((int)$token['user_id']); }
        }
        if (empty($_SESSION['user_id'])) return null;
        if (time()-($_SESSION['last_active'] ?? 0)>1800) { self::logout(); return null; }
        $u=DB::one('SELECT u.*,r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.active=1',[$_SESSION['user_id']]);
        if (!$u || $u['session_version']!==($_SESSION['session_version'] ?? 0)) { self::logout(); return null; }
        $_SESSION['last_active']=time(); unset($u['password']); return self::$current=$u;
    }
    public static function can(string $permission): bool {
        $u=self::user(); return $u && (bool)DB::one('SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.name=?',[$u['role_id'],$permission]);
    }
    public static function throttle(string $scope, string $identifier, int $limit=5): void {
        $bucket=hash('sha256',$scope.'|'.$identifier);
        // Serialize attempts per bucket, including concurrent requests.
        if (!(int)DB::query('SELECT GET_LOCK(?,5)',['yasig:'.substr($bucket,0,58)])->fetchColumn()) throw new \DomainException('Inténtalo nuevamente en unos segundos.');
        try {
            $count=(int)DB::query('SELECT COUNT(*) FROM auth_attempts WHERE bucket=? AND created_at>UTC_TIMESTAMP()-INTERVAL 15 MINUTE',[$bucket])->fetchColumn();
            if ($count>=$limit) throw new \DomainException('Demasiados intentos. Espera 15 minutos.');
            DB::insert('auth_attempts',['bucket'=>$bucket]);
        } finally { DB::query('SELECT RELEASE_LOCK(?)',['yasig:'.substr($bucket,0,58)]); }
    }
    public static function login(string $email,string $password,bool $remember): void {
        self::throttle('login-ip',$_SERVER['REMOTE_ADDR'] ?? 'cli',30);
        self::throttle('login-account',strtolower($email));
        if (!flag('APP_DEMO') && str_ends_with(strtolower($email),'@yasig.demo')) throw new \DomainException('Correo o contraseña incorrectos.');
        $u=DB::one('SELECT * FROM users WHERE email=? AND active=1',[$email]);
        $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if (!password_verify($password,$u['password'] ?? $dummy) || !$u) throw new \DomainException('Correo o contraseña incorrectos.');
        self::establish((int)$u['id']);
        if ($remember) self::remember((int)$u['id']);
        DB::audit('login','users',(int)$u['id']);
    }
    private static function establish(int $id): void {
        $u=DB::one('SELECT session_version FROM users WHERE id=? AND active=1',[$id]); if (!$u) return;
        session_regenerate_id(true); $_SESSION=['user_id'=>$id,'session_version'=>$u['session_version'],'last_active'=>time(),'csrf'=>bin2hex(random_bytes(32))]; self::$current=null;
    }
    private static function remember(int $id): void {
        $token=bin2hex(random_bytes(32));
        DB::insert('remember_tokens',['user_id'=>$id,'token_hash'=>hash('sha256',$token),'expires_at'=>gmdate('Y-m-d H:i:s',time()+1209600)]);
        setcookie('yasig_remember',$token,['expires'=>time()+1209600,'path'=>base_path() ?: '/','secure'=>flag('SESSION_SECURE'),'httponly'=>true,'samesite'=>'Lax']);
    }
    public static function logout(): void {
        if (!empty($_COOKIE['yasig_remember'])) DB::query('DELETE FROM remember_tokens WHERE token_hash=?',[hash('sha256',$_COOKIE['yasig_remember'])]);
        setcookie('yasig_remember','',['expires'=>time()-3600,'path'=>base_path() ?: '/','secure'=>flag('SESSION_SECURE'),'httponly'=>true,'samesite'=>'Lax']);
        $_SESSION=[]; self::$current=null; if (session_status()===PHP_SESSION_ACTIVE) session_regenerate_id(true);
    }
    public static function resetToken(int $id): string {
        $token=bin2hex(random_bytes(32));
        DB::transaction(function() use($id,$token) { DB::query('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL',[$id]); DB::insert('password_reset_tokens',['user_id'=>$id,'token_hash'=>hash('sha256',$token),'expires_at'=>gmdate('Y-m-d H:i:s',time()+1800)]); });
        return $token;
    }
    public static function reset(string $token,string $password): void {
        $hash=\App\Validators\Input::password($password);
        DB::transaction(function() use($token,$hash) {
            $t=DB::one('SELECT * FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE',[hash('sha256',$token)]);
            if (!$t) throw new \DomainException('El enlace venció o ya fue utilizado.');
            DB::query('UPDATE users SET password=?,session_version=session_version+1 WHERE id=?',[$hash,$t['user_id']]);
            DB::query('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL',[$t['user_id']]);
            DB::query('DELETE FROM remember_tokens WHERE user_id=?',[$t['user_id']]); DB::audit('password_reset','users',(int)$t['user_id']);
        });
    }
}
