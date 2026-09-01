# Spec: Despliegue en producción (VPS propio, migrado desde Hostinger compartido)

> **Migración (posterior al arranque en limpio original)**: este sistema nació en el hosting
> compartido de Hostinger (sección "Arranque en limpio original" más abajo, conservada por su
> contexto). El **1 de septiembre de 2026** se migró por completo a un VPS propio (Hostinger VPS,
> Ubuntu 24.04) y el hosting compartido quedó retirado para este subdominio — no es una convivencia
> temporal, es la mudanza definitiva. Motivo: el hosting compartido no permite que la aplicación
> haga `CREATE DATABASE` / `DROP DATABASE` en caliente (el usuario MySQL de la cuenta solo tiene
> privilegios sobre bases registradas a mano vía hPanel), y eso rompe una capacidad central del
> sistema — `stancl/tenancy` crea una base de datos física por cada tenant nuevo
> (`TenantController::store()` → evento `TenantCreated` → jobs `CreateDatabase`/`MigrateDatabase`).
> El síntoma en producción: los primeros intentos de crear un tenant fallaron con
> `Access denied ... CREATE DATABASE`. En el VPS, el usuario de MySQL tiene privilegios reales sobre
> el prefijo `delivery_tenant_%`, y el incidente quedó verificado como resuelto (crear y borrar un
> tenant de prueba, ambos sin error). Todo lo que sigue bajo "Topología", "Requisitos del servidor"
> e "Instalación" describe el **estado actual (VPS)**; la sección final conserva el contexto
> histórico de Hostinger sin actualizar, para no perder el porqué de las decisiones de esa época.

## Historia de usuario

Como desarrollador único del sistema, quiero publicar inDriver en un servidor propio bajo
`https://delivery.prosello.com.mx`, con privilegios reales de base de datos (necesarios para que
`stancl/tenancy` funcione), de forma que el sistema quede utilizable desde internet y que volver a
desplegar un cambio sea un procedimiento escrito y repetible en vez de una serie de decisiones
improvisadas cada vez.

## Objetivo / Alcance

Dejar en el repositorio los artefactos de despliegue (front controller de producción, server block
de Nginx, script de aprovisionamiento del VPS, plantilla de variables de entorno, scripts de
subida/verificación y el procedimiento paso a paso) para el scaffolding que dejó
[001-inicio-proyecto.md](001-inicio-proyecto.md): Laravel API + Vue 3 SPA.

**Esta spec no cambia ninguna regla de negocio.** Todo lo que toca es infraestructura y
configuración.

### El sistema se sirve desde un solo origen

Backend y frontend son dos aplicaciones desacopladas (ver `001-inicio-proyecto.md`), pero en
producción se publican bajo **el mismo host**:

```
https://delivery.prosello.com.mx/            → el SPA (dist/ de Vite)
https://delivery.prosello.com.mx/api/v1/*    → Laravel
https://delivery.prosello.com.mx/sanctum/*   → Laravel (cookie CSRF)
https://delivery.prosello.com.mx/up          → Laravel (healthcheck)
```

La autenticación es Sanctum por **cookie de sesión** (`EnsureFrontendRequestsAreStateful`), y
separar SPA y API en dos hosts (`app.` + `api.`) obligaría a mantener una lista de orígenes en
`config/cors.php`, a emitir la cookie visible para cualquier subdominio del dominio, y a pagar un
`preflight` en cada `POST`. `config/cors.php` no se toca: con un solo origen el navegador no manda
`Origin` en peticiones del mismo host y el middleware de CORS no interviene; sigue como está para
que el proyecto funcione en local contra el dev server de Vite.

## Topología en el servidor (VPS)

```
/var/www/
└── delivery.prosello.com.mx/
    ├── backend/                   ← código de Laravel, fuera del docroot
    └── public_html/               ← docroot: SPA + front controller
```

El código de Laravel vive fuera del docroot por el mismo motivo que en el hosting compartido: si
viviera dentro, `.env`, `storage/logs/laravel.log` y `composer.lock` serían descargables por URL.

Pila: **Nginx + PHP-FPM 8.3** (no Apache — el VPS da control total del servidor, y Nginx rinde mejor
con la RAM disponible), **MySQL 8** con un usuario de aplicación con privilegios reales de
`CREATE`/`DROP DATABASE` sobre el prefijo `delivery_tenant_%` (`config/tenancy.php`), certificado
real de Let's Encrypt (`certbot --nginx`, renovación automática por systemd timer), `ufw` como
firewall (22, 80, 443).

