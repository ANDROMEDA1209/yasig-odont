<?php
declare(strict_types=1);
// Additive: institutional patients, appointments, financial plans and private files remain canonical.
return static function (): void {
    $pdo=App\Models\DB::connection();
    $sqls=[
        "CREATE TABLE IF NOT EXISTS orth_cases (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            clinical_record_id INT UNSIGNED NOT NULL,
            dentist_id INT UNSIGNED NOT NULL,
            treatment_plan_item_id INT UNSIGNED NOT NULL UNIQUE,
            type_key VARCHAR(50) NOT NULL,
            appliance VARCHAR(200) NOT NULL DEFAULT '',
            status VARCHAR(40) NOT NULL DEFAULT 'evaluation',
            start_date DATE NOT NULL,
            next_control DATE NULL,
            estimated_months SMALLINT UNSIGNED NULL,
            control_interval_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            completed_at DATETIME NULL,
            closed_at DATETIME NULL,
            live_record_id INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN closed_at IS NULL THEN clinical_record_id ELSE NULL END) STORED,
            completion_criteria TEXT NULL,
            retainer_type VARCHAR(200) NULL,
            retention_instructions TEXT NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE orth_one_open_case(live_record_id),
            INDEX orth_dentist_status(dentist_id,status), INDEX orth_next_control(next_control), INDEX orth_start(start_date),
            FOREIGN KEY(clinical_record_id) REFERENCES clinical_records(id),
            FOREIGN KEY(dentist_id) REFERENCES dentists(id),
            FOREIGN KEY(treatment_plan_item_id) REFERENCES treatment_plan_items(id),
            FOREIGN KEY(created_by) REFERENCES users(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS orth_case_members (
            case_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, assigned_by INT UNSIGNED NOT NULL,
            active TINYINT NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(case_id,user_id),
            FOREIGN KEY(case_id) REFERENCES orth_cases(id), FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(assigned_by) REFERENCES users(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS orth_diagnoses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, case_id INT UNSIGNED NOT NULL, previous_id INT UNSIGNED NULL,
            evaluation_date DATE NOT NULL, data_json MEDIUMTEXT NOT NULL, user_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX orth_diagnosis_case(case_id,id),
            FOREIGN KEY(case_id) REFERENCES orth_cases(id), FOREIGN KEY(previous_id) REFERENCES orth_diagnoses(id), FOREIGN KEY(user_id) REFERENCES users(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS orth_plan_versions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, case_id INT UNSIGNED NOT NULL, previous_id INT UNSIGNED NULL,
            data_json MEDIUMTEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', change_reason VARCHAR(1000) NOT NULL DEFAULT '',
            user_id INT UNSIGNED NOT NULL, authorized_by INT UNSIGNED NULL, authorized_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX orth_plan_case(case_id,id),
            FOREIGN KEY(case_id) REFERENCES orth_cases(id), FOREIGN KEY(previous_id) REFERENCES orth_plan_versions(id),
            FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(authorized_by) REFERENCES users(id),
            CHECK(status IN ('draft','authorized'))
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS orth_case_appointments (
            case_id INT UNSIGNED NOT NULL, appointment_id INT UNSIGNED NOT NULL UNIQUE, user_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(case_id,appointment_id),
            FOREIGN KEY(case_id) REFERENCES orth_cases(id), FOREIGN KEY(appointment_id) REFERENCES appointments(id), FOREIGN KEY(user_id) REFERENCES users(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS orth_controls (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, case_id INT UNSIGNED NOT NULL, session_id INT UNSIGNED NOT NULL UNIQUE,
            phase VARCHAR(100) NOT NULL DEFAULT '', next_control DATE NULL, appointment_id INT UNSIGNED NULL,
            request_key CHAR(64) NULL UNIQUE, payload_hash CHAR(64) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX orth_control_case(case_id,id), FOREIGN KEY(case_id) REFERENCES orth_cases(id),
            FOREIGN KEY(session_id) REFERENCES treatment_sessions(id), FOREIGN KEY(appointment_id) REFERENCES appointments(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS orth_control_addenda (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, control_id INT UNSIGNED NOT NULL, notes TEXT NOT NULL,
            reason VARCHAR(1000) NOT NULL, user_id INT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(control_id) REFERENCES orth_controls(id), FOREIGN KEY(user_id) REFERENCES users(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS orth_attachments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, case_id INT UNSIGNED NOT NULL, attachment_id INT UNSIGNED NOT NULL UNIQUE,
            control_id INT UNSIGNED NULL, photo_category VARCHAR(50) NULL, phase VARCHAR(100) NOT NULL DEFAULT '',
            description VARCHAR(500) NOT NULL DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX orth_media_case(case_id,photo_category), FOREIGN KEY(case_id) REFERENCES orth_cases(id),
            FOREIGN KEY(attachment_id) REFERENCES attachments(id), FOREIGN KEY(control_id) REFERENCES orth_controls(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS orth_consents (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, case_id INT UNSIGNED NOT NULL, kind VARCHAR(60) NOT NULL,
            status VARCHAR(20) NOT NULL, signed_on DATE NOT NULL, notes TEXT NULL, attachment_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX orth_consent_case(case_id,kind,id), FOREIGN KEY(case_id) REFERENCES orth_cases(id),
            FOREIGN KEY(attachment_id) REFERENCES attachments(id), FOREIGN KEY(user_id) REFERENCES users(id),
            CHECK(status IN ('granted','revoked'))
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS orth_tasks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, case_id INT UNSIGNED NOT NULL, title VARCHAR(250) NOT NULL,
            due_date DATE NULL, priority VARCHAR(20) NOT NULL DEFAULT 'normal', status VARCHAR(20) NOT NULL DEFAULT 'pending',
            user_id INT UNSIGNED NOT NULL, completed_by INT UNSIGNED NULL, completed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX orth_tasks_case(case_id,status,due_date),
            FOREIGN KEY(case_id) REFERENCES orth_cases(id), FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(completed_by) REFERENCES users(id),
            CHECK(status IN ('pending','completed')), CHECK(priority IN ('low','normal','high','urgent'))
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS orth_assistant_proposals (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, case_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
            kind VARCHAR(40) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', prompt TEXT NULL, content MEDIUMTEXT NOT NULL, original_content MEDIUMTEXT NULL,
            model VARCHAR(150) NOT NULL DEFAULT '', reviewed_by INT UNSIGNED NULL, reviewed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX orth_ai_case(case_id,id),
            FOREIGN KEY(case_id) REFERENCES orth_cases(id), FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(reviewed_by) REFERENCES users(id),
            CHECK(status IN ('draft','accepted','discarded'))
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS orth_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, case_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
            event_type VARCHAR(60) NOT NULL, entity_id INT UNSIGNED NULL, from_value VARCHAR(100) NULL, to_value VARCHAR(100) NULL,
            reason TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX orth_history_case(case_id,id), FOREIGN KEY(case_id) REFERENCES orth_cases(id), FOREIGN KEY(user_id) REFERENCES users(id)
        ) ENGINE=InnoDB",
    ];
    foreach($sqls as $sql) $pdo->exec($sql);
    // Safe to retry after an interrupted deployment, including an earlier partial copy of this migration.
    foreach([['orth_controls','payload_hash','CHAR(64) NULL'],['orth_assistant_proposals','original_content','MEDIUMTEXT NULL']] as [$table,$column,$definition]) {
        if (!App\Models\DB::one('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column])) $pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);
    }
    $historyType=App\Models\DB::one('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="orth_history" AND COLUMN_NAME="reason"');
    if (($historyType['DATA_TYPE'] ?? '')!=='text') $pdo->exec('ALTER TABLE orth_history MODIFY reason TEXT NOT NULL');
    // BaseSeeder can run before or after this migration. Explicit role creation keeps fresh installs correct.
    foreach(['Administrador','Odontólogo','Asistente odontológico'] as $role) App\Models\DB::query('INSERT IGNORE INTO roles(name) VALUES (?)',[$role]);
    foreach(['orthodontics','orthodontics_assist'] as $permission) App\Models\DB::query('INSERT IGNORE INTO permissions(name) VALUES (?)',[$permission]);
    foreach(['Administrador','Odontólogo','Asistente odontológico'] as $role) App\Models\DB::query('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.name=? AND p.name="orthodontics"',[$role]);
    App\Models\DB::query('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.name="Asistente odontológico" AND p.name="orthodontics_assist"');
    foreach([['evaluation','Evaluación y diagnóstico',0],['planning','Planificación individual',1],['active','Tratamiento activo',2],['finishing','Evaluación de finalización',3],['retention','Retención y controles',4]] as [$key,$label,$order]) {
        App\Models\DB::query('INSERT IGNORE INTO treatment_stage_templates(specialty,stage_key,stage_name,sort_order) VALUES ("orthodontics",?,?,?)',[$key,$label,$order]);
    }
};
