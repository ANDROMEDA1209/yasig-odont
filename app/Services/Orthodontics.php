<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\DB;
use App\Validators\Input as V;

/** Longitudinal orthodontic records layered over the existing clinical and financial model. */
final class Orthodontics {
    public const STATES=[
        'evaluation'=>'Evaluación inicial','diagnosis'=>'Diagnóstico en preparación','planning'=>'Planificación',
        'pending_authorization'=>'Pendiente de autorización','active'=>'Tratamiento activo','followup'=>'En seguimiento',
        'completed'=>'Finalizado clínicamente','retention'=>'Retención','suspended'=>'Suspendido','cancelled'=>'Cancelado',
    ];
    public const TYPES=['metal'=>'Brackets metálicos','ceramic'=>'Brackets estéticos','aligners'=>'Alineadores transparentes','interceptive'=>'Ortodoncia interceptiva','removable'=>'Aparatología removible','other'=>'Otro tratamiento'];
    public const PHOTO_CATEGORIES=[
        'frontal_rest'=>'Frontal en reposo','frontal_smile'=>'Frontal sonriendo','profile'=>'Perfil facial',
        'intraoral_front'=>'Intraoral frontal','lateral_right'=>'Lateral derecha','lateral_left'=>'Lateral izquierda',
        'occlusal_upper'=>'Oclusal superior','occlusal_lower'=>'Oclusal inferior','additional'=>'Fotografías adicionales',
    ];
    private const DIAGNOSIS_FIELDS=[
        'chief_complaint'=>3000,'medical_history'=>10000,'dental_history'=>10000,'facial_evaluation'=>5000,
        'intraoral_evaluation'=>5000,'molar_relation'=>1000,'canine_relation'=>1000,'angle_class'=>100,
        'midline'=>1000,'bite'=>1000,'periodontal_evaluation'=>5000,'imaging'=>5000,
        'clinical_diagnosis'=>10000,'objectives'=>5000,'prognosis'=>3000,'observations'=>5000,
    ];

    public static function options(): array {
        $out=['states'=>self::STATES,'types'=>self::TYPES,'photo_categories'=>self::PHOTO_CATEGORIES,
            'appliances'=>['fixed'=>'Aparatología fija','aligners'=>'Alineadores','removable'=>'Aparatología removible','retainer'=>'Retenedor'],
            'consent_kinds'=>['treatment'=>'Tratamiento ortodóntico','photographs'=>'Fotografías clínicas','external_ai'=>'Procesamiento con IA externa']];
        $configured=json_decode(setting('orthodontics_options','{}'),true);
        // Configuration adds clinic-defined choices; core keys keep their clinical semantics.
        if (is_array($configured)) foreach(['states','types','photo_categories'] as $group) {
            if (!is_array($configured[$group] ?? null)) continue;
            foreach($configured[$group] as $key=>$label) if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D',(string)$key) && is_string($label) && mb_strlen($label)<=150 && trim($label)!=='') $out[$group][$key]=$label;
        }
        return $out;
    }

    private static function admin(): bool { return (user()['role_name'] ?? '')==='Administrador' || can('users'); }
    private static function clinician(): bool { return self::admin() || can('clinical'); }
    public static function canCreate(): bool { return can('orthodontics') && self::clinician(); }
    private static function demandModule(): void {
        if (!user() || !can('orthodontics')) throw new \DomainException('No tienes acceso al módulo de ortodoncia.',403);
    }
    private static function scope(string $alias='c'): array {
        self::demandModule();
        if (self::admin()) return ['1=1',[]];
        $owner='EXISTS(SELECT 1 FROM dentists od WHERE od.id='.$alias.'.dentist_id AND od.user_id=?)';
        if (!can('orthodontics_assist')) return [can('clinical') ? $owner:'0=1',can('clinical') ? [user()['id']]:[]];
        return ['('.(can('clinical') ? $owner:'0=1').' OR EXISTS(SELECT 1 FROM orth_case_members om WHERE om.case_id='.$alias.'.id AND om.user_id=? AND om.active=1))',can('clinical') ? [user()['id'],user()['id']]:[user()['id']]];
    }
    private static function caseSelect(): string {
        return 'SELECT c.*,r.patient_id,p.first_name,p.last_name,CONCAT(p.first_name," ",p.last_name) patient_name,p.code patient_code,p.document patient_document,p.birth_date,p.phone,p.email,d.name professional,d.name dentist,d.user_id dentist_user_id,i.plan_id,i.price,pl.name plan_name FROM orth_cases c JOIN clinical_records r ON r.id=c.clinical_record_id JOIN patients p ON p.id=r.patient_id JOIN dentists d ON d.id=c.dentist_id JOIN treatment_plan_items i ON i.id=c.treatment_plan_item_id JOIN treatment_plans pl ON pl.id=i.plan_id';
    }
    public static function assertCase(int $id,bool $write=false): array {
        [$scope,$params]=self::scope();
        $case=DB::one(self::caseSelect().' WHERE c.id=? AND '.$scope,[$id,...$params]);
        if (!$case) throw new \DomainException('Expediente no disponible para tu usuario.',403);
        $owner=(int)($case['dentist_user_id'] ?? 0)===(int)user()['id'];
        $canWrite=self::admin() || ($owner && can('clinical'));
        if ($write && !$canWrite) throw new \DomainException('La modificación clínica corresponde al profesional responsable.',403);
        $case['can_write']=$canWrite;
        $case['can_assist']=$canWrite || (can('orthodontics_assist') && (bool)DB::one('SELECT case_id FROM orth_case_members WHERE case_id=? AND user_id=? AND active=1',[$id,user()['id']]));
        if (!$canWrite && !$case['can_assist']) throw new \DomainException('No tienes una asignación autorizada en este expediente.',403);
        $case['treatment_type']=$case['type_key'];
        $case['age']=$case['birth_date'] ? (new \DateTimeImmutable($case['birth_date']))->diff(new \DateTimeImmutable('today'))->y : null;
        return $case;
    }
    public static function assertControl(int $caseId,int $controlId): array {
        self::assertCase($caseId);
        $control=DB::one('SELECT oc.*,s.treatment_plan_item_id FROM orth_controls oc JOIN treatment_sessions s ON s.id=oc.session_id WHERE oc.id=? AND oc.case_id=?',[$controlId,$caseId]);
        if (!$control) throw new \DomainException('El control no corresponde a este expediente.');
        return $control;
    }
    public static function authorizeAttachment(int $attachmentId): void {
        $link=DB::one('SELECT case_id FROM orth_attachments WHERE attachment_id=?',[$attachmentId]);
        if ($link) { self::assertCase((int)$link['case_id']); return; }
        // Files linked to the orthodontic financial item are protected even before a gallery tag exists.
        $case=DB::one('SELECT c.id FROM attachments a JOIN orth_cases c ON c.treatment_plan_item_id=a.treatment_plan_item_id WHERE a.id=?',[$attachmentId]);
        if ($case) self::assertCase((int)$case['id']);
    }
    public static function logEvent(int $caseId,string $type,?int $entityId=null,string $reason=''): void {
        DB::insert('orth_history',['case_id'=>$caseId,'user_id'=>user()['id'],'event_type'=>$type,'entity_id'=>$entityId,'reason'=>$reason]);
        DB::audit('orth_'.$type,'orth_cases',$caseId,$entityId ? 'reference:'.$entityId:'');
    }