## Artefactos en el repositorio

### `deploy/config.example.sh` / `deploy/config.sh`

`config.sh` no se versiona (tiene la IP/ruta real del servidor), `config.example.sh` sí. Cinco
valores:

```bash
SSH_ALIAS="mi-servidor"
REMOTE_APP="/var/www/delivery.ejemplo.com/backend"
REMOTE_DOCROOT="/var/www/delivery.ejemplo.com/public_html"
REMOTE_PHP="/usr/bin/php8.3"
SITE_URL="https://delivery.ejemplo.com"
```

### `deploy/lib.sh`, `deploy/artisan.sh`

Funciones genéricas (`subir_paquete`, `borrar_sobrantes`, `require_connection`,
`require_installed`, el bloqueo de `migrate:fresh`/`migrate:reset`/`db:wipe`), sin cambios por la
migración — no dependen de si el destino es hosting compartido o VPS.

### `deploy/deploy-backend.sh`

Mantenimiento, subida por `tar` sobre SSH, `composer install --no-scripts` + `package:discover`
(se conserva aunque el VPS sí soporta `proc_open` — simplificarlo es una mejora aparte, no
bloqueante), respaldo de la base con `mysqldump` antes de migrar, `migrate --force`, recacheo de
config/rutas/eventos, y — **nuevo por la migración a VPS** — un `chown -R www-data:www-data` al
final sobre todo `$REMOTE_APP`: como el script se corre como `root` (VPS propio, no el usuario de
cuenta que además servía las páginas como en Hostinger), sin este paso PHP-FPM (que corre como
`www-data`) no puede escribir `storage/logs` ni las sesiones — el síntoma es un 500 con el log
vacío, porque hasta el intento de loguear el error falla por permisos.

### `deploy/deploy-frontend.sh`

Sin cambios por la migración: compila en local, sube `dist/` excluyendo `.htaccess` (ya no aplica
con Nginx, pero no estorba dejarlo excluido), `index.php` y `robots.txt`.

### `deploy/vps/provision.sh` (nuevo)

Aprovisiona un VPS Ubuntu 24.04 desde cero, de forma idempotente: instala Nginx, PHP-FPM 8.3 +
extensiones, MySQL, Composer, `certbot`; asegura MySQL (equivalente no interactivo a
`mysql_secure_installation`); crea la base central y el usuario de aplicación con privilegios reales
de `CREATE`/`DROP DATABASE` sobre `delivery_tenant_%` (el fix de raíz del incidente que motivó la
migración); configura `ufw`; crea la estructura de directorios. No activa el sitio de Nginx ni emite
el certificado — eso son pasos posteriores, manuales, documentados en `deploy/README.md`.

### `deploy/vps/nginx-delivery.prosello.com.mx.conf` (nuevo)

Server block de Nginx que traduce 1:1 el comportamiento que antes daba
`deploy/hostinger/htaccess-public_html` en Apache: host canónico (`www` → sin `www`), `/api`,
`/sanctum` y `/up` siempre al front controller (sin usar `PATH_INFO`: Laravel resuelve con
`REQUEST_URI`, que `fastcgi_params` ya manda), fallback del SPA (`try_files ... /index.html`),
caché inmutable para los assets con hash de Vite, `no-cache` en `index.html`. `certbot --nginx`
añade el bloque `:443` y el redirect HTTP→HTTPS por su cuenta la primera vez que se corre.

### `deploy/hostinger/` (histórico, ya no activo)

`index.php` (front controller de producción) sigue siendo válido y en uso tal cual — es genérico,
no depende de Apache. `htaccess-public_html` queda como referencia histórica del enrutado en Apache,
ya no se copia a ningún servidor. `env.production.example` sigue siendo la plantilla del `.env` real
(el nombre del directorio quedó desactualizado, pero el contenido no depende de qué hosting sea).

### `deploy/verify.sh`

Sin cambios por la migración: todas las comprobaciones son por `curl` contra la URL pública, así que
no les importa si detrás hay Apache o Nginx.

### `deploy/README.md`

