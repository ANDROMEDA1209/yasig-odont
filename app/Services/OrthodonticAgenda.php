<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\DB;
use App\Validators\Input as V;

/** The ordinary scheduler remains the only source of appointments and availability. */
final class OrthodonticAgenda {
    public static function schedule(array $d): int {
        $case=Orthodontics::assertCase(V::id($d,'case_id'));
        $appointment=empty($d['appointment_id']) ? 0:V::id($d,'appointment_id');
        if ($appointment && !DB::one('SELECT a.id FROM appointments a JOIN orth_case_appointments l ON l.appointment_id=a.id WHERE l.case_id=? AND a.id=? AND a.patient_id=? AND a.dentist_id=?',[$case['id'],$appointment,$case['patient_id'],$case['dentist_id']])) throw new \DomainException('La cita no pertenece al expediente.');
        $data=[...$d,'patient_id'=>$case['patient_id'],'dentist_id'=>$case['dentist_id'],'status'=>$d['status'] ?? 'confirmed'];
        // Ordinary scheduler validates real professional hours and locks against overlaps.
        Clinic::appointment($data,$appointment,false,function(int $id) use($case,$d) {
            $current=DB::one('SELECT version FROM orth_cases WHERE id=? FOR UPDATE',[$case['id']]);
            if (isset($d['version']) && (int)$d['version']!==(int)$current['version']) throw new \DomainException('El expediente cambió. Recarga antes de programar.');
            DB::query('INSERT IGNORE INTO orth_case_appointments(case_id,appointment_id,user_id) VALUES (?,?,?)',[$case['id'],$id,user()['id']]);
            self::updateNext((int)$case['id']);
            Orthodontics::logEvent((int)$case['id'],'appointment_saved',$id);
        });
        return (int)$case['id'];
    }
    public static function link(array $d): int {
        $case=Orthodontics::assertCase(V::id($d,'case_id')); $appointment=V::id($d,'appointment_id');
        DB::transaction(function() use($case,$appointment) {
            DB::one('SELECT id FROM orth_cases WHERE id=? FOR UPDATE',[$case['id']]);
            if (!DB::one('SELECT id FROM appointments WHERE id=? AND patient_id=? AND dentist_id=? FOR UPDATE',[$appointment,$case['patient_id'],$case['dentist_id']])) throw new \DomainException('Selecciona una cita del mismo paciente y profesional.');
            $linked=DB::one('SELECT case_id FROM orth_case_appointments WHERE appointment_id=?',[$appointment]);
            if ($linked && (int)$linked['case_id']!==(int)$case['id']) throw new \DomainException('La cita está vinculada a otro expediente.');
            DB::query('INSERT IGNORE INTO orth_case_appointments(case_id,appointment_id,user_id) VALUES (?,?,?)',[$case['id'],$appointment,user()['id']]);
            self::updateNext((int)$case['id']); Orthodontics::logEvent((int)$case['id'],'appointment_linked',$appointment);
        });
        return (int)$case['id'];
    }
    private static function updateNext(int $id): void {
        $next=DB::query('SELECT MIN(a.starts_at) FROM appointments a JOIN orth_case_appointments l ON l.appointment_id=a.id WHERE l.case_id=? AND a.starts_at>=UTC_TIMESTAMP() AND a.status IN ("pending","confirmed")',[$id])->fetchColumn();
        $date=$next ? (new \DateTimeImmutable($next,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(env('APP_TIMEZONE','America/Guayaquil')))->format('Y-m-d'):null;
        DB::query('UPDATE orth_cases SET next_control=COALESCE(?,next_control),version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?',[$date,$id]);
    }
}
