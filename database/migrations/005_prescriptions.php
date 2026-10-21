<?php
// A prescription belongs to the patient's clinical record (like clinical_record_entries) and can optionally
// be tied to the treatment or session that prompted it. It is never edited after creation — a mistake is
// cancelled (with a reason, kept on the row) and a new prescription is issued, the same append-only spirit
// as clinical_record_entries corrections.
return static function (): void {
    $exec = static fn(string $sql) => App\Models\DB::connection()->exec($sql);

    $exec("CREATE TABLE IF NOT EXISTS prescriptions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        clinical_record_id INT UNSIGNED NOT NULL,
        dentist_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        treatment_plan_item_id INT UNSIGNED NULL,
        session_id INT UNSIGNED NULL,
        diagnosis_summary VARCHAR(500) NULL,
        instructions VARCHAR(1000) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        cancelled_at DATETIME NULL,
        cancelled_by INT UNSIGNED NULL,
        cancel_reason VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(clinical_record_id) REFERENCES clinical_records(id),
        FOREIGN KEY(dentist_id) REFERENCES dentists(id),
        FOREIGN KEY(user_id) REFERENCES users(id),
        FOREIGN KEY(treatment_plan_item_id) REFERENCES treatment_plan_items(id),
        FOREIGN KEY(session_id) REFERENCES treatment_sessions(id),
        FOREIGN KEY(cancelled_by) REFERENCES users(id),
        INDEX prescription_record(clinical_record_id),
        CHECK(status IN ('active','cancelled'))
    ) ENGINE=InnoDB");

    $exec("CREATE TABLE IF NOT EXISTS prescription_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        prescription_id INT UNSIGNED NOT NULL,
        sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        drug_name VARCHAR(150) NOT NULL,
        presentation VARCHAR(100) NULL,
        dosage VARCHAR(100) NOT NULL,
        frequency VARCHAR(100) NOT NULL,
        duration VARCHAR(100) NULL,
        route VARCHAR(50) NULL,
        instructions VARCHAR(500) NULL,
        FOREIGN KEY(prescription_id) REFERENCES prescriptions(id)
    ) ENGINE=InnoDB");
};