El procedimiento operativo — instalación inicial en un VPS nuevo y despliegue de un cambio —
actualizado para la topología de VPS. Ver ahí el paso a paso completo; esta spec no lo duplica.

### `.claude/commands/deploy.md`

Sin cambios por la migración: detecta qué cambió (`backend/`, `frontend/`, migraciones,
`.env.example`), despliega y verifica — agnóstico de si el destino es hosting compartido o VPS.

## Requisitos del servidor

- Ubuntu 24.04 LTS (o similar reciente), acceso `root` por SSH.
- PHP 8.3 + extensiones `pdo_mysql`, `mbstring`, `openssl`, `curl`, `dom`, `fileinfo`, `iconv`,
  `zip`, `bcmath` (`gd` no hace falta: no hay generación de PDF/QR en inDriver).
- Nginx, MySQL 8, Composer, `certbot` + `python3-certbot-nginx`, `ufw`.
- A diferencia del hosting compartido de antes, aquí **sí** hay `proc_open`/`exec` disponibles y
  **sí** hay `crontab` real (útil el día que `routes/console.php` declare una tarea programada; hoy
  no declara ninguna).

## Instalación / despliegue

Procedimiento completo en [deploy/README.md](../deploy/README.md) — instalación inicial en un VPS
nuevo (`provision.sh`, `.env`, primer `deploy-backend.sh`/`deploy-frontend.sh`, activar Nginx,
emitir certificado) y despliegue de un cambio posterior.

## Fuera de alcance

- PWA, service worker, Capacitor — fuera de alcance de `001-inicio-proyecto.md`.
- CI/CD, staging automatizado: el despliegue sigue siendo manual y documentado.
- Cola de trabajos con worker real: `QUEUE_CONNECTION=database` con `dispatchSync` sigue
  alcanzando (sin tenants reales todavía, poco tráfico); el VPS lo permitiría si hiciera falta.
- Usuario de despliegue no-root dedicado + deshabilitar login SSH de `root`, `fail2ban`,
  `unattended-upgrades`: endurecimiento pendiente, no bloqueante para que el sistema funcione.
- Verificar si el SMTP de Hostinger (`smtp.hostinger.com`) acepta conexiones salientes desde la IP
  del VPS sin problema — ya se vio un `554 Client host rejected` en el log del servidor viejo; si
  se repite desde el VPS es un problema aparte de esta migración.
- Migrar o recuperar cualquier tenant del hosting compartido: al momento de la migración había 0
  tenants reales en producción (los intentos fallidos de creación no dejaron nada recuperable), así
  que no hubo datos de tenant que mover — solo la base central (1 cuenta `AdminCentral`).

## Criterios de aceptación

1. `https://delivery.prosello.com.mx/` sirve el SPA; `http://` redirige con 301 a la forma
   canónica.
2. `https://delivery.prosello.com.mx/up` responde el healthcheck de Laravel, no `index.html`.
3. Una ruta profunda del SPA escrita a mano carga la aplicación en vez de dar 404.
4. Una ruta de API inexistente responde 404 en JSON, nunca HTML.
5. `GET /sanctum/csrf-cookie` responde 204 y deja la cookie `XSRF-TOKEN`.
6. `/.env`, `/composer.json`, `/composer.lock`, `/artisan`, `/vendor/autoload.php` y
   `/storage/logs/laravel.log` no exponen nada bajo el subdominio.
7. `APP_DEBUG=false`: un error provocado devuelve la página genérica, sin stack ni variables de
   entorno.
8. Los assets con hash de Vite llegan con `Cache-Control: immutable`; `index.html`, con `no-cache`.
9. `deploy-backend.sh`, `deploy-frontend.sh` y `verify.sh` funcionan contra el VPS, y un
   `config.sh` sin alguna de las cinco variables falla al arrancar con un mensaje que dice qué
   falta.
10. **Crear un tenant desde `/admin/tenants/crear` responde `201`**, sin el error
    `Access denied ... CREATE DATABASE` que motivó la migración — verificado en producción real
    creando y borrando un tenant de prueba (`moto-celaya`, `delivery_tenant_5`), sin dejar residuos
    en `tenants`, en las bases físicas ni en el log.

## Supuestos asumidos (registro completo, migración a VPS)

