<?php
// Called only after CLI confirmation or authenticated administrator + CSRF checks.
if (!flag('APP_DEMO') || env('APP_ENV')==='production') throw new DomainException('Restablecimiento no permitido.');
$expected=['orth_history','orth_assistant_proposals','orth_tasks','orth_consents','orth_attachments','orth_control_addenda','orth_controls','orth_case_appointments','orth_plan_versions','orth_diagnoses','orth_case_members','orth_cases','prescription_items','prescriptions','treatment_status_history','treatment_sessions','treatment_stages','clinical_diagnosis_surfaces','clinical_diagnoses','audit_logs','attachments','auth_attempts','remember_tokens','password_reset_tokens','inventory_movements','inventory_lots','inventory_items','payment_receipts','payments','treatment_plan_items','treatment_plans','odontogram_findings','odontograms','clinical_record_entries','clinical_records','appointments','patients','dentists','users'];
App\Models\DB::connection()->exec('SET FOREIGN_KEY_CHECKS=0');
try { foreach ($expected as $table) App\Models\DB::connection()->exec('TRUNCATE TABLE `'.$table.'`'); } finally { App\Models\DB::connection()->exec('SET FOREIGN_KEY_CHECKS=1'); }
foreach (glob(ROOT.'/storage/private/*') as $file) if (is_file($file)) unlink($file);
