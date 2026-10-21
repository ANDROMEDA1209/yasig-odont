<?php
return static function (): void {
    foreach (['medical_history','dental_history','allergies'] as $column) {
        if (!App\Models\DB::one('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',['clinical_record_entries',$column])) App\Models\DB::connection()->exec('ALTER TABLE clinical_record_entries ADD COLUMN '.$column.' TEXT NULL');
    }
};
