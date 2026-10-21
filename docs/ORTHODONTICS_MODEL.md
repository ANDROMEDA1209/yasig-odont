# Modelo e integración de Ortodoncia

El módulo se integra en `/admin/orthodontics`. No duplica pacientes, historias, agenda, odontogramas, archivos ni caja. La migración `006_orthodontics.php` es aditiva y puede repetirse tras una ejecución interrumpida. No elimina registros existentes y no carga pacientes ni estadísticas de demostración.

## Entidades canónicas reutilizadas

| Información | Origen |
| --- | --- |
| Paciente, identificación, nacimiento | `patients` |
| Antecedentes e identificación de historia | `clinical_records`, una por paciente |
| Profesional y cuenta asociada | `dentists.user_id` → `users` |
| Estado dental observado e historial | `odontograms`, `odontogram_findings`, `Clinic::odontogramState` |
| Presupuesto, procedimientos y cobros | `treatment_plans`, `treatment_plan_items`, `payments` |
| Fases individualizadas | `treatment_stages`, `treatment_stage_templates` |
| Evolución firmada de cada visita | `treatment_sessions` |
| Citas, disponibilidad, asistencia y reprogramación | `appointments`, `Clinic::appointment` |
| Archivo original privado y validación MIME | `attachments`, `Uploads` |
| Auditoría de acceso y operaciones | `audit_logs` |

`orth_cases.treatment_plan_item_id` es obligatorio y único. Al abrir un caso se crea un plan y un procedimiento de especialidad `orthodontics` en el modelo financiero existente, utilizando el catálogo `ORT`. Los cobros se registran por la caja existente. Los cambios de estado clínico nunca alteran el estado financiero ni simulan un cobro.

## Tablas específicas

| Tabla | Propósito y conservación |
| --- | --- |
| `orth_cases` | Cabecera del episodio, responsable, aparatología, estado, fechas, criterios de cierre y retención. |
| `orth_case_members` | Asistentes explícitamente asignados, quién los asignó y revocación. |
| `orth_diagnoses` | Evaluaciones estructuradas inmutables, encadenadas por `previous_id`. Las medidas opcionales indican milímetros y permanecen vacías si no se registraron. |
| `orth_plan_versions` | Versiones inmutables del contenido del plan, borrador o autorizado, autor, motivo de cambio y autorización profesional. Autorizar una versión conserva su contenido. |
| `orth_controls` | Metadatos de cada `treatment_session`: fase, siguiente control y cita del mismo caso. |
| `orth_control_addenda` | Correcciones firmadas que conservan íntegra la evolución original. |
| `orth_case_appointments` | Puente a la agenda existente. No es una agenda adicional. |
| `orth_attachments` | Clasificación fotográfica/documental y vínculo del archivo original con caso, fase o control. |
| `orth_consents` | Eventos de consentimiento otorgado/revocado con fecha, autor y documento opcional. |
| `orth_tasks` | Pendientes reales, prioridad, fecha y autor de resolución. |
| `orth_assistant_proposals` | Borradores del asistente, texto original, texto revisado, modelo, usuario y validación/descarte. |
| `orth_history` | Eventos clínicos con fecha, autor, referencia, motivo y estado anterior/siguiente. El historial de fases conserva sus notas anteriores completas. |

Las tablas usan claves foráneas e índices por caso/profesional/fechas. `orth_cases.live_record_id` es una columna generada con índice único que impide dos episodios abiertos para la misma historia incluso con solicitudes concurrentes. La selección o reapertura habitual devuelve el caso existente. Crear otro episodio requiere `confirm_new_episode` y que el anterior esté cerrado.

## Autorización

Se crean los permisos `orthodontics` y `orthodontics_assist` mediante la migración, tanto en bases ya instaladas como en instalaciones nuevas. Las capacidades clínicas usan el permiso `clinical` existente.

