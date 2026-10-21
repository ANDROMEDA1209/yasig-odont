<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\DB;
use App\Validators\Input as V;

final class Dashboard {
    public static function filters(array $input): array {
        $from=V::date((string)($input['from'] ?? date('Y-m-d',strtotime('-29 days'))));
        $to=V::date((string)($input['to'] ?? date('Y-m-d')));
        if ($from>$to || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days>366) throw new \DomainException('Selecciona un período de hasta 366 días, con el inicio anterior al fin.');
        $f=['from'=>$from,'to'=>$to,'patient'=>(int)($input['patient'] ?? 0),'q'=>V::text($input,'q',100,false)];
        foreach (['finance_type'=>['','payment','void','balance','charge'],'finance_status'=>['','registered','voided','outstanding','settled','completed'],'event_type'=>['','appointment','clinical','odontogram','treatment'],'event_status'=>['','pending','confirmed','attended','completed','cancelled','no_show','signed','saved'],'sort'=>['recent','oldest','amount'],'metric'=>['income','payments','appointments','treatments']] as $key=>$values) {
            $value=(string)($input[$key] ?? ($key==='sort' ? 'recent':($key==='metric' ? 'income':'')));
            $f[$key]=$value===''&&in_array('',$values,true) ? '':V::choice([$key=>$value],$key,$values);
        }
        foreach (['finance_page','event_page'] as $key) $f[$key]=max(1,min(100000,(int)($input[$key] ?? 1)));
        return $f;
    }
    public static function allowed(): array { return ['finance'=>can('payments')||can('reports'),'clinical'=>can('clinical'),'appointments'=>can('appointments'),'treatments'=>can('treatments')||can('clinical'),'patients'=>can('patients')||can('clinical')||can('payments')||can('reports')]; }
    private static function bounds(array $f): array { $tz=new \DateTimeZone('UTC');return [(new \DateTimeImmutable($f['from']))->setTimezone($tz)->format('Y-m-d H:i:s'),(new \DateTimeImmutable($f['to']))->modify('+1 day')->setTimezone($tz)->format('Y-m-d H:i:s')]; }
    private static function balanceSQL(): string { return 'SELECT pl.id,pl.patient_id,pl.name,pl.created_at,COALESCE(i.total,0) total,COALESCE(pay.paid,0) paid,COALESCE(i.total,0)-COALESCE(pay.paid,0) balance FROM treatment_plans pl LEFT JOIN (SELECT plan_id,SUM(price) total FROM treatment_plan_items WHERE status<>"cancelled" GROUP BY plan_id) i ON i.plan_id=pl.id LEFT JOIN (SELECT plan_id,SUM(amount) paid FROM payments WHERE voided_at IS NULL GROUP BY plan_id) pay ON pay.plan_id=pl.id WHERE pl.status<>"cancelled"'; }
    private static function financeSQL(): string {
        $patient='CONCAT(p.first_name," ",p.last_name)';
        return 'SELECT CONCAT("payment:",pay.id) row_key,"payment" type,p.id patient_id,'.$patient.' patient,r.number concept,pay.amount amount,IF(pay.voided_at IS NULL,"registered","voided") status,pay.created_at occurred_at,u.name author FROM payments pay JOIN treatment_plans pl ON pl.id=pay.plan_id JOIN patients p ON p.id=pl.patient_id JOIN payment_receipts r ON r.payment_id=pay.id JOIN users u ON u.id=pay.user_id
        UNION ALL SELECT CONCAT("void:",pay.id),"void",p.id,'.$patient.',CONCAT("Anulación · ",r.number),-pay.amount,"voided",pay.voided_at,u.name FROM payments pay JOIN treatment_plans pl ON pl.id=pay.plan_id JOIN patients p ON p.id=pl.patient_id JOIN payment_receipts r ON r.payment_id=pay.id JOIN users u ON u.id=pay.voided_by WHERE pay.voided_at IS NOT NULL
        UNION ALL SELECT CONCAT("balance:",b.id),"balance",p.id,'.$patient.',b.name,b.balance,IF(b.balance>0,"outstanding","settled"),b.created_at,"No aplica · saldo calculado" FROM ('.self::balanceSQL().') b JOIN patients p ON p.id=b.patient_id
        UNION ALL SELECT CONCAT("charge:",i.id),"charge",p.id,'.$patient.',t.name,i.price,"completed",i.completed_at,d.name FROM treatment_plan_items i JOIN treatment_plans pl ON pl.id=i.plan_id JOIN patients p ON p.id=pl.patient_id JOIN treatments t ON t.id=i.treatment_id JOIN dentists d ON d.id=pl.dentist_id WHERE i.status="completed"';
    }
    private static function eventsSQL(array $allowed): string {
        $parts=[];$patient='CONCAT(p.first_name," ",p.last_name)';
        if ($allowed['appointments']) {
            $parts[]='SELECT CONCAT("appointment:",a.id) row_key,"appointment" type,p.id patient_id,COALESCE('.$patient.',a.contact_name) patient,CONCAT("Cita · ",s.name) concept,NULL amount,a.status,a.created_at occurred_at,COALESCE((SELECT u.name FROM audit_logs au JOIN users u ON u.id=au.user_id WHERE au.entity="appointments" AND au.entity_id=a.id ORDER BY au.id LIMIT 1),"Sin autor registrado") author FROM appointments a LEFT JOIN patients p ON p.id=a.patient_id JOIN services s ON s.id=a.service_id';
            $parts[]='SELECT CONCAT("event:",au.id),"appointment",p.id,COALESCE('.$patient.',a.contact_name),CASE au.action WHEN "appointment_rescheduled" THEN "Cita reprogramada" WHEN "appointment_cancelled" THEN "Cita cancelada" WHEN "appointment_attended" THEN "Asistencia registrada" WHEN "appointment_completed" THEN "Cita completada" ELSE "Estado de cita actualizado" END,NULL,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(au.detail),au.detail,"{}"),"$.after.status")),a.status),au.created_at,u.name FROM audit_logs au JOIN appointments a ON a.id=au.entity_id LEFT JOIN patients p ON p.id=a.patient_id JOIN users u ON u.id=au.user_id WHERE au.entity="appointments" AND au.action IN ("appointment_rescheduled","appointment_cancelled","appointment_attended","appointment_completed","appointment_status_changed")';
        }
        if ($allowed['clinical']) {
            $parts[]='SELECT CONCAT("clinical:",e.id),"clinical",p.id,'.$patient.',IF(e.correction_of IS NULL,"Evolución clínica firmada","Corrección clínica firmada"),NULL,"signed",e.created_at,u.name FROM clinical_record_entries e JOIN clinical_records r ON r.id=e.clinical_record_id JOIN patients p ON p.id=r.patient_id JOIN users u ON u.id=e.user_id';
            $parts[]='SELECT CONCAT("odontogram:",o.id),"odontogram",p.id,'.$patient.',IF(o.previous_id IS NULL,"Primer odontograma","Odontograma actualizado"),NULL,"saved",o.created_at,u.name FROM odontograms o JOIN clinical_records r ON r.id=o.clinical_record_id JOIN patients p ON p.id=r.patient_id JOIN users u ON u.id=o.user_id';
        }
        if ($allowed['treatments']) $parts[]='SELECT CONCAT("treatment:",i.id),"treatment",p.id,'.$patient.',CONCAT("Realizado · ",t.name),NULL,i.status,i.completed_at,d.name FROM treatment_plan_items i JOIN treatment_plans pl ON pl.id=i.plan_id JOIN patients p ON p.id=pl.patient_id JOIN treatments t ON t.id=i.treatment_id JOIN dentists d ON d.id=pl.dentist_id WHERE i.status="completed"';
        return implode(' UNION ALL ',$parts);
    }
    private static function feed(string $sql,array $f,string $stream): array {
        if (!$sql) return ['rows'=>[],'count'=>0,'page'=>1,'pages'=>1];
        [$start,$end]=self::bounds($f);$where='occurred_at>=? AND occurred_at<?';$params=[$start,$end];
        if ($f['patient']) {$where.=' AND patient_id=?';$params[]=$f['patient'];}
        if ($f['q']!=='') {$where.=' AND (patient LIKE ? OR concept LIKE ? OR author LIKE ?)';for($i=0;$i<3;$i++)$params[]='%'.$f['q'].'%';}
        foreach (['type','status'] as $field) if ($f[$stream.'_'.$field]!=='') {$where.=' AND '.$field.'=?';$params[]=$f[$stream.'_'.$field];}
        $count=(int)DB::query('SELECT COUNT(*) FROM ('.$sql.') movements WHERE '.$where,$params)->fetchColumn();$pages=max(1,(int)ceil($count/6));$page=min($pages,$f[$stream.'_page']);
        $order=$f['sort']==='oldest' ? 'occurred_at ASC,row_key ASC':($f['sort']==='amount' && $stream==='finance' ? 'amount DESC,occurred_at DESC,row_key DESC':'occurred_at DESC,row_key DESC');
        $rows=DB::all('SELECT * FROM ('.$sql.') movements WHERE '.$where.' ORDER BY '.$order.' LIMIT 6 OFFSET '.(($page-1)*6),$params);
        return compact('rows','count','page','pages');
    }
    public static function snapshot(array $input): array {
        $f=self::filters($input);$allowed=self::allowed();
        if (!$allowed['finance'] && in_array($f['metric'],['income','payments'],true)) $f['metric']=$allowed['appointments'] ? 'appointments':'treatments';
        if (!$allowed['appointments'] && $f['metric']==='appointments') $f['metric']=$allowed['finance'] ? 'income':'treatments';
        if (!$allowed['treatments'] && $f['metric']==='treatments') $f['metric']=$allowed['finance'] ? 'income':'appointments';
        // All sections read one consistent InnoDB snapshot, including totals and feeds.
        DB::connection()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return DB::transaction(function() use($f,$allowed) {
            [$start,$end]=self::bounds($f);$patient=$f['patient'] ? ' AND patient_id='.(int)$f['patient']:'';
            $stats=['patients'=>(int)DB::query('SELECT COUNT(*) FROM patients WHERE status="active" AND created_at>=? AND created_at<?'.($f['patient'] ? ' AND id='.(int)$f['patient']:''),[$start,$end])->fetchColumn()];
            if ($allowed['appointments']) $stats['appointments']=(int)DB::query('SELECT COUNT(*) FROM appointments WHERE starts_at>=? AND starts_at<?'.$patient,[$start,$end])->fetchColumn();
            if ($allowed['treatments']) $stats['treatments']=(int)DB::query('SELECT COUNT(*) FROM treatment_plan_items i JOIN treatment_plans pl ON pl.id=i.plan_id WHERE i.status="completed" AND i.completed_at>=? AND i.completed_at<?'.($f['patient'] ? ' AND pl.patient_id='.(int)$f['patient']:''),[$start,$end])->fetchColumn();
            $totals=['income'=>0,'payments'=>0,'voided'=>0,'balance'=>0];
            if ($allowed['finance']) {
                $r=DB::one('SELECT COALESCE(SUM(pay.amount),0) income,COUNT(*) payments FROM payments pay JOIN treatment_plans pl ON pl.id=pay.plan_id WHERE pay.voided_at IS NULL AND pay.created_at>=? AND pay.created_at<?'.($f['patient'] ? ' AND pl.patient_id='.(int)$f['patient']:''),[$start,$end]);$totals['income']=(float)$r['income'];$totals['payments']=(int)$r['payments'];$stats['income']=$totals['income'];
                $totals['voided']=(float)DB::query('SELECT COALESCE(SUM(pay.amount),0) FROM payments pay JOIN treatment_plans pl ON pl.id=pay.plan_id WHERE pay.voided_at>=? AND pay.voided_at<?'.($f['patient'] ? ' AND pl.patient_id='.(int)$f['patient']:''),[$start,$end])->fetchColumn();
                $totals['balance']=(float)DB::query('SELECT COALESCE(SUM(balance),0) FROM ('.self::balanceSQL().') balances WHERE created_at>=? AND created_at<?'.$patient,[$start,$end])->fetchColumn();
            }
            $offset=(new \DateTimeImmutable())->format('P');$series=[];
            $seriesQueries=[];
            if ($allowed['finance']) $seriesQueries['finance']=['SELECT DATE(CONVERT_TZ(pay.created_at,"+00:00",?)) day,SUM(pay.amount) income,COUNT(*) payments FROM payments pay JOIN treatment_plans pl ON pl.id=pay.plan_id WHERE pay.voided_at IS NULL AND pay.created_at>=? AND pay.created_at<?'.($f['patient'] ? ' AND pl.patient_id='.(int)$f['patient']:'').' GROUP BY day',[$offset,$start,$end]];
            if ($allowed['appointments']) $seriesQueries['appointments']=['SELECT DATE(CONVERT_TZ(starts_at,"+00:00",?)) day,COUNT(*) appointments FROM appointments WHERE starts_at>=? AND starts_at<?'.$patient.' GROUP BY day',[$offset,$start,$end]];
            if ($allowed['treatments']) $seriesQueries['treatments']=['SELECT DATE(CONVERT_TZ(i.completed_at,"+00:00",?)) day,COUNT(*) treatments FROM treatment_plan_items i JOIN treatment_plans pl ON pl.id=i.plan_id WHERE i.status="completed" AND i.completed_at>=? AND i.completed_at<?'.($f['patient'] ? ' AND pl.patient_id='.(int)$f['patient']:'').' GROUP BY day',[$offset,$start,$end]];
            foreach($seriesQueries as [$sql,$params]) foreach(DB::all($sql,$params) as $r) $series[$r['day']]=array_merge($series[$r['day']] ?? [],$r);
            $chart=[];for($day=new \DateTimeImmutable($f['from']);$day->format('Y-m-d')<=$f['to'];$day=$day->modify('+1 day')) {$key=$day->format('Y-m-d');$chart[]=['day'=>$key,'income'=>(float)($series[$key]['income'] ?? 0),'payments'=>(int)($series[$key]['payments'] ?? 0),'appointments'=>(int)($series[$key]['appointments'] ?? 0),'treatments'=>(int)($series[$key]['treatments'] ?? 0)];}
            $upcoming=$allowed['appointments'] ? DB::all('SELECT a.id,a.starts_at,a.status,COALESCE(CONCAT(p.first_name," ",p.last_name),a.contact_name) patient,s.name service,d.name dentist FROM appointments a LEFT JOIN patients p ON p.id=a.patient_id JOIN services s ON s.id=a.service_id LEFT JOIN dentists d ON d.id=a.dentist_id WHERE a.starts_at>=GREATEST(?,UTC_TIMESTAMP()) AND a.starts_at<? AND a.status IN ("pending","confirmed","attended")'.($f['patient'] ? ' AND a.patient_id='.(int)$f['patient']:'').' ORDER BY a.starts_at LIMIT 5',[$start,$end]):[];
            $distribution=$allowed['appointments'] ? DB::all('SELECT status,COUNT(*) total FROM appointments WHERE starts_at>=? AND starts_at<?'.$patient.' GROUP BY status',[$start,$end]):[];
            $alerts=[];
            if($allowed['appointments']) {$n=(int)DB::query('SELECT COUNT(*) FROM appointments WHERE status="pending" AND starts_at>=? AND starts_at<?'.$patient,[$start,$end])->fetchColumn();if($n)$alerts[]=['title'=>$n.' citas por confirmar','description'=>'Solicitudes del período que necesitan revisión.','url'=>'/admin/appointments?date='.$f['from'].'&mode=month&status=pending','icon'=>'calendar'];}
            if(can('inventory')) {$n=(int)DB::query('SELECT COUNT(*) FROM inventory_items WHERE stock<=min_stock')->fetchColumn();if($n)$alerts[]=['title'=>$n.' materiales con stock bajo','description'=>'Inventario actual · revisa existencias y lotes.','url'=>'/admin/inventory','icon'=>'box'];}
            if($allowed['finance'] && $totals['balance']>0)$alerts[]=['title'=>money($totals['balance']).' por cobrar','description'=>'Saldo actual de los planes creados en el período.','url'=>can('payments') ? '/admin/payments':'/admin/reports?type=balances&from='.$f['from'].'&to='.$f['to'],'icon'=>'wallet'];
            $finance=$allowed['finance'] ? self::feed(self::financeSQL(),$f,'finance'):['rows'=>[],'count'=>0,'page'=>1,'pages'=>1];
            $events=self::feed(self::eventsSQL($allowed),$f,'event');
            $patients=$allowed['patients']||$allowed['appointments'] ? DB::all('SELECT id,first_name,last_name,code FROM patients ORDER BY last_name,first_name'):[];
            return compact('f','allowed','stats','totals','chart','distribution','upcoming','alerts','finance','events','patients');
        });
    }
    public static function detail(string $key): array {
        if(!preg_match('/^(payment|void|balance|charge|appointment|event|clinical|odontogram|treatment):(\d+)$/D',$key,$m))throw new \DomainException('Movimiento no válido.');
        [$unused,$type,$id]=$m;$allowed=self::allowed();$finance=in_array($type,['payment','void','balance','charge'],true);
        if(($finance&&!$allowed['finance'])||(!$finance && (($type==='appointment'||$type==='event') ? !$allowed['appointments']:($type==='treatment' ? !$allowed['treatments']:!$allowed['clinical']))))throw new \DomainException('Tu cuenta no tiene acceso a este movimiento.',403);
        $sql=$finance ? self::financeSQL():self::eventsSQL($allowed);$row=DB::one('SELECT * FROM ('.$sql.') movements WHERE row_key=?',[$key]);if(!$row)throw new \DomainException('Movimiento no disponible.');
        $fields=['Paciente'=>$row['patient'] ?: 'Solicitud sin paciente registrado','Concepto'=>$row['concept'],'Estado'=>self::status($row['status']),'Responsable'=>$row['author'],'Fecha'=>local_date($row['occurred_at'])];$link=null;
        if($finance)$fields['Valor']=money($row['amount']);
        if($type==='payment'||$type==='void') {$p=DB::one('SELECT method,reference,void_reason,voided_at FROM payments WHERE id=?',[$id]);$fields['Método']=label($p['method']);$fields['Referencia']=$p['reference'] ?: 'Sin referencia';if($p['voided_at'])$fields['Motivo de anulación']=$p['void_reason'];$fields['Alcance']='Comprobante interno. Una anulación contable no representa una devolución bancaria.';if(can('payments'))$link='/admin/receipt?id='.$id;}
        elseif($type==='balance') {$p=DB::one('SELECT * FROM ('.self::balanceSQL().') b WHERE id=?',[$id]);$fields['Presupuesto']=money($p['total']);$fields['Pagado']=money($p['paid']);$fields['Alcance']='Saldo actual; no es un ingreso ni un movimiento de caja.';if(can('payments'))$link='/admin/payments';}
        elseif($type==='charge'||$type==='treatment') {$p=DB::one('SELECT tooth_fdi,notes FROM treatment_plan_items WHERE id=?',[$id]);$fields['Pieza FDI']=$p['tooth_fdi'] ?: 'No asociada';if(!$finance)$fields['Observaciones']=$p['notes'] ?: 'Sin observaciones';else $fields['Alcance']='Valor presupuestado del procedimiento realizado; no equivale a un cobro.';if(can('treatments'))$link='/admin/treatments';}
        elseif($type==='appointment'||$type==='event') {$appointmentId=$id;if($type==='event'){$a=DB::one('SELECT entity_id,detail FROM audit_logs WHERE id=?',[$id]);$appointmentId=$a['entity_id'];$change=json_decode($a['detail'],true);if(is_array($change)){foreach(['before'=>'Antes','after'=>'Después'] as $k=>$name)if(isset($change[$k]))$fields[$name]=local_date($change[$k]['starts_at']).' · '.label($change[$k]['status']);}}$a=DB::one('SELECT starts_at,ends_at,reason FROM appointments WHERE id=?',[$appointmentId]);$fields['Inicio']=local_date($a['starts_at']);$fields['Fin']=local_date($a['ends_at']);$fields['Motivo']=$a['reason'] ?: 'Sin comentario';$link='/admin/appointments?edit='.$appointmentId;}
        elseif($type==='clinical') {$e=DB::one('SELECT consultation_reason,diagnosis,observation FROM clinical_record_entries WHERE id=?',[$id]);$fields['Motivo']=$e['consultation_reason'];$fields['Diagnóstico']=$e['diagnosis'];$fields['Observaciones']=$e['observation'] ?: 'Sin observaciones';$link='/admin/clinical?patient_id='.$row['patient_id'];}
        elseif($type==='odontogram') {$fields['Versión']='#'.$id;$fields['Hallazgos']=(string)DB::query('SELECT COUNT(*) FROM odontogram_findings WHERE odontogram_id=?',[$id])->fetchColumn();$link='/admin/odontogram?patient_id='.$row['patient_id'].'&version='.$id;}
        return ['title'=>self::type($type),'fields'=>$fields,'url'=>$link ? url($link):null,'category'=>$finance ? 'Movimiento financiero':'Actividad clínica y agenda'];
    }
    public static function type(string $type): string { return ['payment'=>'Pago / abono','void'=>'Anulación contable','balance'=>'Saldo pendiente','charge'=>'Procedimiento valorizado','appointment'=>'Cita','event'=>'Cambio de cita','clinical'=>'Historia clínica','odontogram'=>'Odontograma','treatment'=>'Tratamiento realizado'][$type] ?? $type; }
    public static function status(string $status): string { return ['registered'=>'Registrado','voided'=>'Anulado','outstanding'=>'Pendiente de cobro','settled'=>'Saldado','signed'=>'Firmado','saved'=>'Guardado'][$status] ?? label($status); }
    public static function link(array $f,array $changes=[]): string { return url('/admin?'.http_build_query(array_replace($f,$changes))); }
}
