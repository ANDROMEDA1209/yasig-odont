<?php
return static function (): void {
    $columns = [
        'dentition_type' => "ALTER TABLE odontograms ADD COLUMN dentition_type VARCHAR(20) NOT NULL DEFAULT 'permanent' AFTER previous_id",
        'recommended_type' => 'ALTER TABLE odontograms ADD COLUMN recommended_type VARCHAR(20) NULL AFTER dentition_type',
        'override_reason' => 'ALTER TABLE odontograms ADD COLUMN override_reason VARCHAR(500) NULL AFTER recommended_type',
    ];
    foreach ($columns as $column => $sql) {
        if (!App\Models\DB::one('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['odontograms', $column])) {
            App\Models\DB::connection()->exec($sql);
        }
    }
};
