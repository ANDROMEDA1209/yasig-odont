<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
if (is_file(ROOT . '/vendor/autoload.php')) require ROOT . '/vendor/autoload.php';
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $file = ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require $file;
    }
});
if (is_file(ROOT . '/.env')) foreach (file(ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    if (getenv(trim($key)) === false) putenv(trim($key) . '=' . trim($value, " \t\n\r\0\x0B\"'"));
}
require ROOT . '/app/Helpers/functions.php';
date_default_timezone_set(env('APP_TIMEZONE', 'America/Guayaquil'));
ini_set('display_errors', '0');
ini_set('log_errors', '1');
foreach (['logs','private','cache','backups','sessions'] as $directory) if (!is_dir(ROOT . '/storage/' . $directory)) mkdir(ROOT . '/storage/' . $directory, 0700, true);
ini_set('error_log', ROOT . '/storage/logs/php.log');
if (PHP_SAPI !== 'cli') {
    if (env('APP_ENV') === 'production' && flag('APP_DEMO')) throw new RuntimeException('El modo demo debe estar desactivado en producción.');
    if (env('APP_ENV') === 'production' && !flag('SESSION_SECURE')) throw new RuntimeException('HTTPS y SESSION_SECURE son obligatorios.');
    session_save_path(ROOT . '/storage/sessions');
    ini_set('session.use_strict_mode', '1');
    session_name('yasig_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>base_path() ?: '/', 'secure'=>flag('SESSION_SECURE'),'httponly'=>true,'samesite'=>'Lax']);
    session_start();
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
    header('Cache-Control: no-store');
    if (flag('SESSION_SECURE')) header('Strict-Transport-Security: max-age=31536000');
}
