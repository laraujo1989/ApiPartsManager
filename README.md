# Backend (Symfony 7.4)

API JSON de PartsHub: autenticación por sesión (cookie), flujo de solicitudes, inventario, importaciones, integraciones y exportación del Excel *Consolidation Form*. No sirve HTML: la interfaz es el [frontend](../frontend/README.md).

| | |
|---|---|
| PHP | 8.2 o superior, con `pdo_mysql`, `mbstring`, `intl`, `zip`, `gd`, `xml`/`dom`/`simplexml`/`xmlwriter`, `fileinfo`, `curl`, `ctype`, `iconv` |
| Base de datos | MySQL 5.7+/8 o MariaDB 10.4+ (se usa un índice FULLTEXT para buscar entre cientos de miles de partes) |
| Gestor | Composer 2 |

```
src/Controller/Api/   endpoints (/api/...)
src/Entity/           modelo Doctrine
src/Service/          flujo de aprobación, importadores, exportador Excel, búsqueda web
src/Message/          tareas en segundo plano (búsqueda web, recordatorios de PO)
src/Command/          app:seed-demo, app:follow-up-reminders
src/Migrations/       migraciones de la base de datos
resources/templates/  plantilla Excel "Template for Contract Lines"
var/uploads/          archivos subidos por los usuarios (hay que respaldarlo)
```

---

## En local (Windows + XAMPP)

1. Enciende **MySQL** desde XAMPP y crea la base de datos:
   ```sql
   CREATE DATABASE marsparts_symf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. La conexión local está en `.env.local` (no se sube a git). Si no existe, créalo:
   ```dotenv
   DATABASE_URL="mysql://root:@127.0.0.1:3306/marsparts_symf?serverVersion=mariadb-10.4.32&charset=utf8mb4"
   ```
3. Instala y prepara:
   ```bash
   composer install
   php bin/console doctrine:migrations:migrate -n
   php bin/console messenger:setup-transports        # tablas de la cola de tareas
   php bin/console app:seed-demo                     # usuarios por rol + datos de ejemplo (base vacía)
   ```
   Usuarios demo (clave `Demo1234`): `tr`, `lead`, `store`, `manager`, `superadmin`. `php bin/console app:seed-demo --users-only` los crea o restablece en una base que ya tiene datos.
4. Arranca el servidor. **Hay que usar `dev-router.php`**: sin él, el servidor integrado de PHP devuelve 404 en las URLs con extensión (p. ej. `/api/files/part/doc.pdf`).
   ```bash
   php -S 127.0.0.1:8000 -t public dev-router.php
   ```
5. (Opcional) Worker para la búsqueda web de partes y los recordatorios de PO, en otra terminal:
   ```bash
   php bin/console messenger:consume async scheduler_default -vv
   ```

La API queda en `http://127.0.0.1:8000/api`. Para ver la interfaz arranca también el frontend (`../frontend`), que usa un proxy hacia este puerto.

Comandos útiles: `php bin/console cache:clear`, `php bin/console doctrine:schema:validate`, `php bin/console app:follow-up-reminders` (envía ya los recordatorios de PO abiertos).

---

## En producción (aaPanel)

