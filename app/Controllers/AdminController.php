<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\DB;
use App\Services\Clinic;
use App\Services\Reports;
use App\Services\WorkspaceContext as Context;
use App\Validators\Input as V;
use App\Middleware\Guard;
final class AdminController {
    public static function handle(string $path): void {
        $module=explode('/',trim($path,'/'))[1] ?? 'dashboard';
        if ($module==='dashboard-data') { self::dashboardResponse(); return; }
        $permissions=['dashboard'=>'dashboard','patients'=>'patients','appointments'=>'appointments','clinical'=>'clinical','odontogram'=>'clinical','treatment'=>'clinical','orthodontics'=>'orthodontics','treatments'=>'treatments','payments'=>'payments','receipt'=>'payments','inventory'=>'inventory','reports'=>'reports','users'=>'users','settings'=>'settings','attachment'=>'clinical','prescription'=>'clinical','account'=>'dashboard'];
        if (!isset($permissions[$module])) { http_response_code(404); view('error',['title'=>'Página no encontrada','message'=>'Vuelve al panel para continuar.']); return; }
        // Assigned assistants may see orthodontic media without receiving global clinical access.
        if ($module==='attachment' && user() && can('orthodontics') && DB::one('SELECT attachment_id FROM orth_attachments WHERE attachment_id=?',[(int)($_GET['id'] ?? 0)])) Guard::permission('orthodontics');
        else Guard::permission($permissions[$module]);
        if ($module==='orthodontics' && !empty($_GET['case_id'] ?? $_POST['case_id'] ?? null)) {
            try { \App\Services\Orthodontics::assertCase((int)($_POST['case_id'] ?? $_GET['case_id'])); }
            catch (\DomainException $e) { http_response_code(403); view('error',['title'=>'Expediente no disponible','message'=>$e->getMessage()]); return; }
        }
        if ($module==='treatment') {
            $case=DB::one('SELECT id FROM orth_cases WHERE treatment_plan_item_id=?',[(int)($_POST['item_id'] ?? $_GET['id'] ?? 0)]);
            if ($case) {
                try { \App\Services\Orthodontics::assertCase((int)$case['id'],$_SERVER['REQUEST_METHOD']==='POST'); }
                catch (\DomainException $e) { http_response_code(403); view('error',['title'=>'Expediente no disponible','message'=>$e->getMessage()]); return; }
                if ($_SERVER['REQUEST_METHOD']==='GET') redirect('/admin/orthodontics?case_id='.(int)$case['id']);
                // Keep all orthodontic evolutions in the signed, case-scoped timeline.
                throw new \DomainException('Registra los controles y las fases desde el expediente de ortodoncia.');
            }
        }
        $error='';
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0)>0) { http_response_code(413); view('error',['title'=>'La carga supera el límite del servidor','message'=>'Carga los archivos en grupos de hasta 35 MB, con un máximo de 5 MB por archivo. Vuelve al expediente para intentarlo.']); return; }
            Guard::csrf();
            try {
                Context::begin();
                if ($previous=Context::replay()) { flash('La operación ya se guardó correctamente.'); redirect($previous); }
                self::action($module);
                $overrides=[];$drop=[];
                if (in_array($module,['clinical','odontogram'],true) && !empty($_POST['patient_id'])) $overrides['patient_id']=(int)$_POST['patient_id'];
                if ($module==='odontogram' && !in_array($_POST['action'] ?? 'save',['diagnose','resolve_diagnosis','propose_treatment'],true)) $drop[]='version';
                if ($module==='treatment' && !empty($_POST['item_id'])) $overrides['id']=(int)$_POST['item_id'];
                if ($module==='appointments' && !empty($_POST['_saved_id'])) { $overrides['edit']=(int)$_POST['_saved_id'];$drop[]='new'; }
                self::finish($module,$overrides,$drop);
            }
            catch (\DomainException | \JsonException $e) { $error=$e->getMessage(); Context::feedback('error',Context::errors($e)); }
            catch (\PDOException $e) { error_log($e->__toString()); $error=(string)$e->getCode()==='23000' ? 'El registro está duplicado o tiene una relación no válida. Revisa los campos.':'No se pudieron guardar los cambios. Inténtalo nuevamente.'; Context::feedback('error'); }
        }
        if ($module==='attachment') {
            $f=DB::one('SELECT * FROM attachments WHERE id=?',[(int)($_GET['id'] ?? 0)]); if (!$f || !is_file(ROOT.'/storage/private/'.$f['stored_name'])) { http_response_code(404); exit; }
            try { \App\Services\Orthodontics::authorizeAttachment((int)$f['id']); }
            catch (\DomainException) { http_response_code(403); view('error',['title'=>'Archivo no disponible','message'=>'No tienes acceso a este expediente.']); return; }
            header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
            DB::audit('attachment_download','attachments',(int)$f['id']);
            // Thumbnails/gallery previews need the image to render in the page; everything else still forces
            // a download, same as before — `inline` only ever takes effect for the two image mime types.
            $inline=isset($_GET['inline']) && in_array($f['mime'],['image/jpeg','image/png'],true);
            header('Content-Type: '.$f['mime']); header('Content-Disposition: '.($inline ? 'inline':'attachment').'; filename="archivo-'.(int)$f['id'].'.'.(['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'][$f['mime']]).'"'); readfile(ROOT.'/storage/private/'.$f['stored_name']); return;
        }
        if ($module==='orthodontics' && isset($_GET['format'])) \App\Services\OrthodonticDocuments::output(V::id($_GET,'case_id'),$_GET);
        if ($module==='reports' && isset($_GET['export'])) Reports::export(Reports::data($_GET['type'] ?? 'appointments',$_GET['from'] ?? date('Y-m-01'),$_GET['to'] ?? date('Y-m-d')),V::choice($_GET,'export',['csv','pdf']));
        if ($module==='prescription' && isset($_GET['pdf'])) {
            $detail=\App\Services\Prescription::detail((int)($_GET['id'] ?? 0));
            if (!$detail) { http_response_code(404); view('error',['title'=>'Receta no encontrada','message'=>'Vuelve a la historia clínica para continuar.']); return; }
            \App\Services\Prescription::pdf($detail);
        }
        $data=self::data($module); view('dashboard/shell',['module'=>$module,'error'=>$error,...$data]);
    }
    private static function finish(string $module,array $overrides=[],array $drop=[],string $message='Los cambios se guardaron correctamente.'): never {
        $target=Context::destination($module,$overrides,$drop); Context::completed($target); flash($message); redirect($target);
    }
    private static function dashboardResponse(): void {
        header('Content-Type: application/json; charset=UTF-8');
        $lastActive=$_SESSION['last_active'] ?? null;
        if (!user() || !can('dashboard')) { http_response_code(user() ? 403:401); echo json_encode(['error'=>'Tu sesión ha vencido o no tienes acceso.']); return; }
        if (($_GET['_background'] ?? '')==='1' && $lastActive!==null) $_SESSION['last_active']=$lastActive;
        if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); echo json_encode(['error'=>'Método no permitido.']); return; }
        try {
            if (isset($_GET['detail'])) { echo json_encode(\App\Services\Dashboard::detail((string)$_GET['detail']),JSON_THROW_ON_ERROR); return; }
            $snapshot=\App\Services\Dashboard::snapshot($_GET); $sections=[];
            foreach (['overview','schedule','finance','events','alerts'] as $section) { ob_start(); view('dashboard/parts/'.$section,$snapshot); $sections[$section]=ob_get_clean(); }
            echo json_encode(['sections'=>$sections,'filters'=>$snapshot['f'],'updated_at'=>date('H:i:s'),'revision'=>hash('sha256',json_encode($sections))],JSON_THROW_ON_ERROR);
        } catch (\DomainException $e) { http_response_code($e->getCode()===403 ? 403:422); echo json_encode(['error'=>$e->getMessage()]); }
        catch (\Throwable $e) { error_log($e->__toString()); http_response_code(503); echo json_encode(['error'=>'No se pudieron actualizar los datos. Inténtalo nuevamente.']); }
    }
    private static function action(string $module): void {
        $d=$_POST; $action=$d['action'] ?? 'save';
        switch ($module) {
            case 'orthodontics':
                $id=match($action) {
                    'attach'=>\App\Services\OrthodonticFiles::upload($d,$_FILES['attachments'] ?? []),
                    'schedule'=>\App\Services\OrthodonticAgenda::schedule($d),
                    'link_appointment'=>\App\Services\OrthodonticAgenda::link($d),
                    'assistant','assistant_generate','assistant_review'=>\App\Services\OrthodonticAssistant::action($d),
                    default=>\App\Services\Orthodontics::action($d),
                };
                $tabs=['summary','diagnosis','photos','plan','controls','appointments','documents','history','reports','assistant'];
                $defaultTab=match($action) {'diagnosis'=>'diagnosis','plan','authorize_plan','create_stage','add_phase','phase','stage'=>'plan','control','addendum'=>'controls','attach'=>'photos','schedule','link_appointment','task','task_status'=>'appointments','consent','member'=>'documents','assistant','assistant_generate','assistant_review'=>'assistant',default=>'summary'};
                $tab=in_array($d['tab'] ?? '',$tabs,true) ? $d['tab']:$defaultTab;
                self::finish('orthodontics',$id ? ['case_id'=>$id,'tab'=>$tab]:[],[],'Los cambios del expediente se guardaron correctamente.');
            case 'patients': $id=Clinic::patient($d,(int)($d['id'] ?? 0)); self::finish('patients',[(empty($d['id']) ? 'view':'edit')=>$id],['new',empty($d['id']) ? 'edit':'view'],'Paciente guardado.');
            case 'appointments':
                if ($action==='reject_request') { DB::transaction(function() use($d) { $id=V::id($d,'id'); $changed=DB::query('UPDATE appointments SET status="cancelled" WHERE id=? AND source="public" AND status="pending"',[$id])->rowCount(); if (!$changed) throw new \DomainException('La solicitud ya fue procesada.'); DB::audit('request_cancelled','appointments',$id); }); }
                else $_POST['_saved_id']=Clinic::appointment($d,(int)($d['id'] ?? 0)); break;
            case 'clinical':
                if ($action==='upload') \App\Services\Uploads::save(V::id($d,'patient_id'),$_FILES['attachment'] ?? [],$d);
                elseif ($action==='prescribe') { $id=\App\Services\Prescription::create($d); $target=url('/admin/prescription?id='.$id.'&pdf=1'); Context::completed($target); flash('Receta emitida correctamente.'); redirect($target); }
                elseif ($action==='cancel_prescription') \App\Services\Prescription::cancel($d);
                else Clinic::clinical($d); break;
            case 'odontogram':
                if ($action==='diagnose') \App\Services\Treatment::diagnose($d);
                elseif ($action==='resolve_diagnosis') \App\Services\Treatment::resolveDiagnosis($d);
                elseif ($action==='propose_treatment') {
                    if (empty($d['plan_id'])) Clinic::plan($d);
                    else DB::transaction(function() use($d) { $p=DB::one('SELECT id,status FROM treatment_plans WHERE id=? AND patient_id=? FOR UPDATE',[V::id($d,'plan_id'),V::id($d,'patient_id')]); if (!$p || $p['status']==='cancelled') throw new \DomainException('Plan no disponible.'); Clinic::planItem($d,(int)$p['id']); DB::audit('plan_item_added','treatment_plans',(int)$p['id']); });
                }
                else Clinic::odontogram($d); break;
            case 'treatment':
                if ($action==='clinical_status') \App\Services\Treatment::clinicalStatus($d);
                elseif ($action==='stage') \App\Services\Treatment::stage($d);
                elseif ($action==='session') \App\Services\Treatment::session($d);
                elseif ($action==='upload') \App\Services\Uploads::save(V::id($d,'patient_id'),$_FILES['attachment'] ?? [],$d);
                elseif ($action==='prescribe') { $id=\App\Services\Prescription::create($d); $target=url('/admin/prescription?id='.$id.'&pdf=1'); Context::completed($target); flash('Receta emitida correctamente.'); redirect($target); }
                elseif ($action==='cancel_prescription') \App\Services\Prescription::cancel($d);
                break;
            case 'treatments':
                if ($action==='catalog') {
                    $data=['code'=>V::text($d,'code',30),'name'=>V::text($d,'name',150),'description'=>V::text($d,'description',3000,false),'price'=>V::cents($d,'price')/100,'duration_minutes'=>V::id($d,'duration_minutes')];
                    if ($data['duration_minutes']>240) throw new \DomainException('La duración máxima es de 240 minutos.');
                    $id=(int)($d['id'] ?? 0); if ($id) DB::query('UPDATE treatments SET code=?,name=?,description=?,price=?,duration_minutes=? WHERE id=?',[...array_values($data),$id]); else $id=DB::insert('treatments',$data); DB::audit('treatment_catalog_saved','treatments',$id);
                } elseif ($action==='item') {
                    DB::transaction(function() use($d) { $p=DB::one('SELECT id,status FROM treatment_plans WHERE id=? FOR UPDATE',[V::id($d,'plan_id')]); if (!$p || $p['status']==='cancelled') throw new \DomainException('Plan no disponible.'); Clinic::planItem($d,(int)$p['id']); DB::audit('plan_item_added','treatment_plans',(int)$p['id']); });
                } elseif ($action==='status') {
                    DB::transaction(function() use($d) {
                        $id=V::id($d,'id'); $i=DB::one('SELECT plan_id FROM treatment_plan_items WHERE id=?',[$id]); if (!$i) throw new \DomainException('Procedimiento no encontrado.');
                        DB::one('SELECT id FROM treatment_plans WHERE id=? FOR UPDATE',[$i['plan_id']]);
                        $status=V::choice($d,'status',['planned','in_progress','completed','cancelled']);
                        if ($status==='cancelled') { $total=(float)DB::query('SELECT COALESCE(SUM(price),0) FROM treatment_plan_items WHERE plan_id=? AND id<>? AND status<>"cancelled"',[$i['plan_id'],$id])->fetchColumn(); $paid=(float)DB::query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE plan_id=? AND voided_at IS NULL',[$i['plan_id']])->fetchColumn(); if ($paid>$total) throw new \DomainException('Anula los cobros excedentes antes de cancelar este procedimiento.'); }
                        DB::query('UPDATE treatment_plan_items SET status=?,completed_at=?,notes=? WHERE id=?',[$status,$status==='completed' ? gmdate('Y-m-d H:i:s'):null,V::text($d,'notes',2000,false),$id]);
                        $statuses=DB::all('SELECT status FROM treatment_plan_items WHERE plan_id=?',[$i['plan_id']]); $values=array_column($statuses,'status'); $overall=count(array_diff($values,['cancelled']))===0 ? 'cancelled':(count(array_diff($values,['completed','cancelled']))===0 ? 'completed':(in_array('in_progress',$values,true) || in_array('completed',$values,true) ? 'in_progress':'planned'));
                        DB::query('UPDATE treatment_plans SET status=? WHERE id=?',[$overall,$i['plan_id']]); DB::audit('procedure_status_changed','treatment_plan_items',$id,$status);
                    });
                } else Clinic::plan($d); break;
            case 'payments': if ($action==='void') Clinic::voidPayment($d); else Clinic::payment($d); break;
            case 'inventory':
                if ($action==='item') { $id=DB::insert('inventory_items',['code'=>V::text($d,'code',40),'name'=>V::text($d,'name',150),'category'=>V::text($d,'category',100),'unit'=>V::text($d,'unit',40),'min_stock'=>V::cents($d,'min_stock')/100]); DB::audit('inventory_item_created','inventory_items',$id); }
                else Clinic::inventory($d); break;
            case 'users':
                if ($action==='role') {
                    DB::transaction(function() use($d) {
                        $r=V::id($d,'role_id'); $role=DB::one('SELECT * FROM roles WHERE id=? FOR UPDATE',[$r]); if (!$role || $role['name']==='Administrador') throw new \DomainException('Los permisos base del administrador están protegidos.');
                        DB::query('DELETE FROM role_permissions WHERE role_id=?',[$r]); foreach ((array)($d['permissions'] ?? []) as $p) { $p=V::id(['p'=>$p],'p'); DB::insert('role_permissions',['role_id'=>$r,'permission_id'=>$p]); } DB::audit('role_permissions_updated','roles',$r);
                    });
                } elseif ($action==='new_role') { $id=DB::insert('roles',['name'=>V::text($d,'name',80)]); DB::audit('role_created','roles',$id);
                } elseif ($action==='toggle') {
                    $id=V::id($d,'id'); if ($id==user()['id']) throw new \DomainException('No puedes desactivar tu propia cuenta.');
                    DB::query('UPDATE users SET active=1-active,session_version=session_version+1 WHERE id=?',[$id]); DB::query('DELETE FROM remember_tokens WHERE user_id=?',[$id]); DB::audit('user_activation_changed','users',$id);
                } else { $id=DB::insert('users',['role_id'=>V::id($d,'role_id'),'name'=>V::text($d,'name',150),'email'=>V::email($d),'password'=>V::password(V::text($d,'password',72))]); DB::audit('user_created','users',$id); } break;
            case 'settings':
                if ($action==='dentist') {
                    $start=V::text($d,'work_start',5); $end=V::text($d,'work_end',5); if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$end) || $start>=$end) throw new \DomainException('Horario del profesional no válido.');
                    $id=(int)($d['id'] ?? 0); $data=[V::text($d,'name',150),V::text($d,'specialty',150),$start,$end,isset($d['active']) ? 1:0];
                    if ($id) DB::query('UPDATE dentists SET name=?,specialty=?,work_start=?,work_end=?,active=? WHERE id=?',[...$data,$id]); else $id=DB::insert('dentists',array_combine(['name','specialty','work_start','work_end','active'],$data)); DB::audit('dentist_saved','dentists',$id);
                } elseif ($action==='demo_reset') {
                    if (!flag('APP_DEMO') || env('APP_ENV')==='production' || V::text($d,'confirmation')!=='RESTABLECER DEMO') throw new \DomainException('Escribe RESTABLECER DEMO para confirmar.');
                    require ROOT.'/scripts/reset-demo.php'; (require ROOT.'/database/seeds/DemoSeeder.php')(); \App\Services\Auth::logout(); flash('Demo restablecida. Inicia sesión con las credenciales demo.'); redirect('/login');
                } elseif ($action==='demo_seed') {
                    if (DB::one('SELECT id FROM patients LIMIT 1')) throw new \DomainException('La carga rápida solo está disponible sin pacientes.');
                    (require ROOT.'/database/seeds/DemoSeeder.php')();
                } else {
                    DB::transaction(function() use($d) { foreach (['clinic_address','clinic_phone','clinic_email','clinic_whatsapp','clinic_hours'] as $key) { $v=V::text($d,$key,500,false); if ($key==='clinic_email' && $v && !filter_var($v,FILTER_VALIDATE_EMAIL)) throw new \DomainException('Correo no válido.'); if ($key==='clinic_whatsapp' && $v && !preg_match('/^\d{8,15}$/D',$v)) throw new \DomainException('WhatsApp debe incluir código de país y solo dígitos.'); DB::query('INSERT INTO settings(name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$key,$v]); } DB::audit('settings_updated','settings'); });
                } break;
            case 'account':
                $u=DB::one('SELECT password FROM users WHERE id=?',[user()['id']]); if (!password_verify(V::text($d,'current_password',72),$u['password'])) throw new \DomainException('La contraseña actual no coincide.');
                $hash=V::password(V::text($d,'password',72)); DB::transaction(function() use($hash) { DB::query('UPDATE users SET password=?,session_version=session_version+1 WHERE id=?',[$hash,user()['id']]); DB::query('DELETE FROM remember_tokens WHERE user_id=?',[user()['id']]); DB::audit('password_changed','users',(int)user()['id']); }); \App\Services\Auth::logout(); flash('Contraseña actualizada. Inicia sesión nuevamente.'); redirect('/login');
            default: throw new \DomainException('Acción no disponible.');
        }
    }
    private static function data(string $module): array {
        $data=[];
        if (in_array($module,['appointments','treatments','clinical','odontogram'],true)) $data['patients']=DB::all('SELECT id,code,first_name,last_name FROM patients ORDER BY last_name,first_name');
        if (in_array($module,['appointments','treatments','settings','odontogram','clinical'],true)) $data['dentists']=DB::all('SELECT * FROM dentists ORDER BY name');
        if ($module==='orthodontics') {
            $id=(int)($_POST['case_id'] ?? $_GET['case_id'] ?? 0);
            $ortho=$id ? \App\Services\Orthodontics::detail($id):\App\Services\Orthodontics::listing($_GET);
            if ($id) {
                $ortho['assistant']=\App\Services\OrthodonticAssistant::state($id);
                $ortho['services']=DB::all('SELECT id,name,duration_minutes FROM services WHERE active=1 ORDER BY code="ORT" DESC,name');
                $ortho['unlinked_appointments']=DB::all('SELECT a.*,s.name service FROM appointments a JOIN services s ON s.id=a.service_id LEFT JOIN orth_case_appointments l ON l.appointment_id=a.id WHERE a.patient_id=? AND a.dentist_id=? AND l.appointment_id IS NULL ORDER BY a.starts_at DESC LIMIT 30',[$ortho['case']['patient_id'],$ortho['case']['dentist_id']]);
            }
            return ['ortho'=>$ortho];
        } elseif ($module==='dashboard') {
            return ['snapshot'=>\App\Services\Dashboard::snapshot($_GET)];
        } elseif ($module==='patients') {
            $q=mb_substr(trim((string)($_GET['q'] ?? '')),0,100); $status=in_array($_GET['status'] ?? '',['active','inactive'],true) ? $_GET['status']:''; $page=max(1,(int)($_GET['page'] ?? 1)); $search='%'.$q.'%';
            $where='(first_name LIKE ? OR last_name LIKE ? OR document LIKE ? OR code LIKE ?)'.($status ? ' AND status=?':''); $params=[$search,$search,$search,$search]; if ($status) $params[]=$status;
            $data['count']=(int)DB::query('SELECT COUNT(*) FROM patients WHERE '.$where,$params)->fetchColumn(); $page=min($page,max(1,(int)ceil($data['count']/12)));
            $data+=['q'=>$q,'status'=>$status,'page'=>$page,'rows'=>DB::all('SELECT * FROM patients WHERE '.$where.' ORDER BY id DESC LIMIT 12 OFFSET '.(($page-1)*12),$params),'patient'=>DB::one('SELECT * FROM patients WHERE id=?',[(int)($_GET['view'] ?? $_GET['edit'] ?? 0)])];
        } elseif ($module==='appointments') {
            $date=V::date($_GET['date'] ?? date('Y-m-d')); $mode=in_array($_GET['mode'] ?? '',['day','week','month'],true) ? $_GET['mode']:'week'; $local=new \DateTimeImmutable($date);
            $from=match($mode) {'day'=>$local,'month'=>$local->modify('first day of this month'),default=>$local->modify('monday this week')}; $to=match($mode) {'day'=>$from->modify('+1 day'),'month'=>$from->modify('+1 month'),default=>$from->modify('+7 days')};
            $params=[$from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]; $where='a.starts_at>=? AND a.starts_at<?';
            if (!empty($_GET['dentist'])) { $where.=' AND a.dentist_id=?'; $params[]=(int)$_GET['dentist']; }
            if (in_array($_GET['status'] ?? '',['pending','confirmed','attended','completed','cancelled','no_show'],true)) { $where.=' AND a.status=?'; $params[]=$_GET['status']; }
            $data+=['date'=>$date,'mode'=>$mode,'from'=>$from,'to'=>$to,'services'=>DB::all('SELECT * FROM services WHERE active=1'),'rows'=>DB::all('SELECT a.*,COALESCE(CONCAT(p.first_name," ",p.last_name),a.contact_name) patient,d.name dentist,s.name service FROM appointments a LEFT JOIN patients p ON p.id=a.patient_id LEFT JOIN dentists d ON d.id=a.dentist_id JOIN services s ON s.id=a.service_id WHERE '.$where.' ORDER BY starts_at',$params),'edit'=>DB::one('SELECT * FROM appointments WHERE id=?',[(int)($_GET['edit'] ?? 0)]),'requests'=>DB::all('SELECT a.*,s.name service FROM appointments a JOIN services s ON s.id=a.service_id WHERE a.source="public" AND a.status="pending" ORDER BY a.id DESC LIMIT 50')];
        } elseif ($module==='odontogram') {
            $patientId=(int)($_SERVER['REQUEST_METHOD']==='POST' ? ($_POST['patient_id'] ?? 0):($_GET['patient_id'] ?? 0));
            $version=$_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['version']) ? V::id($_GET,'version'):null;
            $data+=Clinic::odontogramState($patientId,$version);
            if ($data['record']) {
                DB::audit('clinical_viewed','clinical_records',(int)$data['record']['id']);
                $data['diagnosis_catalog']=DB::all('SELECT * FROM diagnosis_catalog WHERE active=1 ORDER BY category,sort_order');
                $data['diagnoses']=\App\Services\Treatment::patientDiagnoses((int)$data['record']['id']);
                $data['treatment_items']=DB::all('SELECT i.id,i.tooth_fdi,i.clinical_status,i.specialty,t.name treatment_name FROM treatment_plan_items i JOIN treatment_plans pl ON pl.id=i.plan_id JOIN treatments t ON t.id=i.treatment_id WHERE pl.patient_id=? ORDER BY i.id DESC',[$patientId]);
                $data['open_plans']=DB::all('SELECT id,name FROM treatment_plans WHERE patient_id=? AND status<>"cancelled" ORDER BY id DESC',[$patientId]);
                $data['catalog']=DB::all('SELECT id,name FROM treatments ORDER BY name');
            }
        } elseif ($module==='treatment') {
            $id=(int)($_GET['id'] ?? $_POST['item_id'] ?? 0);
            $detail=$id ? \App\Services\Treatment::detail($id) : null;
            $data['detail']=$detail;
            if ($detail) { DB::audit('clinical_viewed','treatment_plan_items',$id); $data['dentists']=DB::all('SELECT * FROM dentists WHERE active=1 ORDER BY name'); $data['prescriptions']=\App\Services\Prescription::forPatient((int)$detail['item']['patient_id']); }
        } elseif ($module==='prescription') {
            $data['detail']=\App\Services\Prescription::detail((int)($_GET['id'] ?? 0));
            if ($data['detail']) DB::audit('clinical_viewed','prescriptions',(int)$data['detail']['prescription']['id']);
        } elseif ($module==='clinical') {
            $p=(int)($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0); $record=DB::one('SELECT r.*,p.first_name,p.last_name,p.code FROM clinical_records r JOIN patients p ON p.id=r.patient_id WHERE patient_id=?',[$p]); $data['record']=$record;
            if ($record) {
                DB::audit('clinical_viewed','clinical_records',(int)$record['id']);
                $data['entries']=DB::all('SELECT e.*,u.name author FROM clinical_record_entries e JOIN users u ON u.id=e.user_id WHERE clinical_record_id=? ORDER BY e.id DESC',[$record['id']]);
                $data['versions']=DB::all('SELECT o.*,u.name author FROM odontograms o JOIN users u ON u.id=o.user_id WHERE clinical_record_id=? ORDER BY o.id DESC',[$record['id']]);
                $data['latest']=$data['versions'][0] ?? null; $version=(int)($_GET['version'] ?? ($data['latest']['id'] ?? 0));
                if ($version && !in_array($version,array_map('intval',array_column($data['versions'],'id')),true)) throw new \DomainException('Versión no disponible.');
                $data['version']=$version; $data['findings']=DB::all('SELECT tooth_fdi,surface,state,observation FROM odontogram_findings WHERE odontogram_id=?',[$version]);
                $data['attachments']=array_values(array_filter(DB::all('SELECT id,original_name,created_at FROM attachments WHERE clinical_record_id=?',[$record['id']]),static function(array $file): bool { try { \App\Services\Orthodontics::authorizeAttachment((int)$file['id']); return true; } catch (\DomainException) { return false; } }));
                $data['prescriptions']=\App\Services\Prescription::forPatient($p);
            }
        } elseif ($module==='treatments' || $module==='payments') {
            $data['plans']=DB::all('SELECT pl.*,CONCAT(p.first_name," ",p.last_name) patient,d.name dentist,COALESCE(t.total,0) total,COALESCE(pay.paid,0) paid FROM treatment_plans pl JOIN patients p ON p.id=pl.patient_id JOIN dentists d ON d.id=pl.dentist_id LEFT JOIN (SELECT plan_id,SUM(price) total FROM treatment_plan_items WHERE status<>"cancelled" GROUP BY plan_id) t ON t.plan_id=pl.id LEFT JOIN (SELECT plan_id,SUM(amount) paid FROM payments WHERE voided_at IS NULL GROUP BY plan_id) pay ON pay.plan_id=pl.id ORDER BY pl.id DESC');
            $data['catalog']=DB::all('SELECT * FROM treatments ORDER BY name');
            if ($module==='treatments') $data['items']=DB::all('SELECT i.*,t.name treatment,CONCAT(p.first_name," ",p.last_name) patient,pl.name plan FROM treatment_plan_items i JOIN treatments t ON t.id=i.treatment_id JOIN treatment_plans pl ON pl.id=i.plan_id JOIN patients p ON p.id=pl.patient_id ORDER BY i.id DESC');
            else $data['payments']=DB::all('SELECT pay.*,r.number,CONCAT(p.first_name," ",p.last_name) patient FROM payments pay JOIN payment_receipts r ON r.payment_id=pay.id JOIN treatment_plans pl ON pl.id=pay.plan_id JOIN patients p ON p.id=pl.patient_id ORDER BY pay.id DESC');
        } elseif ($module==='receipt') {
            $data['receipt']=DB::one('SELECT pay.*,r.number,pl.name plan,CONCAT(p.first_name," ",p.last_name) patient FROM payments pay JOIN payment_receipts r ON r.payment_id=pay.id JOIN treatment_plans pl ON pl.id=pay.plan_id JOIN patients p ON p.id=pl.patient_id WHERE pay.id=?',[(int)($_GET['id'] ?? 0)]);
        } elseif ($module==='inventory') {
            $data['rows']=DB::all('SELECT * FROM inventory_items ORDER BY stock<=min_stock DESC,name'); $data['lots']=DB::all('SELECT l.*,i.name FROM inventory_lots l JOIN inventory_items i ON i.id=l.item_id ORDER BY expires_on');
            $data['movements']=DB::all('SELECT m.*,i.name,l.lot,u.name author FROM inventory_movements m JOIN inventory_items i ON i.id=m.item_id JOIN inventory_lots l ON l.id=m.lot_id JOIN users u ON u.id=m.user_id ORDER BY m.id DESC LIMIT 100');
        } elseif ($module==='reports') {
            $data['type']=$_GET['type'] ?? 'appointments'; $data['from']=$_GET['from'] ?? date('Y-m-01'); $data['to']=$_GET['to'] ?? date('Y-m-d'); $data['report']=Reports::data($data['type'],$data['from'],$data['to']);
        } elseif ($module==='users') {
            $data['rows']=DB::all('SELECT u.id,u.name,u.email,u.active,u.created_at,r.name role FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.id'); $data['roles']=DB::all('SELECT * FROM roles'); $data['permissions']=DB::all('SELECT * FROM permissions'); $data['grants']=DB::all('SELECT * FROM role_permissions'); $data['activity']=DB::all('SELECT a.*,u.name author FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 50');
        }
        return $data;
    }
}