1. La migración es definitiva: el hosting compartido queda retirado para este subdominio, no es
   convivencia temporal. `prosello.com.mx` y `app.prosello.com.mx` (facturación) no se tocaron —
   siguen en el hosting compartido, ajenos a esta migración.
2. Nginx + PHP-FPM en vez de Apache — decisión tomada explícitamente con el usuario, por
   rendimiento/memoria y porque ya no hace falta encajar en las limitaciones de `.htaccess`.
3. MySQL 8 (paquete `mysql-server` de Ubuntu) en vez de MariaDB — mismo motor que tenía el hosting
   compartido, sin motivo para cambiar de motor en la migración.
4. Un solo origen para SPA y API bajo `delivery.prosello.com.mx`, sin separar en `app.`/`api.` —
   decisión heredada del despliegue original, sin cambios.
5. El fix de raíz del incidente es un `GRANT ALL PRIVILEGES ON \`delivery_tenant_%\`.* TO
   'delivery_app'@'localhost'` — el mismo patrón de prefijo que ya usaba `config/tenancy.php`, sin
   tocar código de aplicación.
6. Sin usuario de despliegue dedicado: los scripts corren como `root` por SSH (único acceso que dio
   el proveedor del VPS). Por eso `deploy-backend.sh` necesita el `chown -R www-data` al final.
   Crear un usuario no-root para despliegues queda como endurecimiento futuro, no bloqueante.
7. Sin tenants que migrar: al momento del corte había 0 tenants reales en producción. Solo se migró
   la base central (`mysqldump`/restore, ~8-24KB) con la única cuenta `AdminCentral` real.
8. TTL del registro DNS ya era bajo (60s) antes de la migración — no hizo falta bajarlo con
   antelación para el corte.
9. Certificado real de Let's Encrypt vía `certbot --nginx`, con renovación automática ya incluida
   en el paquete (`certbot.timer`) — no se automatizó nada aparte para eso.
10. El servidor de Hostinger compartido se deja intacto y sin cancelar por un tiempo, como red de
    seguridad — decisión de cuenta/facturación del usuario, no técnica, fuera de esta spec.

---

## Arranque en limpio original (Hostinger compartido) — histórico, superado por la migración

> Esta sección describe el estado **original** del sistema (antes de la migración a VPS de arriba)
> y se conserva sin actualizar por su valor histórico: documenta el porqué de decisiones que ya no
> aplican (Apache, `.htaccess`, las restricciones de `proc_open` del hosting compartido). Para el
> estado actual, ver las secciones de arriba.

Como desarrollador único del sistema, se publicó originalmente inDriver en el hosting compartido de
Hostinger bajo `https://delivery.prosello.com.mx`. Era un arranque en limpio, no una mudanza: a
diferencia de [022-subdominio-app.md](../../facturacion/specs/022-subdominio-app.md) de facturación
—que movió un sistema ya publicado de un dominio a otro—, no había instalación previa que preservar.
El subdominio se instaló directo, como hizo originalmente facturación en
[018-despliegue-hostinger.md](../../facturacion/specs/018-despliegue-hostinger.md).

Misma cuenta de Hostinger que facturación. El código de Laravel no vivía dentro del docroot, y el
subdominio se creó como **sitio web independiente** en hPanel (*Añadir sitio web → Sitio web
PHP/HTML personalizado*), no desde la pantalla de *Subdominios* (esa segunda pantalla deja el
docroot dentro de `public_html/` del dominio raíz, con el `.htaccess` del padre aplicándose
encima).

El enrutado SPA/API lo manejaba `deploy/hostinger/htaccess-public_html` sobre Apache: host canónico
y HTTPS (301), cabeceras `Authorization`/`X-XSRF-Token` reexpuestas, `/api`/`/sanctum`/`/up` al
front controller, fallback del SPA, caché inmutable para assets con hash. Requisitos del servidor de
esa época: PHP 8.3+, extensiones `pdo_mysql`/`mbstring`/`openssl`/`curl`/`dom`/`fileinfo`/`iconv`/
`zip`, `proc_open`/`exec`/`shell_exec`/`system`/`popen`/`symlink` deshabilitados (por eso
`composer install --no-scripts` + `artisan package:discover` a mano), sin `crontab` por línea de
comandos.

No incluía PWA/service worker, tarea programada, CI/CD/staging/VPS, colas con worker — todo eso
seguía fuera de alcance también en el estado original.