La guía completa del sitio (Nginx, SSL, frontend, worker) está en el [README de la raíz](../README.md#producción-en-aapanel). Aquí van los pasos propios del backend. Se asume la ruta `/www/wwwroot/partshub/backend` y PHP 8.2 de aaPanel (`/www/server/php/82/bin/php`).

### 1. Preparar PHP

*App Store → PHP 8.2 → Setting*:
- **Install extensions:** `fileinfo` e `intl` (las demás suelen venir). Comprueba con `/www/server/php/82/bin/php -m`.
- **Configuration file** (`php.ini`): `upload_max_filesize = 64M`, `post_max_size = 64M`, `memory_limit = 1024M`, `max_execution_time = 900`. Las cargas de materiales de SAP (cientos de miles de filas) son pesadas; ajusta según el tamaño de tus archivos.
- **Disable functions:** si Composer o la consola se quejan, quita `putenv`, `proc_open` y `proc_get_status` de la lista.

### 2. Base de datos

*Database → Add database*: nombre `partshub`, usuario propio, juego de caracteres **utf8mb4**. Anota usuario y clave.

### 3. Subir el código y las dependencias

Sube la carpeta `backend/` (sin `vendor/` ni `var/`) a `/www/wwwroot/partshub/backend`, por git o por zip. Desde *Terminal* de aaPanel:

```bash
cd /www/wwwroot/partshub/backend
export PATH=/www/server/php/82/bin:$PATH
composer install --no-dev --optimize-autoloader
```

### 4. Configuración de producción

Crea `backend/.env.local` (no se sube a git) con tus valores:

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<64 caracteres aleatorios>
DEFAULT_URI=https://partshub.tudominio.com
DATABASE_URL="mysql://partshub:CLAVE@127.0.0.1:3306/partshub?serverVersion=8.0.36&charset=utf8mb4"
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
# El frontend y la API comparten dominio, así que no hace falta abrir CORS a otros orígenes:
CORS_ALLOW_ORIGIN='^https://partshub\.tudominio\.com$'
# Correo (aún no se usa para recuperar contraseñas; déjalo así hasta tener SMTP):
MAILER_DSN=null://null
```

- Genera el secreto con `php -r "echo bin2hex(random_bytes(32));"`.
- En `serverVersion` pon la de tu motor (`8.0.36`, `5.7.44` o `mariadb-10.11.6`); la ves en *Database* de aaPanel o con `SELECT VERSION();`.
- **No dejes `APP_SECRET` vacío ni uses el de `.env.dev`:** firma las sesiones.

### 5. Crear las tablas y el primer administrador

```bash
php bin/console doctrine:migrations:migrate -n --env=prod
php bin/console messenger:setup-transports --env=prod
php bin/console app:seed-demo --admin-only --env=prod     # superadmin / Admin1234 (pide cambiarla al entrar)
```

Entra con ese usuario, cambia la clave y crea el resto desde **Users**. Los catálogos (clases, vendors, partes) se cargan desde **Settings → Data import**. No uses `app:seed-demo` sin `--admin-only` en producción: crea usuarios con claves conocidas.

### 6. Permisos y caché

El usuario de PHP-FPM en aaPanel es `www`. Todo lo que escribe PHP debe ser suyo, incluido lo que crees desde la terminal como root:

```bash
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
chown -R www:www var
chmod -R u+rwX var
```

Respalda `var/uploads/` junto con la base de datos.

### 7. Worker de tareas en segundo plano

Sin worker no funcionan la **búsqueda web de partes** ni los **recordatorios diarios de PO** (08:00). Con el plugin *Supervisor* de aaPanel, *Add Daemon*:

| Campo | Valor |
|---|---|
| Name | `partshub-worker` |
| Run user | `www` |
| Run directory | `/www/wwwroot/partshub/backend` |
| Start command | `/www/server/php/82/bin/php bin/console messenger:consume async scheduler_default --time-limit=3600 --memory-limit=256M --env=prod` |
| Processes | `1` |

El worker se reinicia solo cada hora (`--time-limit`). Sin Supervisor, al menos los recordatorios se pueden lanzar con una *Cron* de aaPanel diaria (`/www/server/php/82/bin/php /www/wwwroot/partshub/backend/bin/console app:follow-up-reminders --env=prod`), pero la búsqueda web no funcionará.

### 8. Actualizar a una versión nueva

```bash
cd /www/wwwroot/partshub/backend
# subir el código nuevo (git pull o zip), respetando var/ y .env.local
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate -n --env=prod
php bin/console cache:clear --env=prod
chown -R www:www var
php bin/console messenger:stop-workers --env=prod   # Supervisor lo vuelve a levantar con el código nuevo
```

### Comprobar que funciona

```bash
curl -i https://partshub.tudominio.com/api/me      # debe responder 401 con JSON (sin sesión)
```

Los errores quedan en `backend/var/log/prod.log`. Si ves 500 sin detalle, revisa ese archivo y el log de PHP del sitio. Los problemas habituales son permisos en `var/` y una extensión de PHP que falta.