    public static function listing(array $filters): array {
        [$scope,$params]=self::scope(); $where=[$scope];
        $f=['q'=>V::text($filters,'q',150,false),'status'=>(string)($filters['status'] ?? ''),'type_key'=>(string)($filters['type_key'] ?? $filters['treatment_type'] ?? ''),
            'dentist_id'=>(int)($filters['dentist_id'] ?? $filters['professional'] ?? 0),'from'=>(string)($filters['from'] ?? ''),'to'=>(string)($filters['to'] ?? ''),
            'sort'=>(string)($filters['sort'] ?? 'next_control'),'direction'=>strtolower((string)($filters['direction'] ?? 'asc'))==='desc' ? 'desc':'asc'];
        $options=self::options();
        if ($f['q']!=='') { $where[]='(CONCAT(p.first_name," ",p.last_name) LIKE ? OR p.document LIKE ? OR p.code LIKE ?)'; foreach(range(1,3) as $_) $params[]='%'.$f['q'].'%'; }
        if ($f['status']!=='') { if (!isset($options['states'][$f['status']])) throw new \DomainException('Estado no válido.'); $where[]='c.status=?'; $params[]=$f['status']; }
        if ($f['type_key']!=='') { if (!isset($options['types'][$f['type_key']])) throw new \DomainException('Tipo no válido.'); $where[]='c.type_key=?'; $params[]=$f['type_key']; }
        if ($f['dentist_id']) { $where[]='c.dentist_id=?'; $params[]=$f['dentist_id']; }
        if ($f['from']!=='') { V::date($f['from']); $where[]='c.start_date>=?'; $params[]=$f['from']; }
        if ($f['to']!=='') { V::date($f['to']); $where[]='c.start_date<=?'; $params[]=$f['to']; }
        if ($f['from']!=='' && $f['to']!=='' && $f['from']>$f['to']) throw new \DomainException('Revisa el período seleccionado.');
        $sorts=['patient_name'=>'patient_name','patient'=>'patient_name','name'=>'patient_name','recent'=>'c.created_at','start_date'=>'c.start_date','next_control'=>'c.next_control','status'=>'c.status','professional'=>'d.name'];
        if ($f['sort']==='recent' && !isset($filters['direction'])) $f['direction']='desc';
        $sort=$sorts[$f['sort']] ?? 'c.next_control';
        $rows=DB::all(self::caseSelect().' WHERE '.implode(' AND ',$where).' ORDER BY '.$sort.' '.$f['direction'].',c.id DESC',$params);
        $ids=array_map('intval',array_column($rows,'id')); $idSql=$ids ? implode(',',$ids):'0'; $today=date('Y-m-d');
        $controlRows=DB::all('SELECT oc.case_id,MAX(s.occurred_at) last_control FROM orth_controls oc JOIN treatment_sessions s ON s.id=oc.session_id WHERE oc.case_id IN ('.$idSql.') GROUP BY oc.case_id');
        $last=array_column($controlRows,'last_control','case_id');
        $tasks=DB::all('SELECT case_id,COUNT(*) n FROM orth_tasks WHERE status="pending" AND case_id IN ('.$idSql.') GROUP BY case_id'); $pendingTasks=array_column($tasks,'n','case_id');
        $stats=['active'=>0,'new_cases'=>0,'pending_controls'=>0,'today_appointments'=>0,'overdue_controls'=>0,'completed'=>0,'retention'=>0,'alerts'=>0];
        $groups=['months'=>[],'states'=>[],'types'=>[],'dentists'=>[],'duration'=>[]];
        foreach($rows as &$row) {
            $row['age']=$row['birth_date'] ? (new \DateTimeImmutable($row['birth_date']))->diff(new \DateTimeImmutable('today'))->y : null;
            $row['treatment_type']=$row['type_key']; $row['last_control']=$last[$row['id']] ?? null;
            $row['pending_controls']=$row['next_control'] && !$row['closed_at'] && $row['next_control']<=$today ? 1:0;
            $row['pending_tasks']=(int)($pendingTasks[$row['id']] ?? 0);
            if (in_array($row['status'],['active','followup'],true)) $stats['active']++;
            if ($f['from']!=='' || $f['to']!=='' || substr($row['start_date'],0,7)===date('Y-m')) $stats['new_cases']++;
            $stats['pending_controls']+=$row['pending_controls'];
            if ($row['next_control'] && !$row['closed_at'] && $row['next_control']<$today) $stats['overdue_controls']++;
            if ($row['status']==='completed') $stats['completed']++;
            if ($row['status']==='retention') $stats['retention']++;
            if ($row['pending_tasks']>0) $stats['alerts']++;
            $month=substr($row['start_date'],0,7); $groups['months'][$month]=($groups['months'][$month] ?? 0)+1;
            foreach(['states'=>'status','types'=>'type_key','dentists'=>'professional'] as $g=>$field) $groups[$g][$row[$field]]=($groups[$g][$row[$field]] ?? 0)+1;
            if ($row['completed_at'] && $row['estimated_months']) { $days=(new \DateTimeImmutable($row['start_date']))->diff(new \DateTimeImmutable($row['completed_at']))->days; $groups['duration'][]=['label'=>$row['patient_code'],'estimated'=>(int)$row['estimated_months'],'actual'=>round($days/30.4375,1),'value'=>round($days/30.4375,1)]; }
        } unset($row);
        $timezone=new \DateTimeZone('UTC'); $start=(new \DateTimeImmutable('today'))->setTimezone($timezone)->format('Y-m-d H:i:s'); $end=(new \DateTimeImmutable('tomorrow'))->setTimezone($timezone)->format('Y-m-d H:i:s');
        $stats['today_appointments']=(int)DB::query('SELECT COUNT(*) FROM orth_case_appointments ca JOIN appointments a ON a.id=ca.appointment_id WHERE ca.case_id IN ('.$idSql.') AND a.starts_at>=? AND a.starts_at<? AND a.status NOT IN ("cancelled","no_show")',[$start,$end])->fetchColumn();
        $appointmentStats=DB::all('SELECT a.status,COUNT(*) n FROM orth_case_appointments ca JOIN appointments a ON a.id=ca.appointment_id WHERE ca.case_id IN ('.$idSql.') GROUP BY a.status');
        $charts=[]; ksort($groups['months']);
        foreach(['months','states','types','dentists'] as $group) { $charts[$group]=[]; foreach($groups[$group] as $key=>$n) { $map=$group==='states' ? $options['states']:($group==='types' ? $options['types']:[]); $charts[$group][]=['label'=>$map[$key] ?? $key,'key'=>$key,'month'=>$key,'value'=>$n,'total'=>$n]; } }
        $charts['attendance']=[]; $attended=0; $missed=0;
        foreach($appointmentStats as $a) { $charts['attendance'][]=['label'=>label($a['status']),'key'=>$a['status'],'value'=>(int)$a['n']]; if (in_array($a['status'],['attended','completed'],true)) $attended+=(int)$a['n']; if ($a['status']==='no_show') $missed+=(int)$a['n']; }
        $charts['compliance']=$attended+$missed ? [['label'=>'Controles cumplidos','value'=>$attended],['label'=>'Inasistencias','value'=>$missed]]:[];
        $charts['duration']=$groups['duration'];
        $total=count($rows); $page=max(1,(int)($filters['page'] ?? 1)); $pages=max(1,(int)ceil($total/12)); $page=min($page,$pages);
        $dentists=self::admin() ? DB::all('SELECT id,name,user_id FROM dentists WHERE active=1 ORDER BY name'):DB::all('SELECT DISTINCT d.id,d.name,d.user_id FROM dentists d LEFT JOIN orth_cases c ON c.dentist_id=d.id WHERE d.user_id=? OR c.id IN ('.$idSql.') ORDER BY d.name',[user()['id']]);
        $patients=self::canCreate() ? DB::all('SELECT p.* FROM patients p WHERE p.status="active" ORDER BY p.last_name,p.first_name'):[];
        return ['case'=>null,'filters'=>$f,'stats'=>$stats,'metrics'=>$stats,'charts'=>$charts,'cases'=>array_slice($rows,($page-1)*12,12),'total'=>$total,'page'=>$page,'pages'=>$pages,'patients'=>$patients,'dentists'=>$dentists,'options'=>$options,'catalogs'=>$options,'can_create'=>self::canCreate(),'can_write'=>self::canCreate(),'can_assist'=>self::canCreate() || can('orthodontics_assist')];
    }

