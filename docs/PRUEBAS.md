# Verificación

Entorno real comprobado el 20/10/2026: Windows/XAMPP, PHP 8.2.12, PDO MySQL, MariaDB 10.4.32, Composer 2.10.2 y Chromium headless. Pruebas sobre **yasig_odont_test**, sin modificar los datos demo de **yasig_odont**.

## Integración PHP / MySQL

```powershell
php tests/run.php
```

**115 pruebas correctas, 0 fallidas.** Reconstruyen solamente yasig_odont_test. Se verificaron: consulta sin registros, FDI, hashes, login/logout, cinco roles, pacientes/duplicados/edición, validación, XSS, consultas parametrizadas, CSRF token, creación/reprogramación/cancelación de citas, solapamientos, horarios, solicitud pública pendiente/idempotente/consentimiento, firma/corrección/antecedentes históricos, odontograma versionado y edición obsoleta, FK FDI, planes, pagos parciales/idempotentes/saldos/anulaciones/sobrepagos, stock/lotes/vencimientos, validación MIME/tamaño, siete reportes, integridad, auditoría, tokens de recuperación de un solo uso y límite de intentos. El dashboard añade verificación de sincronización entre totales y gráficas, filtros, separación financiera/clínica, detalles autorizados, base vacía y rechazo de reintentos con importes diferentes.

La regresión del odontograma añade recuperación por paciente sin crear registros, carga completa de superficies/notas, rechazo de versiones cero o ajenas, primer registro explícito, guardado sin cambios sin duplicados, protección contra payload ausente/incompleto/vaciado, actualización con historial intacto, nueva evaluación explícita con copia del estado vigente y consulta de procedimientos realizados por pieza. No requiere migraciones ni tablas nuevas.

Concurrencia: el test lanza dos procesos PHP independientes contra MySQL para reservar el mismo horario y profesional; comprueba un resultado creado y uno rechazado por conflicto.

## Navegador

Instalar herramientas de QA fuera del código de producción:

```powershell
npm install --prefix storage/qa @playwright/test @axe-core/playwright --no-save
```

Crear la base de pruebas y ejecutar el test PHP anterior. En otra terminal:

```powershell
$env:DB_DATABASE='yasig_odont_test'
php -S 127.0.0.1:8081 -t public public/router.php
```

Luego:

```powershell
# Si Chromium ya está instalado, indicar su ejecutable; si no, instalarlo con Playwright.
$env:PLAYWRIGHT_CHROMIUM_EXECUTABLE='RUTA_AL_CHROMIUM'
node tests/browser.cjs
```

Recorrido específico de regresión, también comprobado en Chromium:

```powershell
node tests/odontogram-browser.cjs
```

Verifica crear el primer registro, buscar y volver a seleccionar al paciente, apertura de la superficie previamente guardada, actualización de hallazgos realizados, consulta histórica de solo lectura, nueva evaluación confirmada, rechazo de vaciado por POST directo y recuperación de procedimientos por pieza. Utiliza la base aislada preparada por `tests/run.php`.

El script tiene una ruta de respaldo para el Chromium existente en este equipo. En otro sistema, definir la variable o ejecutar el CLI de Playwright desde storage/qa para instalar su versión de Chromium.

Se comprobaron páginas públicas, 404, rechazo CSRF HTTP 419, login real, todas las pantallas administrativas, paciente creado en UI, firma clínica confirmada, 32/20 dientes SVG, guardado y recarga del odontograma, creación y reprogramación en agenda, solicitud pública visible en panel, cobro y movimiento, subida multipart y descarga privada, PDF y CSV reales, y HTTP 403 para recepción en clínica/caja/usuarios/adjuntos.

Revisión de desborde en **1440, 390 y 320 px** para inicio, login, dashboard, odontograma, agenda, pacientes y reportes. Sin errores JavaScript. Capturas de escritorio/móvil guardadas en storage/qa-*.png (fuera del repositorio).

Axe WCAG 2 A/AA y 2.1 AA en inicio, login, dashboard y odontograma a 390 px: **sin violaciones detectadas** tras corregir contraste y foco de tablas desplazables. Este resultado automático no sustituye pruebas manuales con lectores de pantalla, dispositivos físicos o validación clínica.

## Dependencias y sintaxis

`composer audit`: sin avisos de vulnerabilidad encontrados para las dependencias instaladas. PHP lint ejecutado para todos los archivos propios. No se probó el envío SMTP con un servicio real. Dockerfile y Compose revisados; construcción no ejecutada porque el daemon de Docker Desktop no estaba disponible. No hay despliegue público que permita afirmar verificaciones sobre Railway/cPanel.