- Administrador o usuario autorizado para administrar usuarios: acceso global dentro del módulo.
- Profesional clínico: únicamente los casos cuyo responsable tenga su `dentists.user_id`.
- Asistente: requiere ambos permisos de ortodoncia y una fila activa en `orth_case_members`. Puede consultar y gestionar tareas o archivos autorizados; no puede firmar diagnósticos, planes, evoluciones o cambios clínicos.
- Recepción y caja no reciben acceso al módulo por defecto.

La misma comprobación se aplica al detalle, filtros, métricas y asistente. `authorizeAttachment` reconoce tanto los metadatos fotográficos como los archivos que ya estaban vinculados al procedimiento ortodóntico. Las rutas generales de archivos y tratamiento deben llamar estos controles para impedir eludirlos cambiando la URL.

## Operaciones y versiones

`Orthodontics::action(array $data): int` devuelve siempre el identificador del caso. Acciones: `create_case`, `diagnosis`, `plan`, `authorize_plan`, `status`, `control`, `addendum`, `phase`, `create_stage`, `consent`, `task`, `task_status`, `member`.

Todas las modificaciones toman un bloqueo de la fila del caso y se ejecutan en una transacción. Los formularios pueden enviar `version` para evitar sobrescribir una actualización concurrente y `previous_id` para diagnóstico/plan. Guardar una evaluación o un plan idéntico no añade versiones clínicas repetidas. En controles, `request_key` evita un reenvío: el servidor compara también una huella del contenido y rechaza reutilizar la misma clave para datos distintos.

Los controles exigen `confirm_sign`, fecha real, observaciones y relaciones válidas del mismo paciente/profesional. No se permite registrar en el futuro ni antes de abrir el caso. Una visita histórica incorporada posteriormente no sobrescribe la fecha de próximo control de una visita más reciente. Las correcciones son adendas, nunca una actualización destructiva de la sesión original.

Autorizar un plan requiere `confirm_authorization`, objetivos y criterios de finalización. Los borradores posteriores no cambian el plan autorizado vigente. Finalizar clínicamente o comenzar retención requiere un plan autorizado, criterios registrados y `confirm_completion`. Retención exige además tipo de retenedor e indicaciones. La duración estimada no finaliza tratamientos automáticamente.

Las fases iniciales son un punto de partida operativo; pueden marcarse como no aplicables y pueden añadirse fases individualizadas con `create_stage`. No constituyen un protocolo clínico universal ni infieren tratamientos.

## Datos de interfaz y configuración

`listing($filters)` devuelve casos paginados, opciones, profesionales, pacientes institucionales disponibles, métricas y gráficas calculadas desde MySQL. Admite búsqueda, estado, tipo, profesional, período, orden y página. No genera estadísticas ficticias. Las gráficas sin datos devuelven colecciones vacías.

`detail($caseId)` devuelve caso, historia, versiones diagnósticas y de plan, controles/adendas, fases, fotografías/documentos, citas, consentimientos, tareas, cambios, odontograma y saldo real del plan vinculado.

`options()` incluye mapas `states`, `types`, `photo_categories`, `appliances` y `consent_kinds`. El registro `settings.name = orthodontics_options` puede añadir claves y etiquetas de estados/tipos/categorías mediante JSON; las claves de los estados clínicos centrales mantienen su significado. Ejemplo:

```json
{"types":{"functional":"Ortopedia funcional"},"photo_categories":{"study":"Modelo de estudio"}}
```

No contiene credenciales de IA ni envía automáticamente información clínica a terceros. La integración externa, si se configura, debe conservar el control de consentimiento y autorización del caso. Los documentos derivados de IA permanecen identificados como borradores hasta su revisión; aceptar un texto no ejecuta procedimientos clínicos.

## Instalación y conservación

Aplicar `php scripts/console.php migrate` con el usuario de migraciones habitual. Hacer respaldo de MySQL y `storage/private` con las políticas existentes. No ejecutar `demo-reset` sobre una instalación con información que deba conservarse.

Las pruebas usan `yasig_odont_test`, separada de la base de trabajo. Esta implementación ofrece controles de acceso y trazabilidad técnica; las políticas de consentimiento, conservación, derechos de los pacientes y validación clínica corresponden a la organización y al profesional responsable.
