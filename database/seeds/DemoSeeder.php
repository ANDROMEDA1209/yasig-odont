<?php
declare(strict_types=1);
use App\Models\DB;
return static function (): void {
    if (!flag('APP_DEMO') || env('APP_ENV')==='production') throw new DomainException('El seeder demo solo funciona fuera de producción y con APP_DEMO=true.');
    if (DB::one('SELECT id FROM users WHERE email=?',['admin@yasig.demo'])) throw new DomainException('La demo ya está cargada. Usa el comando demo-reset para restablecerla.');
    DB::transaction(function() {
        $role=DB::one('SELECT id FROM roles WHERE name=?',['Administrador']);
        $admin=DB::insert('users',['role_id'=>$role['id'],'name'=>'Administración Demo','email'=>'admin@yasig.demo','password'=>password_hash('Demo-Yasig-2026!',PASSWORD_DEFAULT)]);
        $drRole=DB::one('SELECT id FROM roles WHERE name=?',['Odontólogo']);
        $dentists=[];
        foreach (['Profesional Demo A','Profesional Demo B'] as $n=>$name) {
            $u=DB::insert('users',['role_id'=>$drRole['id'],'name'=>$name,'email'=>'odontologo'.($n+1).'@yasig.demo','password'=>password_hash('Demo-Yasig-2026!',PASSWORD_DEFAULT)]);
            $dentists[]=DB::insert('dentists',['user_id'=>$u,'name'=>$name,'specialty'=>$n ? 'Atención infantil · perfil ficticio':'Odontología general · perfil ficticio']);
        }
        foreach (['Recepción','Asistente odontológico','Personal de caja'] as $i=>$name) {
            $r=DB::one('SELECT id FROM roles WHERE name=?',[$name]); DB::insert('users',['role_id'=>$r['id'],'name'=>$name.' Demo','email'=>['recepcion','asistente','caja'][$i].'@yasig.demo','password'=>password_hash('Demo-Yasig-2026!',PASSWORD_DEFAULT)]);
        }
        $services=DB::all('SELECT * FROM services'); $treatments=DB::all('SELECT * FROM treatments');
        $names=[['Alba','Paciente Demo'],['Bruno','Paciente Demo'],['Clara','Paciente Demo'],['Diego','Paciente Demo'],['Elena','Paciente Demo'],['Fabio','Paciente Demo'],['Greta','Paciente Demo'],['Hugo','Paciente Demo'],['Iris','Paciente Demo'],['Joel','Paciente Demo'],['Kiara','Paciente Demo'],['Leo','Paciente Demo']];
        foreach ($names as $i=>[$first,$last]) {
            $p=DB::insert('patients',['code'=>'YO-DEMO-'.str_pad((string)($i+1),4,'0',STR_PAD_LEFT),'first_name'=>$first,'last_name'=>$last,'document'=>'FICTICIO-'.($i+1),'birth_date'=>'1995-06-15','phone'=>'DEMO-SIN-TELEFONO','email'=>'paciente'.($i+1).'@example.invalid']);
            $r=DB::insert('clinical_records',['patient_id'=>$p,'medical_history'=>'Antecedentes ficticios para demostración.','allergies'=>$i===0 ? 'Alergia ficticia de ejemplo.':'Sin alergias registradas (demo)']);
            $o=DB::insert('odontograms',['clinical_record_id'=>$r,'user_id'=>$admin]);
            foreach ([[16,'caries'],[26,'restoration'],[36,'planned_procedure']] as [$fdi,$state]) DB::insert('odontogram_findings',['odontogram_id'=>$o,'tooth_fdi'=>$fdi,'surface'=>'occlusal','state'=>$state,'observation'=>'Hallazgo ficticio de demostración.']);
            $plan=DB::insert('treatment_plans',['patient_id'=>$p,'dentist_id'=>$dentists[$i%2],'name'=>'Plan preventivo · Demo']);
            foreach (array_slice($treatments,0,3) as $j=>$t) DB::insert('treatment_plan_items',['plan_id'=>$plan,'treatment_id'=>$t['id'],'price'=>$t['price'],'status'=>$j===0 ? 'completed':'planned','completed_at'=>$j===0 ? gmdate('Y-m-d H:i:s'):null]);
            $pay=DB::insert('payments',['plan_id'=>$plan,'user_id'=>$admin,'amount'=>'20.00','method'=>'cash','request_key'=>hash('sha256','demo'.$p)]);
            DB::insert('payment_receipts',['payment_id'=>$pay,'number'=>'YO-DEMO-'.str_pad((string)$pay,6,'0',STR_PAD_LEFT)]);
            for ($week=-3;$week<=1;$week++) {
                $local=(new DateTimeImmutable('today'))->modify(($week*7+($i%6)).' days')->setTime(8+intdiv($i,2),0);
                if ($local->format('N')==='7') $local=$local->modify('+1 day');
                $state=$week<0 ? ['completed','completed','no_show','cancelled'][$i%4] : ['confirmed','pending','confirmed'][$i%3];
                DB::insert('appointments',['patient_id'=>$p,'dentist_id'=>$dentists[$i%2],'service_id'=>$services[0]['id'],'starts_at'=>$local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'ends_at'=>$local->modify('+30 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'status'=>$state,'reason'=>'Consulta ficticia para demo.']);
            }
        }
        foreach ([['MAT-01','Guantes de nitrilo','Protección','caja',12,5],['MAT-02','Resina de demostración','Restauración','unidad',3,5],['MAT-03','Mascarillas','Protección','caja',8,3]] as [$code,$name,$category,$unit,$stock,$min]) {
            $item=DB::insert('inventory_items',['code'=>$code,'name'=>$name,'category'=>$category,'unit'=>$unit,'stock'=>$stock,'min_stock'=>$min]);
            $lot=DB::insert('inventory_lots',['item_id'=>$item,'lot'=>'LOTE-DEMO','expires_on'=>date('Y-m-d',strtotime('+1 year')),'stock'=>$stock]);
            DB::insert('inventory_movements',['item_id'=>$item,'lot_id'=>$lot,'user_id'=>$admin,'quantity'=>$stock,'reason'=>'Entrada inicial ficticia']);
        }
        DB::audit('demo_seeded','system');
    });
};
