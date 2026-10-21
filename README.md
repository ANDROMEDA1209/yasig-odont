# Yasig Odont — Family Dental Care

Aplicación PHP/MySQL de odontología integral: sitio público y sistema privado en el mismo dominio. Interfaz en español, fechas en `America/Guayaquil`, almacenamiento de fechas y horas en UTC.

## Abrir la instalación local preparada

Desde `C:\xampp\htdocs\dental_care`, iniciar MySQL desde XAMPP y ejecutar:

```powershell
php -S 127.0.0.1:8080 -t public public/router.php
```

Abrir **http://localhost:8080**. Acceso del equipo: **http://localhost:8080/login**.

| Cuenta de demostración | Correo |
| --- | --- |
| Administrador | admin@yasig.demo |
| Odontólogo A / B | odontologo1@yasig.demo / odontologo2@yasig.demo |
| Recepción | recepcion@yasig.demo |
| Asistente | asistente@yasig.demo |
| Caja | caja@yasig.demo |

Contraseña de todas las cuentas demo: **`Demo-Yasig-2026!`**. Cambiar desde el nombre de usuario → Mi cuenta. No utilizar estas cuentas en producción. La configuración local `.env` está excluida de Git; la contraseña MySQL se generó aleatoriamente.

## Nueva instalación

Requiere PHP 8.2 o superior, Composer 2, MySQL 8.x o MariaDB compatible, PDO MySQL, mbstring, fileinfo, DOM y GD. El entorno comprobado usa **PHP 8.2.12**, **MariaDB 10.4.32**, **Composer 2.10.2** y **Node 24.19.0** para pruebas del navegador. Estas versiones antiguas de XAMPP son para desarrollo; actualizar el runtime antes del uso real.

```powershell
composer install --no-interaction --prefer-dist
Copy-Item .env.example .env
# Crear la base y un usuario MySQL dedicado; completar DB_* en .env.
php scripts/console.php migrate
php scripts/console.php demo
php -S 127.0.0.1:8080 -t public public/router.php
```

El comando `migrate` crea las tablas, carga roles, permisos, dentición FDI y catálogos básicos. `demo` carga exclusivamente datos ficticios. En una instalación real, no ejecutar `demo`: crear un administrador con `php scripts/console.php admin correo@dominio.ec "Nombre"` y entregar su contraseña por stdin. Consulta [instalación completa](docs/INSTALACION.md).

## Funcionalidades

- Sitio público responsive: inicio, nosotros, servicios y detalle, equipo, galería, contacto, privacidad, solicitud de cita y 404.
- Citas web persistentes, pendientes de revisión, con consentimiento, CSRF, limitación de frecuencia y clave de envío para evitar duplicados.
- Autenticación, recuperación con tokens de un solo uso, recordatorio de acceso con tokens rotatorios, expiración, cambio de contraseña y revocación de sesiones.
- Dashboard con actualización cada 15 segundos, filtros de período/paciente, indicadores animados, gráfica explorable y accesible, próximas citas, alertas, movimientos financieros separados de actividad clínica, búsqueda, estados, paginación y panel de detalle autorizado. [Funcionamiento y pruebas](docs/DASHBOARD.md).
- Pacientes con código único, duplicados controlados, filtros, paginación, edición y ficha.
- Agenda diaria, semanal y mensual; asignación de paciente/profesional/servicio; confirmación, reprogramación, asistencia, finalización, cancelación e inasistencia. Bloqueo transaccional del profesional contra reservas simultáneas.
- Historia clínica con firma, autor y fecha; correcciones nuevas que conservan la entrada original y los antecedentes de cada firma; adjuntos privados PDF/JPG/PNG.
- Odontograma SVG FDI de 32 piezas permanentes y 20 temporales, superficies, hallazgos, notas, estados planificados y realizados, versiones y control de edición concurrente.
- Catálogo editable, planes, procedimientos, presupuesto, estado y evolución. Los procedimientos nuevos permanecen planificados.
- Pagos parciales, saldo, comprobantes internos imprimibles, idempotencia y anulaciones auditadas; sin facturación tributaria.
- Inventario por material y lote, vencimiento, entradas/salidas, prevención de stock negativo y alertas.
- Usuarios, activación/desactivación, roles personalizados, permisos por módulo y auditoría.
- Siete reportes por fecha y exportación real CSV/PDF.
- Configuración pública y de profesionales, demo reproducible y restablecimiento administrativo protegido.

