# Seguridad y límites

## Medidas implementadas

PDO con consultas preparadas nativas y listas explícitas de campos; validación de opciones, fechas, importes y relaciones; escape HTML; CSRF para toda modificación; contraseñas con password_hash/password_verify; sesiones regeneradas, expiración por inactividad, HttpOnly/SameSite y Secure configurable, obligatorio en producción. Cabeceras CSP, nosniff, frame-ancestors, X-Frame-Options, Referrer-Policy y HSTS con HTTPS configurado.

Login y recuperación limitados en MySQL por cuenta/IP con bloqueo de concurrencia. Tokens de recuperación y recordatorio guardados como SHA-256, con aleatoriedad criptográfica, caducidad y revocación. El recordatorio se rota al recuperar una sesión. Cambio/restablecimiento/desactivación revoca sesiones mediante session_version. Sin registro público de administradores.

Roles comprobados antes de cada ruta administrativa y POST. Los antecedentes y alergias se separan del listado general de pacientes. Consulta de historias y descargas clínicas se auditan. Los logs no contienen texto clínico ni contraseñas. El personal de caja puede consultar reportes operativos; revisar esta atribución según la organización antes de producción.

Registros clínicos firmados y odontogramas anteriores se conservan; las correcciones crean entradas nuevas y los antecedentes se guardan junto a cada firma. La edición del odontograma verifica la versión vigente. Las cuentas con acceso directo de administrador a MySQL podrían alterar datos: usar permisos mínimos y respaldos auditados.

Citas asignadas bloquean la fila del profesional en una transacción antes de comprobar solapamientos. Pagos bloquean el plan, calculan saldo y generan comprobante en una misma transacción. Movimientos bloquean material y lote. Importes se almacenan DECIMAL; los cálculos de autorización de pagos se realizan en centavos enteros. Anulaciones conservan el registro y su motivo.

Adjuntos PDF/JPG/PNG: límite de 5 MB, extensión y MIME reales, comprobación básica de imagen, nombre aleatorio, is_uploaded_file/move_uploaded_file, fuera del document root y descarga autenticada como attachment. No se admite SVG/HTML/scripts. El directorio public/uploads está denegado. No hay motor antivirus ni desarme de PDF: añadir análisis de malware según el riesgo y aislar los lectores de archivos. El servidor debe limitar también tamaño de POST y carga.

## Demo y datos de salud

La demo contiene únicamente personas inventadas, documentos FICTICIO y correos `.demo`/`example.invalid`. APP_DEMO habilita un aviso visible y los seeders; el restablecimiento exige permiso settings, sesión, CSRF, texto de confirmación y diálogo. El CLI exige `--confirm-demo`. Está bloqueado con APP_ENV=production. El botón elimina datos y adjuntos de la instalación demo completa y conserva catálogos/configuración.

No afirmar cumplimiento automático de la Ley Orgánica de Protección de Datos Personales. El marco vigente debe revisarse en la [SPDP](https://www.spdp.gob.ec/marco-normativo/) junto con sus [guías](https://www.spdp.gob.ec/docs/guias/) y normativa aplicable al responsable. Las medidas de software no reemplazan bases legales, consentimiento cuando corresponda, contratos con encargados, gestión de derechos, evaluación de transferencias ni políticas organizativas.

## Checklist de producción

- [ ] Validación profesional del odontograma, superficies, FDI, flujos y documentos clínicos.
- [ ] PHP/DB/SO con parches actuales; revisar [soporte PHP](https://www.php.net/supported-versions.php). La versión XAMPP verificada es antigua y no debe convertirse en runtime real sin actualización.
- [ ] BD nueva; sin usuarios, datos ni contraseñas demo. APP_ENV=production, APP_DEMO=false, APP_DEBUG=false.
- [ ] Cuenta administrativa individual y contraseñas distintas. Revisar permisos de cada rol; desactivar cuentas sin uso.
- [ ] DocumentRoot exclusivamente public, HTTPS y SESSION_SECURE=true. Aislar phpMyAdmin y MySQL de Internet.
- [ ] Secretos del proveedor fuera de Git; usuario runtime con mínimo privilegio; usuario de migración distinto.
- [ ] MySQL remoto por red privada y TLS validado si corresponde. Disco y respaldos cifrados mediante el proveedor: la aplicación no cifra a nivel de campo ni utiliza APP_KEY para cifrado.
- [ ] Storage privado persistente, permisos de SO restrictivos y backups cifrados con prueba de restauración.
- [ ] SMTP probado, remitente verificado y recuperación administrativa documentada.
- [ ] Política de privacidad definitiva, responsable y contacto, fines/bases legales, retención y procedimiento de acceso/rectificación/eliminación aplicable. La página incluida es una plantilla identificada como pendiente.
- [ ] Inventario de tratamientos de datos, evaluación de riesgos, contratos/transferencias y revisión profesional de obligaciones ecuatorianas.
- [ ] Procedimiento de incidentes, registro y monitoreo; purga periódica de intentos y tokens vencidos según retención definida.
- [ ] Revisión independiente de seguridad y pruebas tras el despliegue, incluidas concurrencia real, autorización, HTTPS y restauración.
- [ ] Sustituir fotografías, profesionales, contacto y logotipo por información autorizada y verificada.

## Riesgos y funciones no incluidas

Una réplica PHP y sesiones de archivos locales. No MFA, cifrado de campos, firma electrónica certificada, antivirus, almacenamiento externo automático, facturación SRI ni registro legal de consentimientos clínicos. El bloqueo de agenda usa la disciplina de la aplicación: herramientas externas que escriban directamente en appointments pueden eludirlo. Un usuario con permisos clínicos puede consultar todas las historias; no hay asignación por paciente. La demo no se ha sometido a pentest independiente ni a validación médica. Los tests no prueban invulnerabilidad.
