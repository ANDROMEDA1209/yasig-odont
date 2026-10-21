<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/app.php';
putenv('DB_DATABASE=yasig_odont_test');
try { App\Services\Clinic::appointment(json_decode(base64_decode($argv[1]),true,512,JSON_THROW_ON_ERROR)); echo 'created'; } catch(DomainException) { echo 'conflict'; }