    public static function detail(int $caseId): array {
        $case=self::assertCase($caseId); $record=DB::one('SELECT r.*,p.first_name,p.last_name,p.code,p.document,p.birth_date,p.phone,p.email FROM clinical_records r JOIN patients p ON p.id=r.patient_id WHERE r.id=?',[$case['clinical_record_id']]);
        $diagnoses=DB::all('SELECT od.*,u.name author FROM orth_diagnoses od JOIN users u ON u.id=od.user_id WHERE od.case_id=? ORDER BY od.id DESC',[$caseId]);
        $plans=DB::all('SELECT op.*,u.name author,au.name authorized_by_name FROM orth_plan_versions op JOIN users u ON u.id=op.user_id LEFT JOIN users au ON au.id=op.authorized_by WHERE op.case_id=? ORDER BY op.id DESC',[$caseId]);
        foreach($diagnoses as &$row) $row['data']=json_decode($row['data_json'],true,512,JSON_THROW_ON_ERROR); unset($row);
        foreach($plans as &$row) $row['data']=json_decode($row['data_json'],true,512,JSON_THROW_ON_ERROR); unset($row);
        $controls=DB::all('SELECT oc.*,s.occurred_at,s.dentist_id,s.session_number,s.objective,s.findings observations,s.findings notes,s.procedures_done procedures,s.materials_used appliance_changes,s.incidents,s.instructions recommendations,s.instructions,s.user_id,s.created_at signed_at,d.name professional,u.name author FROM orth_controls oc JOIN treatment_sessions s ON s.id=oc.session_id JOIN dentists d ON d.id=s.dentist_id JOIN users u ON u.id=s.user_id WHERE oc.case_id=? ORDER BY s.occurred_at DESC,oc.id DESC',[$caseId]);
        $addendaByControl=[];
        foreach(DB::all('SELECT a.*,u.name author FROM orth_control_addenda a JOIN orth_controls oc ON oc.id=a.control_id JOIN users u ON u.id=a.user_id WHERE oc.case_id=? ORDER BY a.id',[$caseId]) as $addendum) $addendaByControl[$addendum['control_id']][]=$addendum;
        foreach($controls as &$row) $row['addenda']=$addendaByControl[$row['id']] ?? []; unset($row);
        $stages=DB::all('SELECT st.*,d.name professional FROM treatment_stages st LEFT JOIN dentists d ON d.id=st.professional_id WHERE st.treatment_plan_item_id=? ORDER BY st.sort_order,st.id',[$case['treatment_plan_item_id']]);
        $appointments=DB::all('SELECT a.*,s.name service,d.name professional,ca.case_id FROM orth_case_appointments ca JOIN appointments a ON a.id=ca.appointment_id JOIN services s ON s.id=a.service_id LEFT JOIN dentists d ON d.id=a.dentist_id WHERE ca.case_id=? ORDER BY a.starts_at DESC',[$caseId]);
        $attachments=DB::all('SELECT a.*,oa.id metadata_id,oa.case_id,oa.control_id,oa.photo_category,oa.phase,oa.description clinical_description,u.name author FROM orth_attachments oa JOIN attachments a ON a.id=oa.attachment_id JOIN users u ON u.id=a.user_id WHERE oa.case_id=? ORDER BY COALESCE(a.captured_at,DATE(a.created_at)) DESC,a.id DESC',[$caseId]);
        foreach($attachments as &$file) { $file['attachment_id']=$file['id']; $file['description']=$file['clinical_description'] ?: $file['description']; $file['url']=url('/admin/attachment?id='.$file['id'].'&inline=1'); $file['download_url']=url('/admin/attachment?id='.$file['id']); } unset($file);
        $photos=array_values(array_filter($attachments,fn($f)=>in_array($f['mime'],['image/jpeg','image/png'],true)));
        $documents=array_values(array_filter($attachments,fn($f)=>$f['mime']==='application/pdf'));
        $consents=DB::all('SELECT co.*,u.name author FROM orth_consents co JOIN users u ON u.id=co.user_id WHERE co.case_id=? ORDER BY co.id DESC',[$caseId]);
        $tasks=DB::all('SELECT t.*,u.name author,cu.name completed_by_name FROM orth_tasks t JOIN users u ON u.id=t.user_id LEFT JOIN users cu ON cu.id=t.completed_by WHERE t.case_id=? ORDER BY t.status,t.due_date,t.id DESC',[$caseId]);
        $history=DB::all('SELECT h.*,u.name author FROM orth_history h JOIN users u ON u.id=h.user_id WHERE h.case_id=? ORDER BY h.id DESC',[$caseId]);
        $members=DB::all('SELECT m.*,u.name,u.email,au.name assigned_by_name FROM orth_case_members m JOIN users u ON u.id=m.user_id JOIN users au ON au.id=m.assigned_by WHERE m.case_id=? ORDER BY m.active DESC,u.name',[$caseId]);
        $paid=(float)DB::query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE plan_id=? AND voided_at IS NULL',[$case['plan_id']])->fetchColumn();
        $total=(float)DB::query('SELECT COALESCE(SUM(price),0) FROM treatment_plan_items WHERE plan_id=? AND status<>"cancelled"',[$case['plan_id']])->fetchColumn();
        $options=self::options();
        $proposals=DB::all('SELECT ap.*,u.name author,ru.name reviewer FROM orth_assistant_proposals ap JOIN users u ON u.id=ap.user_id LEFT JOIN users ru ON ru.id=ap.reviewed_by WHERE ap.case_id=? ORDER BY ap.id DESC',[$caseId]);
        DB::audit('orth_case_viewed','orth_cases',$caseId);
        return ['case'=>$case,'patient'=>$record,'diagnoses'=>$diagnoses,'diagnosis'=>$diagnoses[0] ?? null,'plans'=>$plans,'plan'=>$plans[0] ?? null,
            'controls'=>$controls,'stages'=>$stages,'phases'=>$stages,'appointments'=>$appointments,'attachments'=>$attachments,'media'=>$attachments,'photos'=>$photos,'documents'=>$documents,
            'consents'=>$consents,'tasks'=>$tasks,'history'=>$history,'members'=>$members,'proposals'=>$proposals,'assistant_proposals'=>$proposals,
            'odontogram'=>Clinic::odontogramState((int)$case['patient_id']),'financial'=>['plan_id'=>$case['plan_id'],'total'=>$total,'paid'=>$paid,'balance'=>$total-$paid],
            'options'=>$options,'catalogs'=>$options,'can_write'=>$case['can_write'],'can_assist'=>$case['can_assist'],'can_create'=>self::canCreate(),
            'dentists'=>DB::all('SELECT id,name,user_id FROM dentists WHERE active=1 ORDER BY name'),
            'assignable_users'=>$case['can_write'] ? DB::all('SELECT DISTINCT u.id,u.name,u.email FROM users u JOIN role_permissions rp ON rp.role_id=u.role_id JOIN permissions p ON p.id=rp.permission_id WHERE u.active=1 AND p.name="orthodontics_assist" ORDER BY u.name'):[]];
    }

