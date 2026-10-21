<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\DB;
use App\Validators\Input as V;
final class Reports {
    public static function data(string $type,string $from,string $to): array {
        V::date($from); V::date($to); if ($from>$to) throw new \DomainException('El inicio debe ser anterior al fin.');
        $start=(new \DateTimeImmutable($from))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end=(new \DateTimeImmutable($to))->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return match($type) {
            'patients'=>['columns'=>['Código','Paciente','Registro','Estado'],'rows'=>DB::all('SELECT code,CONCAT(first_name," ",last_name) patient,created_at,status FROM patients WHERE created_at>=? AND created_at<? ORDER BY created_at',[$start,$end])],
            'appointments'=>['columns'=>['Estado','Cantidad'],'rows'=>DB::all('SELECT status,COUNT(*) total FROM appointments WHERE starts_at>=? AND starts_at<? GROUP BY status',[$start,$end])],
            'no_show'=>['columns'=>['Cita','Paciente / solicitud','Fecha UTC'],'rows'=>DB::all('SELECT a.id,COALESCE(CONCAT(p.first_name," ",p.last_name),a.contact_name) patient,a.starts_at FROM appointments a LEFT JOIN patients p ON p.id=a.patient_id WHERE a.status="no_show" AND a.starts_at>=? AND a.starts_at<?',[$start,$end])],
            'treatments'=>['columns'=>['Procedimiento','Paciente','Fecha UTC','Importe'],'rows'=>DB::all('SELECT t.name,CONCAT(p.first_name," ",p.last_name) patient,i.completed_at,i.price FROM treatment_plan_items i JOIN treatment_plans pl ON pl.id=i.plan_id JOIN patients p ON p.id=pl.patient_id JOIN treatments t ON t.id=i.treatment_id WHERE i.status="completed" AND i.completed_at>=? AND i.completed_at<?',[$start,$end])],
            'income'=>['columns'=>['Comprobante','Importe','Método','Fecha UTC'],'rows'=>DB::all('SELECT r.number,p.amount,p.method,p.created_at FROM payments p JOIN payment_receipts r ON r.payment_id=p.id WHERE p.voided_at IS NULL AND p.created_at>=? AND p.created_at<?',[$start,$end])],
            'balances'=>['columns'=>['Plan','Paciente','Presupuesto','Pagado','Saldo'],'rows'=>DB::all('SELECT pl.name,CONCAT(p.first_name," ",p.last_name) patient,COALESCE(t.total,0) total,COALESCE(pay.paid,0) paid,COALESCE(t.total,0)-COALESCE(pay.paid,0) balance FROM treatment_plans pl JOIN patients p ON p.id=pl.patient_id LEFT JOIN (SELECT plan_id,SUM(price) total FROM treatment_plan_items WHERE status<>"cancelled" GROUP BY plan_id) t ON t.plan_id=pl.id LEFT JOIN (SELECT plan_id,SUM(amount) paid FROM payments WHERE voided_at IS NULL GROUP BY plan_id) pay ON pay.plan_id=pl.id WHERE pl.created_at>=? AND pl.created_at<? AND pl.status<>"cancelled"',[$start,$end])],
            'inventory'=>['columns'=>['Material','Lote','Cantidad','Motivo','Fecha UTC'],'rows'=>DB::all('SELECT i.name,l.lot,m.quantity,m.reason,m.created_at FROM inventory_movements m JOIN inventory_items i ON i.id=m.item_id JOIN inventory_lots l ON l.id=m.lot_id WHERE m.created_at>=? AND m.created_at<? ORDER BY m.created_at',[$start,$end])],
            default=>throw new \DomainException('Reporte no disponible.')
        };
    }
    public static function export(array $report,string $format): never {
        DB::audit('report_export','reports',0,$format);
        if ($format==='csv') {
            header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="yasig-reporte.csv"'); $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
            fputcsv($out,$report['columns']); foreach ($report['rows'] as $row) fputcsv($out,array_map(static function($v) { $v=(string)$v; return preg_match('/^[=+\-@\t\r]/',$v) ? "'".$v:$v; },array_values($row))); fclose($out); exit;
        }
        if ($format!=='pdf') throw new \DomainException('Formato no disponible.');
        $html='<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans;font-size:10px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:7px}th{background:#e7f4ef}</style></head><body><h1>YASIG ODONT</h1><p>Reporte interno · '.e(date('d/m/Y H:i')).' · '.(flag('APP_DEMO') ? 'DATOS FICTICIOS':'').'</p><table><tr>';
        foreach($report['columns'] as $c) $html.='<th>'.e($c).'</th>'; $html.='</tr>';
        foreach($report['rows'] as $r) { $html.='<tr>'; foreach($r as $v) $html.='<td>'.e($v).'</td>'; $html.='</tr>'; } $html.='</table></body></html>';
        $pdf=new \Dompdf\Dompdf(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'chroot'=>ROOT.'/storage/cache','tempDir'=>ROOT.'/storage/cache','fontCache'=>ROOT.'/storage/cache']); $pdf->loadHtml($html); $pdf->setPaper('A4','landscape'); $pdf->render(); $pdf->stream('yasig-reporte.pdf',['Attachment'=>true]); exit;
    }
}
