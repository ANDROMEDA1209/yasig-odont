<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/bootstrap/app.php';
use App\Models\DB;
try {
    $command=$argv[1] ?? 'help';
    if ($command==='migrate') {
        DB::connection()->exec('CREATE TABLE IF NOT EXISTS migrations (version VARCHAR(100) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        foreach (glob(ROOT.'/database/migrations/*.php') as $file) { $version=basename($file); if (DB::one('SELECT version FROM migrations WHERE version=?',[$version])) continue; (require $file)(); DB::insert('migrations',['version'=>$version]); echo "Migración: $version\n"; }
        (require ROOT.'/database/seeds/BaseSeeder.php')(); echo "Base inicializada.\n";
    } elseif ($command==='demo') { (require ROOT.'/database/seeds/DemoSeeder.php')(); echo "Demo cargada. admin@yasig.demo / Demo-Yasig-2026!\n";
    } elseif ($command==='demo-reset') {
        if (!flag('APP_DEMO') || env('APP_ENV')==='production' || ($argv[2] ?? '')!=='--confirm-demo') throw new DomainException('Solo demo: añade --confirm-demo.');
        require ROOT.'/scripts/reset-demo.php'; (require ROOT.'/database/seeds/DemoSeeder.php')(); echo "Demo restablecida.\n";
    } elseif ($command==='admin') {
        $email=$argv[2] ?? ''; $name=$argv[3] ?? 'Administrador'; if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new DomainException('Uso: php scripts/console.php admin correo nombre. Contraseña por stdin.');
        echo "Contraseña (12–72 caracteres; stdin; se mostrará si la terminal tiene eco habilitado):\n";
        $hash=App\Validators\Input::password(trim((string)fgets(STDIN)));
        $r=DB::one('SELECT id FROM roles WHERE name=?',['Administrador']); DB::insert('users',['role_id'=>$r['id'],'name'=>$name,'email'=>strtolower($email),'password'=>$hash]); echo "Administrador creado.\n";
    } elseif ($command==='reset-link') {
        $u=DB::one('SELECT id FROM users WHERE email=? AND active=1',[$argv[2] ?? '']); if (!$u) throw new DomainException('Cuenta no encontrada.');
        echo rtrim(env('APP_URL'),'/').'/reset?token='.App\Services\Auth::resetToken((int)$u['id'])."\nVálido 30 minutos. Entregar por un canal privado; no guardar en el repositorio.\n";
    } else echo "Comandos: migrate | demo | demo-reset --confirm-demo | admin <correo> <nombre> | reset-link <correo>\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
