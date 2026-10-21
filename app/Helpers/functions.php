<?php
declare(strict_types=1);
function env(string $key, string $default = ''): string { $v = getenv($key); return $v === false ? $default : $v; }
function flag(string $key): bool { return filter_var(env($key), FILTER_VALIDATE_BOOLEAN); }
function e(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function base_path(): string { return rtrim((string)parse_url(env('APP_URL', 'http://localhost:8080'), PHP_URL_PATH), '/'); }
function url(string $path = '/'): string { return base_path() . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . url($path), true, 303); exit; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf()) . '">'; }
function flash(string $message, string $type='success'): void { $_SESSION['flash'] = [$message,$type]; }
function money(mixed $value): string { return '$' . number_format((float)$value, 2, '.', ','); }
function brand_logo(): ?string { foreach (['/assets/images/gallery/Logo.jpeg','/assets/images/brand/logo.png'] as $path) if (is_file(ROOT.'/public'.$path)) return $path; return null; }
function action_label(string $value): string { return ['login'=>'Inicio de sesión','logout'=>'Cierre de sesión','patient_saved'=>'Ficha de paciente guardada','patient_created'=>'Paciente registrado','appointment_rescheduled'=>'Cita reprogramada','appointment_cancelled'=>'Cita cancelada','appointment_attended'=>'Asistencia registrada','appointment_completed'=>'Cita completada','appointment_status_changed'=>'Estado de cita actualizado','appointment_saved'=>'Cita actualizada','appointment_requested'=>'Solicitud de cita recibida','request_cancelled'=>'Solicitud cancelada','clinical_viewed'=>'Historia clínica consultada','clinical_signed'=>'Evolución clínica firmada','clinical_correction'=>'Corrección clínica registrada','odontogram_evaluation'=>'Nueva evaluación odontológica iniciada','odontogram_version'=>'Versión de odontograma guardada','plan_created'=>'Plan de tratamiento creado','plan_item_added'=>'Procedimiento añadido al plan','procedure_status_changed'=>'Evolución de procedimiento registrada','payment_registered'=>'Cobro registrado','payment_voided'=>'Cobro anulado','stock_movement'=>'Movimiento de inventario registrado','inventory_item_created'=>'Material registrado','treatment_catalog_saved'=>'Catálogo de tratamientos actualizado','report_export'=>'Reporte exportado','attachment_uploaded'=>'Archivo clínico adjuntado','attachment_download'=>'Archivo clínico descargado','password_reset'=>'Acceso restablecido','password_changed'=>'Contraseña actualizada','role_permissions_updated'=>'Permisos del rol actualizados','role_created'=>'Rol creado','user_created'=>'Cuenta del equipo creada','user_activation_changed'=>'Estado de cuenta actualizado','dentist_saved'=>'Perfil profesional actualizado','settings_updated'=>'Configuración actualizada','demo_seeded'=>'Datos ficticios cargados'][$value] ?? 'Operación registrada'; }
function view(string $template, array $data=[]): void { extract($data, EXTR_SKIP); require ROOT . '/resources/views/' . $template . '.php'; }
function user(): ?array { return App\Services\Auth::user(); }
function can(string $permission): bool { return App\Services\Auth::can($permission); }
function setting(string $key, string $default=''): string { try { return App\Models\DB::one('SELECT value FROM settings WHERE name=?',[$key])['value'] ?? $default; } catch (Throwable) { return $default; } }
function selected(mixed $a, mixed $b): string { return (string)$a === (string)$b ? ' selected' : ''; }
function local_date(?string $value,string $format='d/m/Y H:i'): string { return $value ? (new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(env('APP_TIMEZONE','America/Guayaquil')))->format($format):'—'; }
function form_field_id(string $name,string $extra=''): string {
    if (preg_match('/(?:^|\s)id\s*=\s*([\'"])(.*?)\1/i',$extra,$match)) return $match[2];
    static $counts=[];
    $base='field-'.(trim((string)preg_replace('/[^a-z0-9_-]+/i','-',$name),'-') ?: 'input');
    $count=$counts[$base]=($counts[$base] ?? 0)+1;
    return $base.($count>1?'-'.$count:'');
}
function required_indicator(bool $required,string $id): string { return $required?'<span class="required-indicator" data-required-for="'.e($id).'" aria-hidden="true"> *</span>':''; }
function field(string $name,string $title,mixed $value='',string $type='text',bool $required=true,string $extra=''): void { $id=form_field_id($name,$extra); $idAttr=preg_match('/(?:^|\s)id\s*=/i',$extra)?'':' id="'.e($id).'"'; echo '<label for="'.e($id).'"><span class="field-label-text">'.e($title).required_indicator($required,$id).'</span><input'.$idAttr.' type="'.e($type).'" name="'.e($name).'" value="'.e($value).'" '.($required ? 'required ':'').$extra.'></label>'; }
function area(string $name,string $title,mixed $value='',bool $required=false): void { $id=form_field_id($name); echo '<label class="full" for="'.e($id).'"><span class="field-label-text">'.e($title).required_indicator($required,$id).'</span><textarea id="'.e($id).'" name="'.e($name).'" rows="3" maxlength="10000" '.($required ? 'required':'').'>'.e($value).'</textarea></label>'; }
function choose(string $name,string $title,array $options,mixed $value='',bool $required=true): void { $id=form_field_id($name); echo '<label for="'.e($id).'"><span class="field-label-text">'.e($title).required_indicator($required,$id).'</span><select id="'.e($id).'" name="'.e($name).'" '.($required ? 'required':'').'><option value="">Seleccionar</option>'; foreach($options as $key=>$text) echo '<option value="'.e($key).'"'.selected($value,$key).'>'.e($text).'</option>'; echo '</select></label>'; }
function patient_options(array $rows): array { $out=[]; foreach($rows as $p) $out[$p['id']]=$p['first_name'].' '.$p['last_name'].' · '.$p['code']; return $out; }
function label(string $value): string { return ['pending'=>'Pendiente','confirmed'=>'Confirmada','attended'=>'Asistió','completed'=>'Completado','cancelled'=>'Cancelado','no_show'=>'Inasistencia','planned'=>'Planificado','in_progress'=>'En curso','active'=>'Activo','inactive'=>'Inactivo','healthy'=>'Sano','caries'=>'Caries','restoration'=>'Restauración','missing'=>'Ausente','planned_procedure'=>'Procedimiento planificado','performed'=>'Procedimiento realizado','cash'=>'Efectivo','transfer'=>'Transferencia','card'=>'Tarjeta','pediatric'=>'Pediátrico · dentición temporal','mixed'=>'Mixto · dentición en transición','permanent'=>'Permanente · dentición definitiva',
    // Clinical workflow status of a treatment_plan_item — independent from its billing `status`.
    'proposed'=>'Propuesto','pending_acceptance'=>'Pendiente de aceptación','accepted'=>'Aceptado','scheduled'=>'Programado','paused'=>'Pausado','needs_followup'=>'Requiere seguimiento','referred'=>'Derivado','rejected'=>'Rechazado por el paciente',
    // Stage checklist status
    'not_applicable'=>'No aplica','needs_review'=>'Requiere revisión',
    // Specialty / priority / diagnosis category labels
    'general'=>'General','endodontics'=>'Endodoncia','restoration'=>'Restauración','periodontics'=>'Periodoncia','orthodontics'=>'Ortodoncia','prosthetics'=>'Prótesis e implantes','surgery'=>'Cirugía oral',
    'low'=>'Baja','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente',
    'dental'=>'Dental','pulpar'=>'Pulpar','periapical'=>'Periapical','periodontal'=>'Periodontal','oclusal'=>'Oclusal','otro'=>'Otro',
    'resolved'=>'Resuelto','superseded'=>'Reemplazado',
    'photo'=>'Fotografía','xray'=>'Radiografía','document'=>'Documento','other'=>'Otro',
][$value] ?? $value; }
function dentition_stage_label(string $value): string { return ['pediatric'=>'Pediátrica','mixed'=>'Mixta','permanent'=>'Permanente'][$value] ?? $value; }
function surface_label(string $value): string { return ['whole'=>'Diente completo','mesial'=>'Mesial','distal'=>'Distal','vestibular'=>'Vestibular','lingual'=>'Lingual / palatina','occlusal'=>'Oclusal / incisal'][$value] ?? $value; }
function badge(string $value): string { return '<span class="badge ' . e($value) . '">' . e(label($value)) . '</span>'; }
function icon(string $name, int $size=20): string {
    $paths = ['grid'=>'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z','users'=>'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M16 3.13a4 4 0 0 1 0 7.75','calendar'=>'M8 2v4 M16 2v4 M3 10h18 M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2','tooth'=>'M12 5C5 0 2 5 4 11c1 3 0 10 3 10 2 0 2-7 5-7s3 7 5 7c3 0 2-7 3-10 2-6-1-11-8-6Z','heart'=>'M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z','wallet'=>'M20 8H4V4h14v4 M3 8h18v13H3z M16 12h5v5h-5z','box'=>'m3 7 9-5 9 5-9 5-9-5Z M3 7v10l9 5 9-5V7 M12 12v10','chart'=>'M3 3v18h18 M7 16v-5 M12 16V7 M17 16V4','settings'=>'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M12 2v3 M12 19v3 M2 12h3 M19 12h3 M5 5l2 2 M17 17l2 2 M5 19l2-2 M17 7l2-2','arrow'=>'M5 12h14 M13 6l6 6-6 6','plus'=>'M12 5v14 M5 12h14','search'=>'M21 21l-5-5 M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16','logout'=>'M9 21H3V3h6 M13 8l5 4-5 4 M7 12h11','clock'=>'M12 8v4l3 2 M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20','check'=>'m5 12 4 4L19 6','menu'=>'M3 6h18 M3 12h18 M3 18h18','shield'=>'m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6l9-4Z m-4 9 3 3 5-5','file'=>'M14 2H4v20h16V8l-6-6Z M14 2v6h6 M8 13h8 M8 17h8'];
    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.($paths[$name] ?? $paths['tooth']).'"/></svg>';
}