    public static function action(array $d): int {
        self::demandModule(); $action=V::text($d,'action',60);
        // Compatibility with the existing clinic's field names without changing its controllers.
        foreach(['treatment_type'=>'type_key','notes'=>'observations','instructions'=>'recommendations','duration_months'=>'estimated_months','criteria_confirmed'=>'confirm_completion'] as $from=>$to) if (!isset($d[$to]) && isset($d[$from])) $d[$to]=$d[$from];
        if (!isset($d['control_interval_days']) && !empty($d['control_interval_weeks'])) $d['control_interval_days']=(string)((int)$d['control_interval_weeks']*7);
        if ($action==='create_case') return self::createCase($d);
        $id=V::id($d,'case_id');
        $assistAction=in_array($action,['task','task_status'],true); $case=self::assertCase($id,!$assistAction);
        if ($assistAction && !$case['can_assist']) throw new \DomainException('No puedes realizar esta acción en el expediente.',403);
        return DB::transaction(function() use($d,$action,$id,$case) {
            $current=DB::one('SELECT * FROM orth_cases WHERE id=? FOR UPDATE',[$id]);
            if (isset($d['version']) && (int)$d['version']!==(int)$current['version']) throw new \DomainException('Otra persona actualizó este expediente. Recarga y revisa la versión actual.');
            $case=[...$case,...$current];
            switch($action) {
                case 'diagnosis': self::diagnosis($d,$case); break;
                case 'plan': self::plan($d,$case); break;
                case 'authorize_plan': self::authorizePlan($d,$case); break;
                case 'status': self::status($d,$case); break;
                case 'control': self::control($d,$case); break;
                case 'addendum': self::addendum($d,$case); break;
                case 'phase': case 'stage': self::stage($d,$case); break;
                case 'create_stage': case 'add_phase': self::createStage($d,$case); break;
                case 'consent': self::consent($d,$case); break;
                case 'task': self::task($d,$case); break;
                case 'task_status': self::taskStatus($d,$case); break;
                case 'member': self::member($d,$case); break;
                default: throw new \DomainException('Acción de ortodoncia no disponible.');
            }
            DB::query('UPDATE orth_cases SET version=version+1 WHERE id=?',[$id]);
            return $id;
        });
    }

