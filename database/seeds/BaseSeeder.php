<?php
declare(strict_types=1);
use App\Models\DB;
return static function (): void {
    if (DB::one('SELECT name FROM settings WHERE name=?',['base_seed_version'])) return;
    DB::transaction(static function (): void {
    $permissions=['dashboard','patients','appointments','clinical','treatments','payments','inventory','reports','users','settings'];
    foreach ($permissions as $p) DB::query('INSERT IGNORE INTO permissions(name) VALUES (?)',[$p]);
    $roles=['Administrador'=>$permissions,'Odontólogo'=>['dashboard','patients','appointments','clinical','treatments'],'Recepción'=>['dashboard','patients','appointments'],'Asistente odontológico'=>['dashboard','inventory'],'Personal de caja'=>['dashboard','payments','reports']];
    foreach ($roles as $name=>$allowed) {
        DB::query('INSERT IGNORE INTO roles(name) VALUES (?)',[$name]); $role=DB::one('SELECT id FROM roles WHERE name=?',[$name]);
        foreach ($allowed as $p) DB::query('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE name=?',[$role['id'],$p]);
    }
    foreach (range(1,8) as $q) foreach (range(1,$q<=4 ? 8 : 5) as $p) DB::query('INSERT IGNORE INTO teeth(fdi,dentition,quadrant,position) VALUES (?,?,?,?)',[$q*10+$p,$q<=4 ? 'permanent':'primary',$q,$p]);
    $services=[['VAL','Valoración integral','Una evaluación para conocer tu salud bucal y definir juntos el siguiente paso.',20,30],['LIM','Limpieza dental','Cuidado preventivo y eliminación profesional de placa y cálculo.',35,45],['RES','Restauración dental','Recuperación de la forma y función de las piezas dentales.',45,60],['ORT','Ortodoncia','Valoración individual de la alineación dental y la mordida.',30,45],['END','Endodoncia','Evaluación y tratamiento de los tejidos internos del diente.',160,90],['PED','Odontopediatría','Atención cercana y adaptada a las necesidades de los más pequeños.',25,30]];
    foreach ($services as [$code,$name,$description,$price,$duration]) {
        DB::query('INSERT IGNORE INTO services(code,name,description,price,duration_minutes) VALUES (?,?,?,?,?)',[$code,$name,$description,$price,$duration]);
        DB::query('INSERT IGNORE INTO treatments(code,name,description,price,duration_minutes) VALUES (?,?,?,?,?)',[$code,$name,$description,$price,$duration]);
    }
    DB::query('INSERT INTO settings(name,value) VALUES (?,?)',['base_seed_version','1']);
    });
};
