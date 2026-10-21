# Instalación

## PHP y MySQL

El código requiere PHP >=8.2. El runtime de despliegue debe ser una versión con soporte y parches actuales; Docker utiliza la rama 8.5. La versión local 8.2.12 fue comprobada, no se instaló ni se probó PHP 8.5 en este equipo. Dependencias de Composer fijadas en `composer.lock`.

Activar `pdo_mysql`, `mbstring`, `fileinfo`, `dom`, `gd`, `openssl`, `intl` y `zip`. Instalar Composer 2. En XAMPP iniciar MySQL. MariaDB 10.4.32 local acepta las tablas InnoDB, claves foráneas y restricciones utilizadas. Validar el proveedor MySQL antes de migrar una instalación real.

Crear una base nueva desde phpMyAdmin o la consola de MySQL:

```sql
CREATE DATABASE yasig_odont CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'yasig'@'localhost' IDENTIFIED BY 'REEMPLAZAR_POR_UN_SECRETO_ALEATORIO';
GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP,REFERENCES ON yasig_odont.* TO 'yasig'@'localhost';
```

El ejemplo de permisos incluye migración y reinicio de demo. En producción separar el usuario de migraciones del usuario de ejecución, retirando CREATE/ALTER/DROP e impidiendo UPDATE/DELETE de las tablas de auditoría, entradas clínicas firmadas y versiones de odontograma. El host de la cuenta debe coincidir con DB_HOST; para el desarrollo con TCP se puede usar `127.0.0.1` en ambos.

Copiar `.env.example` a `.env`, completar DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME y DB_PASSWORD. No agregar `.env` a Git.

```powershell
composer install --prefer-dist --no-interaction
php scripts/console.php migrate
php scripts/console.php demo
```

La migración inicial utiliza `database/schema.sql`; las siguientes aplican cambios incrementales. `migrations` registra la versión. Ejecutar nuevamente `migrate` es seguro en una instalación ya inicializada. No importar el SQL y ejecutar la migración inicial sin registrar la versión: el procedimiento recomendado es siempre el comando `migrate`.

## Servir únicamente public/

Para desarrollo:

```powershell
php -S 127.0.0.1:8080 -t public public/router.php
```

Configurar `APP_URL=http://localhost:8080`. Para Apache utilizar `config/xampp-vhost.conf`, añadir `127.0.0.1 yasig.local` al archivo hosts y configurar `APP_URL=http://yasig.local`. Esta entrega no modificó la configuración global de Apache ni el archivo hosts.

La raíz del repositorio devuelve denegación por `.htaccess`. No quitar esa protección para abrir el proyecto en `http://localhost/dental_care`. El punto de entrada debe ser `public/`. El router incluido sirve para el servidor de desarrollo de PHP; Apache usa `public/.htaccess`.

Dar permisos de escritura al usuario PHP solamente sobre `storage/`. Los adjuntos no se escriben en `public/uploads`; se guardan en `storage/private` con nombres aleatorios y se descargan por una ruta autenticada.

## Administración inicial y recuperación

```powershell
php scripts/console.php admin administrador@dominio.ec "Administración"
```

La contraseña se lee de stdin (12–72 caracteres), no como argumento. En algunas terminales se verá mientras se escribe: usar una terminal privada o una entrada protegida. En la interfaz: nombre de usuario → Mi cuenta → cambiar contraseña.

Sin SMTP, el operador con acceso autorizado al servidor ejecuta:

```powershell
php scripts/console.php reset-link administrador@dominio.ec
```

El enlace contiene un token aleatorio, de un uso y 30 minutos de validez. Entregar solo al titular por un canal privado. La web no muestra tokens y responde sin revelar si existe una cuenta. Con MAIL_* configurado, envía el enlace por SMTP/TLS.

## Variables

| Variable | Uso |
| --- | --- |
| APP_ENV | local, testing, demo o production |
| APP_DEMO | true solo para una instalación ficticia separada |
| APP_DEBUG | detalle de errores solo fuera de producción; falso por defecto |
| APP_URL | URL completa, incluyendo subdirectorio si existe |
| APP_TIMEZONE | America/Guayaquil |
| APP_KEY | reservado para futuras funciones criptográficas; no cifra actualmente los registros |
| DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD | conexión MySQL |
| DB_SSL_CA | ruta a certificado CA para MySQL remoto con TLS |
| SESSION_SECURE | true con HTTPS; obligatorio en production |
| MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM | SMTP para recuperación |
| CLINIC_ADDRESS, CLINIC_PHONE, CLINIC_EMAIL, CLINIC_WHATSAPP, CLINIC_HOURS | valores iniciales, reemplazables en Configuración |
| MYSQL_PASSWORD, MYSQL_ROOT_PASSWORD | solo para Compose, distintos y aleatorios |

Los valores del proceso tienen precedencia sobre `.env`; los valores editados en Configuración tienen precedencia sobre CLINIC_*.
