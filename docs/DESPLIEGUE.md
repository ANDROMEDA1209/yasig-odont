# Despliegue desde GitHub

No se ha publicado este proyecto ni se han configurado cuentas externas. La URL local no es una URL pública. Se requiere un alojamiento que ejecute PHP, MySQL persistente y, para adjuntos, disco persistente. Sites/Cloudflare Workers y GitHub Pages no ejecutan este backend PHP.

## Repositorio

Instalar dependencias desde `composer.lock`, no copiar vendor. Inicializar Git, revisar los archivos a publicar y subir a un repositorio de GitHub propio con los comandos del README. Configurar las credenciales del proveedor en su panel, no en archivos versionados.

## Railway con Docker y MySQL

1. Crear un proyecto Railway y un servicio desde el repositorio de GitHub. El proveedor detecta el `Dockerfile`, instala dependencias y expone Apache. Configurar el puerto de destino **80** al generar el dominio. La imagen escucha en 80 y no interpreta automáticamente la variable PORT.
2. Añadir un servicio MySQL administrado con almacenamiento persistente. Vincular sus variables con DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME y DB_PASSWORD en el servicio PHP. No exponer MySQL públicamente si no es necesario; preferir red privada. Usar DB_SSL_CA cuando la conexión remota requiera CA/TLS.
3. Añadir un volumen al servicio PHP montado en `/var/www/html/storage/private` para conservar adjuntos al reconstruir el contenedor. MySQL conserva registros y saldos en su propio volumen. Si se escala a varias réplicas, se necesita un almacén de sesiones compartido, un almacén compartido de adjuntos y revisar los bloqueos y respaldos; la configuración entregada es para una réplica.
4. Configurar APP_URL con el dominio HTTPS generado, APP_TIMEZONE=America/Guayaquil y SESSION_SECURE=true. Para demo pública separada: APP_ENV=demo, APP_DEMO=true. Para uso real: APP_ENV=production, APP_DEMO=false, APP_DEBUG=false, con una BD nueva sin datos demo.
5. Ejecutar una vez `php scripts/console.php migrate` mediante la consola del servicio, con el usuario de migraciones. Asegurar propiedad de storage para www-data. Ejecutar `php scripts/console.php demo` solo en la base de demo. En producción crear el administrador mediante `console.php admin` con contraseña por stdin.
6. Configurar MAIL_* con el servicio SMTP real y verificar un enlace de recuperación. Si el proveedor restringe SMTP, utilizar un servicio SMTP permitido; el procedimiento CLI sigue disponible.
7. Generar el dominio público, configurar `/health` como health check y ejecutar las verificaciones posteriores. Guardar la URL exacta resultante en la documentación de la instalación.

No se asume un plan gratuito permanente ni MySQL gratis. Revisar precios, límites, suspensión, egreso, almacenamiento y respaldos en el proveedor al contratar. Un contenedor sin volumen pierde los adjuntos al redeploy; MySQL sin persistencia pierde los datos. Un volumen no sustituye un respaldo y borrar el servicio o volumen puede destruir información.

Fuentes de configuración consultadas: [Dockerfiles Railway](https://docs.railway.com/builds/dockerfiles), [MySQL Railway](https://docs.railway.com/databases/mysql), [volúmenes al desplegar Compose](https://docs.railway.com/guides/docker-compose).

## cPanel o hosting compartido

Usar PHP con soporte y extensiones requeridas. Crear base y usuario desde MySQL Databases, asociarlos y copiar el nombre real (normalmente con prefijo de la cuenta) a DB_DATABASE/DB_USERNAME. Subir el código a un directorio fuera de public_html. Ejecutar Composer por SSH o construir vendor en un equipo compatible y subirlo sin secretos.

**Con control de DocumentRoot:** apuntar el dominio a `/home/CUENTA/yasig-odont/public`. Dar escritura únicamente a storage. Activar HTTPS, reescritura Apache y `.htaccess`.

**Sin control de DocumentRoot:** mantener app, bootstrap, config, database, resources, routes, storage, vendor y `.env` en `/home/CUENTA/yasig-odont` fuera de public_html. Copiar solo el contenido de `public/` a public_html y cambiar, únicamente en ese `index.php`, la ruta de bootstrap a:

```php
require '/home/CUENTA/yasig-odont/bootstrap/app.php';
```

No copiar el repositorio completo a public_html ni confiar exclusivamente en ocultar `.env`. Para la detección del logo, esta distribución requiere además conservar una copia de `public/assets/images/brand/logo.png` en el árbol privado cuando el oficial exista, o adaptar su comprobación de archivo al document root. Las URL de assets se calculan con APP_URL. Mantener ambas copias públicas sincronizadas durante actualizaciones.

Ejecutar migraciones mediante SSH o la terminal cPanel. No crear un instalador o consola pública HTTP. Configurar APP_ENV=production, SESSION_SECURE=true y APP_DEMO=false para la instalación real. Establecer el runtime `display_errors=Off` y almacenar logs fuera de la raíz pública.

## Verificación posterior

- `/health` responde 200 con `{"status":"ok"}`; una falla de DB devuelve 503 sin revelar credenciales.
- Sitio y todas las páginas públicas cargan sin imágenes ni fuentes remotas esenciales.
- `.env`, `/database/schema.sql`, `/storage/`, `/app/` y `/uploads/` no devuelven archivos internos.
- Login/Logout, CSRF y permisos funcionan; un usuario de recepción recibe 403 al abrir clínica o caja.
- Solicitud pública aparece pendiente en Agenda, se confirma y se impide un choque de horarios.
- Crear paciente ficticio y versión de odontograma, recargar y comprobar persistencia.
- Registrar un pago y un movimiento ficticios, exportar PDF/CSV y comprobar el saldo.
- Reiniciar el servicio y confirmar que registros y adjuntos siguen disponibles.
- Verificar dominio, HTTPS, cookies Secure/HttpOnly/SameSite y ausencia de errores visibles.

## Respaldo y restauración

Programar backups cifrados del MySQL y del volumen de adjuntos fuera del servidor principal, con acceso restringido y una política de retención. Usar las funciones de respaldo del proveedor o `mysqldump --single-transaction` con credenciales en un archivo protegido fuera del repositorio, nunca como argumentos compartidos. Mantener la correspondencia temporal entre datos y archivos. Ensayar restauración en una instalación aislada, ejecutar migraciones compatibles y verificar conteos, adjuntos y saldos antes de reabrir el acceso. La aplicación no contiene un botón de descarga pública de respaldos.