## Estructura

```text
app/          Controllers, Models, Services, Middleware, Validators, Helpers
bootstrap/    carga de entorno, sesión, seguridad y autoload
config/       aplicación, DB, seguridad, PHP y Apache/XAMPP
database/     schema.sql, migraciones versionadas, seeders base/demo
docs/         instalación, despliegue, manual, seguridad, pruebas y recursos
public/       único directorio HTTP: index.php, router.php, assets, uploads bloqueado
resources/    vistas públicas, autenticación, panel, layouts y componentes
routes/       definición de rutas
scripts/      consola de instalación, administración y recuperación
storage/      logs, sesiones, caché, adjuntos privados y respaldos (fuera de Git)
tests/        pruebas de integración y navegador
```

La identidad utiliza los originales suministrados: `public/assets/images/gallery/Logo.jpeg` y `public/assets/images/gallery/banner.jpeg`, completos y adaptados a escritorio y móvil. Las fotografías locales son referencias con créditos y licencia en [recursos](docs/RECURSOS.md).

## Pruebas

```powershell
php tests/run.php
composer audit
```

El test PHP usa únicamente la base **`yasig_odont_test`**, que reconstruye. Debe existir y el usuario de desarrollo debe tener permisos sobre ella. Nunca ejecutar pruebas sobre una base real. Pruebas visuales y funcionales con Chromium/Playwright: [procedimiento y resultados](docs/PRUEBAS.md).

## Docker

El Dockerfile usa PHP 8.5/Apache y Composer; Compose usa MySQL 8.4 con volumen persistente. En `.env`, añadir `MYSQL_PASSWORD` y `MYSQL_ROOT_PASSWORD` con valores aleatorios diferentes:

```powershell
docker compose up -d --build
docker compose exec --user www-data app php scripts/console.php migrate
docker compose exec --user www-data app php scripts/console.php demo
```

No usar `docker compose down -v` si necesitas conservar datos. La construcción Docker está preparada pero no se ejecutó: Docker Desktop no estaba iniciado. La aplicación sí se verificó con PHP y MariaDB locales.

## GitHub y URL pública

```powershell
git init
git add .
git status --short
git commit -m "Implementar Yasig Odont"
git branch -M main
git remote add origin https://github.com/TU_USUARIO/yasig-odont.git
git push -u origin main
```

Revisar que `.env`, `storage`, adjuntos y `vendor` no estén incluidos. No se creó un repositorio remoto ni una URL pública: faltan una cuenta de alojamiento PHP/MySQL, su base persistente y el repositorio de destino. GitHub Pages no ejecuta PHP. [Despliegue Railway y cPanel](docs/DESPLIEGUE.md).

## Alcance y pendientes

Es una aplicación funcional de demostración, no un sistema clínico certificado. Antes de utilizar datos reales: validar el modelo odontológico con un profesional, completar la identidad y datos del consultorio, revisar la política de privacidad y obligaciones locales, actualizar PHP/BD, configurar HTTPS/SMTP, crear una BD limpia, definir conservación y respaldos, probar recuperación y efectuar una revisión de seguridad independiente. La entrega no afirma certificación ni cumplimiento legal automático. [Seguridad y checklist](docs/SEGURIDAD.md).

Limitaciones actuales: horario fijo lunes a sábado por profesional, sin feriados ni turnos partidos; sin firma electrónica certificada, facturación SRI, WhatsApp API, procesamiento bancario, recetas, integración radiológica ni portal de pacientes. El correo SMTP está implementado pero no se verificó con un proveedor real. Los reportes exportan el resultado completo, por lo que grandes volúmenes requieren límites o trabajos en segundo plano.
