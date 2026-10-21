Set-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)
if (-not (Test-Path -LiteralPath '.env')) {
    Write-Error 'Falta .env. Sigue docs/INSTALACION.md antes de iniciar.'
    exit 1
}
Write-Host 'Inicia MySQL en XAMPP. Sitio: http://localhost:8080 | Acceso: http://localhost:8080/login'
php -S 127.0.0.1:8080 -t public public/router.php