    private static function positive(array $d,string $key,int $default,int $max): int {
        if (!isset($d[$key]) || $d[$key]==='') return $default;
        $n=V::id($d,$key); if ($n>$max) throw new \DomainException('Revisa el valor de '.$key.'.'); return $n;
    }
    private static function createCase(array $d): int {
        if (!self::canCreate()) throw new \DomainException('Solo un profesional autorizado puede abrir un expediente.',403);
        $patient=V::id($d,'patient_id'); $dentist=V::id($d,'dentist_id'); $options=self::options();
        $type=V::choice($d,'type_key',array_keys($options['types'])); $appliance=V::text($d,'appliance',200,false);
        $start=empty($d['start_date']) ? date('Y-m-d'):V::date($d['start_date']);
        $months=self::positive($d,'estimated_months',24,120); $interval=self::positive($d,'control_interval_days',30,730);
        $next=empty($d['next_control']) ? null:V::date($d['next_control']);
        return DB::transaction(function() use($d,$patient,$dentist,$type,$appliance,$start,$months,$interval,$next) {
            $record=DB::one('SELECT r.id,p.status FROM clinical_records r JOIN patients p ON p.id=r.patient_id WHERE r.patient_id=? FOR UPDATE',[$patient]);
            if (!$record || $record['status']!=='active') throw new \DomainException('Selecciona un paciente activo del registro institucional.');
            $professional=DB::one('SELECT id,user_id FROM dentists WHERE id=? AND active=1',[$dentist]);
            if (!$professional || (!self::admin() && (int)($professional['user_id'] ?? 0)!==(int)user()['id'])) throw new \DomainException('No puedes abrir un caso para ese profesional.',403);
            $existing=DB::one('SELECT id,closed_at FROM orth_cases WHERE clinical_record_id=? ORDER BY id DESC LIMIT 1',[$record['id']]);
            if ($existing && empty($d['confirm_new_episode'])) { self::assertCase((int)$existing['id']); return (int)$existing['id']; }
            if ($existing && !$existing['closed_at']) throw new \DomainException('El paciente ya tiene un expediente abierto. Continúa su seguimiento antes de iniciar otro.');
            $catalog=DB::one('SELECT id,price FROM treatments WHERE code="ORT"');
            if (!$catalog) { $tid=DB::insert('treatments',['code'=>'ORT','name'=>'Ortodoncia','description'=>'Atención ortodóntica individual','price'=>'0.00','duration_minutes'=>45]); $catalog=['id'=>$tid,'price'=>'0.00']; }
            $name=V::text($d,'name',150,false) ?: 'Plan de ortodoncia';
            $planId=DB::insert('treatment_plans',['patient_id'=>$patient,'dentist_id'=>$dentist,'name'=>$name]);
            $itemId=Clinic::planItem(['treatment_id'=>$catalog['id'],'specialty'=>'orthodontics','notes'=>'Expediente de ortodoncia vinculado.'],$planId);
            if (isset($d['price']) && $d['price']!=='') DB::query('UPDATE treatment_plan_items SET price=? WHERE id=?',[number_format(V::cents($d,'price')/100,2,'.',''),$itemId]);
            $id=DB::insert('orth_cases',['clinical_record_id'=>$record['id'],'dentist_id'=>$dentist,'treatment_plan_item_id'=>$itemId,'type_key'=>$type,'appliance'=>$appliance,'start_date'=>$start,'next_control'=>$next,'estimated_months'=>$months,'control_interval_days'=>$interval,'created_by'=>user()['id']]);
            self::logEvent($id,'case_created',$itemId);
            DB::audit('plan_created','treatment_plans',$planId);
            return $id;
        });
    }
    private static function latest(string $table,int $caseId): ?array { return DB::one('SELECT * FROM '.$table.' WHERE case_id=? ORDER BY id DESC LIMIT 1',[$caseId]); }
    private static function checkPrevious(array $d,?array $previous): void {
        if (isset($d['previous_id']) && (int)$d['previous_id']!==(int)($previous['id'] ?? 0)) throw new \DomainException('Existe una versión más reciente. Recarga antes de guardar.');
    }
    private static function diagnosis(array $d,array $case): void {
        $date=empty($d['evaluation_date']) ? date('Y-m-d'):V::date($d['evaluation_date']);
        if ($date>date('Y-m-d')) throw new \DomainException('La fecha de evaluación no puede ser futura.');
        $data=[]; foreach(self::DIAGNOSIS_FIELDS as $key=>$max) $data[$key]=V::text($d,$key,$max,false);
        foreach(['overbite_mm','overjet_mm','crowding_mm','spacing_mm'] as $key) {
            $value=V::text($d,$key,20,false);
            if ($value!=='' && (!preg_match('/^-?\d{1,2}(\.\d{1,2})?$/D',$value) || (float)$value < -50 || (float)$value > 50)) throw new \DomainException('Registra una medida válida en milímetros para '.$key.'.');
            $data[$key]=$value==='' ? null:(float)$value;
        }
        if (!array_filter($data,fn($v)=>$v!=='' && $v!==null)) throw new \DomainException('Registra al menos un hallazgo de la evaluación.');
        $previous=self::latest('orth_diagnoses',(int)$case['id']); self::checkPrevious($d,$previous);
        $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if ($previous && $previous['data_json']===$json && $previous['evaluation_date']===$date) return;
        $id=DB::insert('orth_diagnoses',['case_id'=>$case['id'],'previous_id'=>$previous['id'] ?? null,'evaluation_date'=>$date,'data_json'=>$json,'user_id'=>user()['id']]);
        self::logEvent((int)$case['id'],'diagnosis_recorded',$id);
    }
    private static function plan(array $d,array $case): void {
        $data=[]; foreach(['objectives'=>5000,'phases'=>10000,'procedures'=>10000,'completion_criteria'=>5000,'retention_plan'=>5000] as $key=>$max) $data[$key]=V::text($d,$key,$max,false);
        if (!array_filter($data)) throw new \DomainException('Registra objetivos o contenido para el plan.');
        $data['estimated_months']=self::positive($d,'estimated_months',(int)($case['estimated_months'] ?: 24),120);
        $data['control_interval_days']=self::positive($d,'control_interval_days',(int)$case['control_interval_days'],730);
        $data['estimated_start']=empty($d['estimated_start']) ? $case['start_date']:V::date($d['estimated_start']);
        $data['type_key']=empty($d['type_key']) ? $case['type_key']:V::choice($d,'type_key',array_keys(self::options()['types']));
        $data['appliance']=isset($d['appliance']) ? V::text($d,'appliance',200,false):$case['appliance'];
        $status=empty($d['status']) ? 'draft':V::choice($d,'status',['draft','authorized']);
        if ($status==='authorized' && empty($d['confirm_authorization'])) throw new \DomainException('Confirma la revisión profesional antes de autorizar el plan.');
        if ($status==='authorized' && ($data['objectives']==='' || $data['completion_criteria']==='')) throw new \DomainException('Un plan autorizado necesita objetivos y criterios clínicos de finalización.');
        $previous=self::latest('orth_plan_versions',(int)$case['id']); self::checkPrevious($d,$previous);
        $reason=V::text($d,'change_reason',1000,false); $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if ($previous && $previous['data_json']===$json && $previous['status']===$status) return;
        $id=DB::insert('orth_plan_versions',['case_id'=>$case['id'],'previous_id'=>$previous['id'] ?? null,'data_json'=>$json,'status'=>$status,'change_reason'=>$reason,'user_id'=>user()['id'],'authorized_by'=>$status==='authorized' ? user()['id']:null,'authorized_at'=>$status==='authorized' ? gmdate('Y-m-d H:i:s'):null]);
        if ($status==='authorized') self::applyAuthorizedPlan($case,$data);
        self::logEvent((int)$case['id'],'plan_'.$status,$id,$reason);
    }
    private static function authorizePlan(array $d,array $case): void {
        $id=V::id($d,'plan_id');
        $plan=DB::one('SELECT * FROM orth_plan_versions WHERE id=? AND case_id=? FOR UPDATE',[$id,$case['id']]);
        if (!$plan) throw new \DomainException('El plan no pertenece a este expediente.');
        $latest=self::latest('orth_plan_versions',(int)$case['id']);
        if ((int)$latest['id']!==$id) throw new \DomainException('Solo puedes autorizar la versión más reciente del plan.');
        if (empty($d['confirm_authorization'])) throw new \DomainException('Confirma la revisión y autorización profesional.');
        $data=json_decode($plan['data_json'],true,512,JSON_THROW_ON_ERROR);
        if (empty($data['objectives']) || empty($data['completion_criteria'])) throw new \DomainException('Completa objetivos y criterios clínicos antes de autorizar.');
        if ($plan['status']==='authorized') return;
        DB::query('UPDATE orth_plan_versions SET status="authorized",authorized_by=?,authorized_at=UTC_TIMESTAMP() WHERE id=?',[user()['id'],$id]);
        self::applyAuthorizedPlan($case,$data); self::logEvent((int)$case['id'],'plan_authorized',$id);
    }
    private static function applyAuthorizedPlan(array $case,array $data): void {
        DB::query('UPDATE orth_cases SET estimated_months=?,control_interval_days=?,type_key=?,appliance=?,completion_criteria=? WHERE id=?',[$data['estimated_months'],$data['control_interval_days'],$data['type_key'],$data['appliance'],$data['completion_criteria'],$case['id']]);
    }
    private static function status(array $d,array $case): void {
        $to=V::choice($d,'status',array_keys(self::options()['states'])); $reason=V::text($d,'reason',1000);
        $criteria=isset($d['completion_criteria']) ? V::text($d,'completion_criteria',5000,false):(string)$case['completion_criteria'];
        $retainer=isset($d['retainer_type']) ? V::text($d,'retainer_type',200,false):(string)$case['retainer_type'];
        $instructions=isset($d['retention_instructions']) ? V::text($d,'retention_instructions',5000,false):(string)$case['retention_instructions'];
        $before=['completion_criteria'=>(string)$case['completion_criteria'],'retainer_type'=>(string)$case['retainer_type'],'retention_instructions'=>(string)$case['retention_instructions']];
        $after=['completion_criteria'=>$criteria,'retainer_type'=>$retainer,'retention_instructions'=>$instructions];
        $stateChanged=$to!==$case['status']; $detailsChanged=$before!==$after;
        if (!$stateChanged && !$detailsChanged) return;
        if (in_array($to,['completed','retention'],true)) {
            if (!DB::one('SELECT id FROM orth_plan_versions WHERE case_id=? AND status="authorized"',[$case['id']])) throw new \DomainException('Autoriza un plan clínico antes de finalizar o iniciar retención.');
            if (empty($d['confirm_completion']) || trim($criteria)==='') throw new \DomainException('Confirma el cumplimiento de los criterios clínicos de finalización.');
        }
        if ($to==='retention' && ($retainer==='' || $instructions==='')) throw new \DomainException('Registra el retenedor y las indicaciones de retención.');
        $completedAt=in_array($to,['completed','retention'],true) ? ($case['completed_at'] ?: gmdate('Y-m-d H:i:s')):$case['completed_at'];
        $closedAt=in_array($to,['completed','cancelled'],true) ? ($case['closed_at'] ?: gmdate('Y-m-d H:i:s')):null;
        if (!$closedAt && DB::one('SELECT id FROM orth_cases WHERE clinical_record_id=? AND id<>? AND closed_at IS NULL',[$case['clinical_record_id'],$case['id']])) throw new \DomainException('Existe otro episodio abierto. Revisa su seguimiento antes de reabrir este caso.');
        DB::query('UPDATE orth_cases SET status=?,completed_at=?,closed_at=?,completion_criteria=?,retainer_type=?,retention_instructions=? WHERE id=?',[$to,$completedAt,$closedAt,$criteria,$retainer,$instructions,$case['id']]);
        if ($stateChanged) {
            DB::insert('orth_history',['case_id'=>$case['id'],'user_id'=>user()['id'],'event_type'=>'status_changed','from_value'=>$case['status'],'to_value'=>$to,'reason'=>$reason]);
            DB::audit('orth_status_changed','orth_cases',(int)$case['id'],$case['status'].' > '.$to);
            // Keep the existing treatment list's clinical column consistent; financial status is separate.
            $clinical=match($to) {'evaluation','diagnosis'=>'proposed','planning','pending_authorization'=>'pending_acceptance','active'=>'in_progress','followup','retention'=>'needs_followup','completed'=>'completed','suspended'=>'paused','cancelled'=>'cancelled',default=>null};
            $item=DB::one('SELECT clinical_status FROM treatment_plan_items WHERE id=? FOR UPDATE',[$case['treatment_plan_item_id']]);
            if ($clinical && $item['clinical_status']!==$clinical) {
                DB::query('UPDATE treatment_plan_items SET clinical_status=? WHERE id=?',[$clinical,$case['treatment_plan_item_id']]);
                DB::insert('treatment_status_history',['treatment_plan_item_id'=>$case['treatment_plan_item_id'],'field'=>'clinical_status','from_status'=>$item['clinical_status'],'to_status'=>$clinical,'reason'=>$reason,'user_id'=>user()['id']]);
                DB::audit('treatment_clinical_status_changed','treatment_plan_items',(int)$case['treatment_plan_item_id'],$item['clinical_status'].' > '.$clinical);
            }
        }
        if ($detailsChanged) self::logEvent((int)$case['id'],'status_details_changed',null,json_encode(['reason'=>$reason,'from_status'=>$case['status'],'to_status'=>$to,'previous'=>$before,'updated'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
    private static function control(array $d,array $case): void {
        if (empty($d['confirm_sign'])) throw new \DomainException('Confirma la firma profesional del control.');
        $date=V::date(V::text($d,'date',10)); $time=V::text($d,'time',5);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$time)) throw new \DomainException('Hora de control no válida.');
        $local=new \DateTimeImmutable($date.' '.$time);
        if ($local>new \DateTimeImmutable('now')) throw new \DomainException('Un control realizado no puede estar en el futuro.');
        if ($date<$case['start_date']) throw new \DomainException('El control no puede ser anterior al inicio del caso.');
        $observations=V::text($d,'observations',5000); $procedures=V::text($d,'procedures',5000,false);
        $changes=V::text($d,'appliance_changes',1000,false); $incidents=V::text($d,'incidents',2000,false); $recommendations=V::text($d,'recommendations',2000,false); $phase=V::text($d,'phase',100,false);
        $next=empty($d['next_control']) ? null:V::date($d['next_control']); if ($next && $next<=$date) throw new \DomainException('El próximo control debe ser posterior al control registrado.');
        $appointment=empty($d['appointment_id']) ? null:V::id($d,'appointment_id');
        if ($appointment && !DB::one('SELECT a.id FROM appointments a JOIN orth_case_appointments ca ON ca.appointment_id=a.id WHERE a.id=? AND a.patient_id=? AND a.dentist_id=? AND ca.case_id=?',[$appointment,$case['patient_id'],$case['dentist_id'],$case['id']])) throw new \DomainException('La cita no corresponde a este expediente y profesional.');
        $request=empty($d['request_key']) ? null:hash('sha256',$case['id'].'|'.V::text($d,'request_key',150));
        $fingerprint=hash('sha256',json_encode([$case['id'],$date,$time,$observations,$procedures,$changes,$incidents,$recommendations,$phase,$next,$appointment],JSON_THROW_ON_ERROR));
        if ($request && ($existing=DB::one('SELECT id,payload_hash FROM orth_controls WHERE request_key=?',[$request]))) {
            if (($existing['payload_hash'] ?? '')!==$fingerprint) throw new \DomainException('Este envío ya se utilizó para otro control. Recarga el formulario.');
            return;
        }
        $number=(int)DB::query('SELECT COALESCE(MAX(session_number),0)+1 FROM treatment_sessions WHERE treatment_plan_item_id=?',[$case['treatment_plan_item_id']])->fetchColumn();
        $occurredAt=$local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $sessionId=DB::insert('treatment_sessions',['treatment_plan_item_id'=>$case['treatment_plan_item_id'],'session_number'=>$number,'occurred_at'=>$occurredAt,'dentist_id'=>$case['dentist_id'],'objective'=>$phase,'findings'=>$observations,'procedures_done'=>$procedures,'materials_used'=>$changes,'incidents'=>$incidents,'instructions'=>$recommendations,'user_id'=>user()['id']]);
        $id=DB::insert('orth_controls',['case_id'=>$case['id'],'session_id'=>$sessionId,'phase'=>$phase,'next_control'=>$next,'appointment_id'=>$appointment,'request_key'=>$request,'payload_hash'=>$fingerprint]);
        // Backfilled visits do not overwrite a more recent visit's follow-up date.
        $newer=DB::one('SELECT oc.id FROM orth_controls oc JOIN treatment_sessions s ON s.id=oc.session_id WHERE oc.case_id=? AND s.occurred_at>? LIMIT 1',[$case['id'],$occurredAt]);
        if (!$newer) DB::query('UPDATE orth_cases SET next_control=? WHERE id=?',[$next,$case['id']]);
        self::logEvent((int)$case['id'],'control_recorded',$id);
        if ($incidents!=='') { $tid=DB::insert('orth_tasks',['case_id'=>$case['id'],'title'=>'Revisar incidencia del control #'.$number,'due_date'=>$date,'priority'=>'high','user_id'=>user()['id']]); self::logEvent((int)$case['id'],'task_created',$tid); }
    }
    private static function addendum(array $d,array $case): void {
        $control=V::id($d,'control_id');
        if (!DB::one('SELECT id FROM orth_controls WHERE id=? AND case_id=?',[$control,$case['id']])) throw new \DomainException('El control no corresponde a este expediente.');
        $notes=V::text($d,'notes',5000); $reason=V::text($d,'reason',1000);
        $id=DB::insert('orth_control_addenda',['control_id'=>$control,'notes'=>$notes,'reason'=>$reason,'user_id'=>user()['id']]); self::logEvent((int)$case['id'],'control_addendum',$id);
    }
    private static function stage(array $d,array $case): void {
        $id=V::id($d,isset($d['stage_id']) ? 'stage_id':'id'); $status=V::choice($d,'status',['pending','in_progress','completed','not_applicable','needs_review']);
        $stage=DB::one('SELECT * FROM treatment_stages WHERE id=? AND treatment_plan_item_id=? FOR UPDATE',[$id,$case['treatment_plan_item_id']]);
        if (!$stage) throw new \DomainException('La fase no pertenece a este expediente.');
        $notes=V::text($d,'notes',5000,false); $started=$stage['started_at'];
        if (!$started && in_array($status,['in_progress','completed','needs_review'],true)) $started=gmdate('Y-m-d H:i:s');
        DB::query('UPDATE treatment_stages SET status=?,notes=?,professional_id=?,started_at=?,completed_at=? WHERE id=?',[$status,$notes,$case['dentist_id'],$started,$status==='completed' ? gmdate('Y-m-d H:i:s'):null,$id]);
        // Store the former note in the clinical history as well as the current phase snapshot.
        self::logEvent((int)$case['id'],'phase_changed',$id,json_encode(['from'=>$stage['status'],'to'=>$status,'previous_notes'=>$stage['notes'],'notes'=>$notes],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
    private static function createStage(array $d,array $case): void {
        $name=V::text($d,'stage_name',150); $notes=V::text($d,'notes',5000,false);
        $order=(int)DB::query('SELECT COALESCE(MAX(sort_order),0)+1 FROM treatment_stages WHERE treatment_plan_item_id=?',[$case['treatment_plan_item_id']])->fetchColumn();
        if ($order>500) throw new \DomainException('Revisa las fases existentes antes de añadir más.');
        $id=DB::insert('treatment_stages',['treatment_plan_item_id'=>$case['treatment_plan_item_id'],'stage_key'=>'custom_'.bin2hex(random_bytes(8)),'stage_name'=>$name,'sort_order'=>$order,'notes'=>$notes]);
        self::logEvent((int)$case['id'],'phase_created',$id,$name);
    }
    private static function consent(array $d,array $case): void {
        $kind=V::choice($d,'kind',array_keys(self::options()['consent_kinds'])); $status=V::choice($d,'status',['granted','revoked']);
        $date=empty($d['signed_on']) ? date('Y-m-d'):V::date($d['signed_on']); if ($date>date('Y-m-d')) throw new \DomainException('La fecha del consentimiento no puede ser futura.');
        $attachment=empty($d['attachment_id']) ? null:V::id($d,'attachment_id');
        if ($attachment && !DB::one('SELECT attachment_id FROM orth_attachments WHERE attachment_id=? AND case_id=?',[$attachment,$case['id']])) throw new \DomainException('El documento no pertenece a este expediente.');
        $id=DB::insert('orth_consents',['case_id'=>$case['id'],'kind'=>$kind,'status'=>$status,'signed_on'=>$date,'notes'=>V::text($d,'notes',5000,false),'attachment_id'=>$attachment,'user_id'=>user()['id']]); self::logEvent((int)$case['id'],'consent_'.$status,$id);
    }
    private static function task(array $d,array $case): void {
        $id=DB::insert('orth_tasks',['case_id'=>$case['id'],'title'=>V::text($d,'title',250),'due_date'=>empty($d['due_date']) ? null:V::date($d['due_date']),'priority'=>empty($d['priority']) ? 'normal':V::choice($d,'priority',['low','normal','high','urgent']),'user_id'=>user()['id']]); self::logEvent((int)$case['id'],'task_created',$id);
    }
    private static function taskStatus(array $d,array $case): void {
        $id=V::id($d,'task_id'); $status=V::choice($d,'status',['pending','completed']);
        if (!DB::one('SELECT id FROM orth_tasks WHERE id=? AND case_id=? FOR UPDATE',[$id,$case['id']])) throw new \DomainException('La tarea no corresponde a este expediente.');
        DB::query('UPDATE orth_tasks SET status=?,completed_by=?,completed_at=? WHERE id=?',[$status,$status==='completed' ? user()['id']:null,$status==='completed' ? gmdate('Y-m-d H:i:s'):null,$id]); self::logEvent((int)$case['id'],'task_'.$status,$id);
    }
    private static function member(array $d,array $case): void {
        $id=V::id($d,'user_id'); $active=empty($d['active']) ? 0:1;
        if (!DB::one('SELECT u.id FROM users u JOIN role_permissions rp ON rp.role_id=u.role_id JOIN permissions p ON p.id=rp.permission_id WHERE u.id=? AND u.active=1 AND p.name="orthodontics_assist"',[$id])) throw new \DomainException('Selecciona un asistente autorizado.');
        DB::query('INSERT INTO orth_case_members(case_id,user_id,assigned_by,active) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE active=VALUES(active),assigned_by=VALUES(assigned_by)',[$case['id'],$id,user()['id'],$active]); self::logEvent((int)$case['id'],$active ? 'member_assigned':'member_revoked',$id);
    }
}
