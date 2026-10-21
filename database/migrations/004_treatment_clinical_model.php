<?php
// Adds the clinical layer the simple billing-oriented treatment_plan_items could not express on its own:
// a configurable diagnosis catalog, multi-surface clinical diagnoses tied to a tooth and an odontogram
// version, a clinical workflow status that is independent from the financial status (so changing one never
// corrupts payment/balance math that already depends on the existing status column), sessions, a generic
// configurable stage tracker (used first by Endodoncia), and richer attachment context.
return static function (): void {
    $hasColumn = static fn(string $table, string $column): bool =>
        (bool) App\Models\DB::one('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $column]);
    $exec = static fn(string $sql) => App\Models\DB::connection()->exec($sql);

    $exec("CREATE TABLE IF NOT EXISTS diagnosis_catalog (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(30) NOT NULL,
        code VARCHAR(40) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        description VARCHAR(500) NULL,
        active TINYINT NOT NULL DEFAULT 1,
        sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        CHECK(category IN ('dental','pulpar','periapical','periodontal','oclusal','otro'))
    ) ENGINE=InnoDB");

    $exec("CREATE TABLE IF NOT EXISTS clinical_diagnoses (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        clinical_record_id INT UNSIGNED NOT NULL,
        odontogram_id INT UNSIGNED NULL,
        tooth_fdi SMALLINT UNSIGNED NOT NULL,
        diagnosis_id INT UNSIGNED NOT NULL,
        notes VARCHAR(1000) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(clinical_record_id) REFERENCES clinical_records(id),
        FOREIGN KEY(odontogram_id) REFERENCES odontograms(id),
        FOREIGN KEY(tooth_fdi) REFERENCES teeth(fdi),
        FOREIGN KEY(diagnosis_id) REFERENCES diagnosis_catalog(id),
        FOREIGN KEY(user_id) REFERENCES users(id),
        INDEX diagnosis_tooth(clinical_record_id, tooth_fdi),
        CHECK(status IN ('active','resolved','superseded'))
    ) ENGINE=InnoDB");

    // A single diagnosis event can span several surfaces; no rows at all means it applies to the whole tooth.
    $exec("CREATE TABLE IF NOT EXISTS clinical_diagnosis_surfaces (
        clinical_diagnosis_id INT UNSIGNED NOT NULL,
        surface VARCHAR(20) NOT NULL,
        PRIMARY KEY(clinical_diagnosis_id, surface),
        FOREIGN KEY(clinical_diagnosis_id) REFERENCES clinical_diagnoses(id),
        CHECK(surface IN ('mesial','distal','vestibular','lingual','occlusal'))
    ) ENGINE=InnoDB");

    // Financial `status` on treatment_plan_items stays untouched — payments, dashboard income and reports
    // already depend on its exact 4 values. clinical_status is a parallel, independent clinical workflow.
    $columns = [
        'clinical_diagnosis_id' => 'ALTER TABLE treatment_plan_items ADD COLUMN clinical_diagnosis_id INT UNSIGNED NULL AFTER tooth_fdi, ADD FOREIGN KEY(clinical_diagnosis_id) REFERENCES clinical_diagnoses(id)',
        'specialty' => "ALTER TABLE treatment_plan_items ADD COLUMN specialty VARCHAR(30) NOT NULL DEFAULT 'general' AFTER clinical_diagnosis_id",
        'priority' => "ALTER TABLE treatment_plan_items ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER specialty",
        'justification' => 'ALTER TABLE treatment_plan_items ADD COLUMN justification VARCHAR(1000) NULL AFTER priority',
        'estimated_sessions' => 'ALTER TABLE treatment_plan_items ADD COLUMN estimated_sessions SMALLINT UNSIGNED NULL AFTER justification',
        'clinical_status' => "ALTER TABLE treatment_plan_items ADD COLUMN clinical_status VARCHAR(20) NOT NULL DEFAULT 'proposed' AFTER status",
    ];
    foreach ($columns as $column => $sql) if (!$hasColumn('treatment_plan_items', $column)) $exec($sql);

    $exec("CREATE TABLE IF NOT EXISTS treatment_status_history (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        treatment_plan_item_id INT UNSIGNED NOT NULL,
        field VARCHAR(20) NOT NULL,
        from_status VARCHAR(20) NULL,
        to_status VARCHAR(20) NOT NULL,
        reason VARCHAR(1000) NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(treatment_plan_item_id) REFERENCES treatment_plan_items(id),
        FOREIGN KEY(user_id) REFERENCES users(id),
        CHECK(field IN ('status','clinical_status'))
    ) ENGINE=InnoDB");

    $exec("CREATE TABLE IF NOT EXISTS treatment_sessions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        treatment_plan_item_id INT UNSIGNED NOT NULL,
        session_number SMALLINT UNSIGNED NOT NULL,
        occurred_at DATETIME NOT NULL,
        dentist_id INT UNSIGNED NOT NULL,
        objective VARCHAR(500) NULL,
        tooth_state_start VARCHAR(500) NULL,
        symptoms VARCHAR(1000) NULL,
        findings TEXT NULL,
        procedures_done TEXT NULL,
        materials_used VARCHAR(1000) NULL,
        incidents TEXT NULL,
        tooth_state_end VARCHAR(500) NULL,
        instructions TEXT NULL,
        next_appointment_id INT UNSIGNED NULL,
        notes TEXT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(treatment_plan_item_id, session_number),
        FOREIGN KEY(treatment_plan_item_id) REFERENCES treatment_plan_items(id),
        FOREIGN KEY(dentist_id) REFERENCES dentists(id),
        FOREIGN KEY(next_appointment_id) REFERENCES appointments(id),
        FOREIGN KEY(user_id) REFERENCES users(id)
    ) ENGINE=InnoDB");

    // Per-specialty configurable checklist; seeded below for Endodoncia. Editing the catalog never touches
    // stages already copied onto an in-progress treatment, so past records keep whatever stages they had.
    $exec("CREATE TABLE IF NOT EXISTS treatment_stage_templates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        specialty VARCHAR(30) NOT NULL,
        stage_key VARCHAR(40) NOT NULL,
        stage_name VARCHAR(150) NOT NULL,
        sort_order SMALLINT UNSIGNED NOT NULL,
        active TINYINT NOT NULL DEFAULT 1,
        UNIQUE(specialty, stage_key)
    ) ENGINE=InnoDB");

    $exec("CREATE TABLE IF NOT EXISTS treatment_stages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        treatment_plan_item_id INT UNSIGNED NOT NULL,
        stage_key VARCHAR(40) NOT NULL,
        stage_name VARCHAR(150) NOT NULL,
        sort_order SMALLINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        professional_id INT UNSIGNED NULL,
        notes TEXT NULL,
        procedures TEXT NULL,
        materials VARCHAR(500) NULL,
        incidents TEXT NULL,
        next_action VARCHAR(500) NULL,
        session_id INT UNSIGNED NULL,
        UNIQUE(treatment_plan_item_id, stage_key),
        FOREIGN KEY(treatment_plan_item_id) REFERENCES treatment_plan_items(id),
        FOREIGN KEY(professional_id) REFERENCES dentists(id),
        FOREIGN KEY(session_id) REFERENCES treatment_sessions(id),
        CHECK(status IN ('pending','in_progress','completed','not_applicable','needs_review'))
    ) ENGINE=InnoDB");

    // Files stay patient-level by default (existing behaviour) but can now be pinned to the exact
    // tooth, treatment, session or stage they document — nothing about the existing rows changes.
    $attachmentColumns = [
        'tooth_fdi' => 'ALTER TABLE attachments ADD COLUMN tooth_fdi SMALLINT UNSIGNED NULL AFTER clinical_record_id, ADD FOREIGN KEY(tooth_fdi) REFERENCES teeth(fdi)',
        'treatment_plan_item_id' => 'ALTER TABLE attachments ADD COLUMN treatment_plan_item_id INT UNSIGNED NULL AFTER tooth_fdi, ADD FOREIGN KEY(treatment_plan_item_id) REFERENCES treatment_plan_items(id)',
        'session_id' => 'ALTER TABLE attachments ADD COLUMN session_id INT UNSIGNED NULL AFTER treatment_plan_item_id, ADD FOREIGN KEY(session_id) REFERENCES treatment_sessions(id)',
        'stage_id' => 'ALTER TABLE attachments ADD COLUMN stage_id INT UNSIGNED NULL AFTER session_id, ADD FOREIGN KEY(stage_id) REFERENCES treatment_stages(id)',
        'category' => "ALTER TABLE attachments ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'document' AFTER mime",
        'captured_at' => 'ALTER TABLE attachments ADD COLUMN captured_at DATE NULL AFTER category',
        'description' => 'ALTER TABLE attachments ADD COLUMN description VARCHAR(500) NULL AFTER captured_at',
    ];
    foreach ($attachmentColumns as $column => $sql) if (!$hasColumn('attachments', $column)) $exec($sql);

    // Seed data: idempotent (INSERT IGNORE on a UNIQUE key), safe to run against databases that already
    // have rows from a previous partial run.
    $diagnoses = [
        ['dental','D-SANO','Diente sano',0],['dental','D-CAR-INI','Caries inicial',1],['dental','D-CAR-MOD','Caries moderada',2],['dental','D-CAR-AVZ','Caries avanzada',3],['dental','D-CAR-REC','Caries recurrente',4],
        ['dental','D-FRA-COR','Fractura coronaria',5],['dental','D-FRA-RAD','Fractura radicular',6],['dental','D-FISURA','Fisura dental',7],['dental','D-DESGASTE','Desgaste / abrasión / erosión / atrición',8],
        ['dental','D-ESMALTE','Alteración del esmalte',9],['dental','D-HIPOPLASIA','Hipoplasia',10],['dental','D-MANCHA','Mancha / alteración de color',11],
        ['dental','D-REAB-INT','Reabsorción radicular interna',12],['dental','D-REAB-EXT','Reabsorción radicular externa',13],['dental','D-MOVILIDAD','Movilidad dental',14],
        ['dental','D-ECTOPICO','Diente ectópico',15],['dental','D-IMPACTADO','Diente incluido / impactado',16],['dental','D-SUPERNUM','Diente supernumerario',17],
        ['dental','D-AUSENTE','Ausencia dental',18],['dental','D-AGENESIA','Agenesia',19],['dental','D-ERUPCION','Erupción parcial / en curso',20],['dental','D-DIASTEMA','Diastema / apiñamiento',21],
        ['pulpar','P-NORMAL','Pulpa normal',0],['pulpar','P-REVERSIBLE','Pulpitis reversible',1],['pulpar','P-IRREV-SINT','Pulpitis irreversible sintomática',2],['pulpar','P-IRREV-ASINT','Pulpitis irreversible asintomática',3],
        ['pulpar','P-NECROSIS','Necrosis pulpar',4],['pulpar','P-TRATADO','Diente previamente tratado',5],
        ['periapical','A-NORMAL','Tejidos apicales normales',0],['periapical','A-SINT','Periodontitis apical sintomática',1],['periapical','A-ASINT','Periodontitis apical asintomática',2],
        ['periapical','A-ABS-AGU','Absceso apical agudo',3],['periapical','A-ABS-CRO','Absceso apical crónico',4],['periapical','A-LESION','Lesión periapical',5],
        ['periapical','A-FRACTURA','Fractura radicular',6],['periapical','A-REABSORCION','Reabsorción',7],['periapical','A-PERFORACION','Perforación',8],
        ['periodontal','G-SALUD','Salud periodontal',0],['periodontal','G-GINGIVITIS','Gingivitis',1],['periodontal','G-PERIODONTITIS','Periodontitis',2],['periodontal','G-RECESION','Recesión gingival',3],
        ['periodontal','G-SANGRADO','Sangrado al sondaje',4],['periodontal','G-FURCACION','Furcación',5],['periodontal','G-CALCULO','Cálculo y placa bacteriana',6],
        ['periodontal','G-ABSCESO','Absceso periodontal',7],['periodontal','G-MUCOSITIS','Mucositis periimplantaria',8],['periodontal','G-PERIIMPLANTITIS','Periimplantitis',9],
        ['oclusal','O-MORD-ABIERTA','Mordida abierta',0],['oclusal','O-MORD-CRUZADA','Mordida cruzada',1],['oclusal','O-SOBREMORDIDA','Sobremordida',2],['oclusal','O-RESALTE','Resalte aumentado',3],
        ['otro','X-PROTESIS','Complicación protésica',0],['otro','X-IMPLANTE','Complicación de implante',1],['otro','X-TRAUMA','Traumatismo dental',2],
    ];
    foreach ($diagnoses as [$category, $code, $name, $order]) {
        App\Models\DB::connection()->prepare('INSERT IGNORE INTO diagnosis_catalog(category,code,name,sort_order) VALUES (?,?,?,?)')->execute([$category, $code, $name, $order]);
    }

    $endodonticsStages = [
        ['evaluacion','Evaluación y diagnóstico',0],['planificacion','Planificación y consentimiento',1],['apertura','Apertura y acceso cameral',2],
        ['localizacion','Localización de conductos',3],['conductometria','Conductometría y longitud de trabajo',4],['instrumentacion','Instrumentación y limpieza',5],
        ['irrigacion','Irrigación y desinfección',6],['medicacion','Medicación intraconducto',7],['obturacion','Obturación de conductos',8],
        ['restauracion','Restauración definitiva o provisional',9],['control','Control clínico y radiográfico',10],['alta','Alta o seguimiento',11],
    ];
    foreach ($endodonticsStages as [$key, $name, $order]) {
        App\Models\DB::connection()->prepare('INSERT IGNORE INTO treatment_stage_templates(specialty,stage_key,stage_name,sort_order) VALUES (?,?,?,?)')->execute(['endodontics', $key, $name, $order]);
    }
};
