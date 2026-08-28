# Spec: Despliegue en Hostinger (producción, arranque en limpio)

## Historia de usuario

Como desarrollador único del sistema, quiero publicar inDriver en el hosting compartido de
Hostinger bajo `https://delivery.prosello.com.mx`, de forma que el sistema quede utilizable desde
internet y que volver a desplegar un cambio sea un procedimiento escrito y repetible en vez de una
serie de decisiones improvisadas cada vez.

## Objetivo / Alcance

Dejar en el repositorio los artefactos de despliegue (front controller de producción, `.htaccess`
del docroot, plantilla de variables de entorno, scripts de subida/verificación y el procedimiento
paso a paso) para el scaffolding que dejó [001-inicio-proyecto.md](001-inicio-proyecto.md): Laravel
API + Vue 3 SPA, sin lógica de negocio de delivery/tracking todavía.

**Esta spec no cambia ninguna regla de negocio.** Todo lo que toca es infraestructura y
configuración.

**Es un arranque en limpio, no una mudanza.** A diferencia de
[022-subdominio-app.md](../../facturacion/specs/022-subdominio-app.md) de facturación —que movió un
sistema ya publicado de un dominio a otro—, aquí no hay instalación previa que preservar, ni
usuarios que reautenticar, ni service worker viejo que apagar. El subdominio
`delivery.prosello.com.mx` se instala directo, como hizo originalmente facturación en
[018-despliegue-hostinger.md](../../facturacion/specs/018-despliegue-hostinger.md), con la única
diferencia de que aquí el sistema nace ya en su propio subdominio en vez de en el dominio raíz.

### El sistema se sirve desde un solo origen

Backend y frontend son dos aplicaciones desacopladas (ver `001-inicio-proyecto.md`), pero en
producción se publican bajo **el mismo host**:

```
https://delivery.prosello.com.mx/            → el SPA (dist/ de Vite)
https://delivery.prosello.com.mx/api/v1/*    → Laravel
https://delivery.prosello.com.mx/sanctum/*   → Laravel (cookie CSRF)
https://delivery.prosello.com.mx/up          → Laravel (healthcheck)
```

Mismo motivo que en facturación: la autenticación es Sanctum por **cookie de sesión**
(`EnsureFrontendRequestsAreStateful`), y separar SPA y API en dos hosts (`app.` + `api.`) obligaría
a mantener una lista de orígenes en `config/cors.php`, a emitir la cookie visible para cualquier
subdominio del dominio, y a pagar un `preflight` en cada `POST`. `config/cors.php` no se toca: con
un solo origen el navegador no manda `Origin` en peticiones del mismo host y el middleware de CORS
no interviene; sigue como está para que el proyecto funcione en local contra el dev server de Vite.

## Topología en el servidor

Misma cuenta de Hostinger que facturación (confirmado). El código de Laravel **no vive dentro del
docroot** — si viviera, `.env`, `storage/logs/laravel.log` y `composer.lock` serían descargables
por URL — y el subdominio se crea como **sitio web independiente** en hPanel (*Añadir sitio web →
Sitio web PHP/HTML personalizado*), no desde la pantalla de *Subdominios*, por la misma razón que
en facturación: esa segunda pantalla deja el docroot dentro de `public_html/` del dominio raíz, con
el `.htaccess` del padre aplicándose encima.

```
~/domains/
├── prosello.com.mx/               ← sistema de facturación, sin tocar
├── app.prosello.com.mx/           ← sistema de facturación, sin tocar
└── delivery.prosello.com.mx/
    ├── backend/                   ← código de Laravel, fuera del docroot
    └── public_html/               ← docroot: SPA + front controller
```

## Artefactos nuevos en el repositorio

Se crea `deploy/`, calcado del de facturación pero sin lo que no aplica todavía: sin sincronización
de catálogos SAT, sin tarea de cron (`routes/console.php` de inDriver no declara ninguna todavía),
sin PWA/service worker (fuera de alcance de `001-inicio-proyecto.md`), sin la lógica de mudanza
(`APEX_URL`, dominio raíz, landing) que solo existe en facturación.

### `deploy/config.example.sh` / `deploy/config.sh`

Igual patrón que facturación: `config.sh` no se versiona (tiene la ruta real de la cuenta),
`config.example.sh` sí. Solo cinco valores, sin `APEX_URL` ni `REMOTE_LANDING_DOCROOT` — no existe
dominio raíz que redirigir ni landing que verificar:

```bash
SSH_ALIAS="prosello"
REMOTE_APP="/home/<cuenta>/domains/delivery.prosello.com.mx/backend"
REMOTE_DOCROOT="/home/<cuenta>/domains/delivery.prosello.com.mx/public_html"
REMOTE_PHP="/usr/bin/php"
SITE_URL="https://delivery.prosello.com.mx"
```

### `deploy/lib.sh`, `deploy/artisan.sh`

Mismas funciones que facturación (`subir_paquete`, `borrar_sobrantes`, `require_connection`,
`require_installed`, el bloqueo de `migrate:fresh`/`migrate:reset`/`db:wipe`): son genéricas, no
dependen del dominio de negocio. Solo se exige la existencia de las cinco variables de
`config.sh` de arriba, no las siete de facturación.

### `deploy/deploy-backend.sh`

Mismo procedimiento que facturación —mantenimiento, subida por `tar` sobre SSH, `composer install
--no-scripts` + `package:discover` (Hostinger deshabilita `proc_open`), respaldo de la base con
`mysqldump` antes de migrar, `migrate --force`, recacheo de config/rutas/eventos— **sin el paso de
catálogos SAT**, que es específico de facturación. Los respaldos se nombran
`indriver-YYYYMMDD-HHMMSS.sql.gz`.

### `deploy/deploy-frontend.sh`

Igual que facturación: compila en local (el plan compartido no tiene Node), sube `dist/` excluyendo
`.htaccess`, `index.php` y `robots.txt` para no pisar los de producción, borra los `assets/` de
builds anteriores.

### `deploy/hostinger/index.php`

Copia de `backend/public/index.php` con las rutas resueltas contra `__DIR__.'/../backend'` en vez
de `__DIR__.'/../'`, igual razón que en facturación: en el servidor el proyecto no está encima del
docroot sino a su lado, y la ruta relativa hace que el mismo archivo sirva sin importar si el
subdominio es el principal de la cuenta o uno adicional.

### `deploy/hostinger/htaccess-public_html`

Versión reducida de la de facturación: sin las reglas de PWA (manifest, `.mjs`, cachés de service
worker) porque inDriver no tiene PWA todavía. Conserva lo que sí aplica:

1. Host canónico y HTTPS (301, sin nombrar el dominio, derivado de la petición).
2. Cabeceras `Authorization` y `X-XSRF-Token` reexpuestas.
3. `/api`, `/sanctum` y `/up` al front controller.
4. Todo lo demás: archivo real si existe, si no `index.html` (fallback del SPA para rutas de Vue
   Router).
5. Caché inmutable para los assets con hash de Vite (`.js`/`.css`), `index.html` siempre
   revalidado.

El día que se agregue PWA (fuera de alcance de `001`), este archivo gana las reglas que hoy tiene
facturación para manifest/service worker — no antes, porque agregarlas sin que exista la PWA sería
documentar un comportamiento que el sistema no tiene.

### `deploy/hostinger/env.production.example`

Mismo patrón que facturación, sin las variables que no aplican (`FACTURAPI_*`, `TWILIO_*`):

| Variable | Valor | Por qué |
|---|---|---|
| `APP_NAME` | `inDriver` | |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | Con `true` expone stack y variables de entorno en cualquier error |
| `APP_URL` / `FRONTEND_URL` | `https://delivery.prosello.com.mx` | |
| `SANCTUM_STATEFUL_DOMAINS` | `delivery.prosello.com.mx` | Sin `www`, coherente con el host canónico |
| `SESSION_DOMAIN` | `null` | Cookie host-only |
| `SESSION_SECURE_COOKIE` | `true` | La cookie no viaja nunca por HTTP |
| `LOG_LEVEL` | `warning` | `debug` llena el disco del plan compartido |
| `DB_*` | los de hPanel | Base y usuario con prefijo `uXXXXXXXX_` |
| `MAIL_*` | SMTP de Hostinger | Igual que facturación |
| `GOOGLE_MAPS_API_KEY` | vacío | Reservada por `001-inicio-proyecto.md`; no se usa todavía |

### `deploy/hostinger/robots.txt`

`Disallow: /` para todo. Todavía no hay ninguna pantalla pública en inDriver, así que no hay nada
que un buscador deba indexar. Reemplaza al `robots.txt` por defecto de Laravel.

### `deploy/verify.sh`

Mismo enfoque que facturación —todo por `curl` contra la URL pública— pero con las comprobaciones
que sí aplican al estado actual del sistema:

1. **Disponibilidad**: `/up` y `/` responden 200.
2. **Host canónico**: `http://` redirige con 301.
3. **Separación entre SPA y API**: una ruta profunda del SPA escrita a mano carga la aplicación
   (200, fallback de Vue Router); una ruta de API inexistente (`/api/v1/ping-inexistente`) responde
   **404 en JSON**, nunca el `index.html` del SPA — es la comprobación central de esta spec, porque
   confirma que el `.htaccess` reparte correctamente entre las dos aplicaciones.
4. **Sanctum vivo**: `GET /sanctum/csrf-cookie` responde 204 y dejando la cookie `XSRF-TOKEN`.
5. **Nada del proyecto es descargable**: `.env`, `composer.json`, `composer.lock`, `artisan`,
   `vendor/autoload.php`, `storage/logs/laravel.log`, `.git/config` — igual que facturación.
6. **Caché**: los assets con hash de Vite llegan con `immutable`; `index.html`, con `no-cache`.

**No** incluye las comprobaciones de PWA, `.mjs`, ni las del dominio raíz/landing: no aplican a un
sistema sin PWA que nace directo en su subdominio.

### `deploy/README.md`

El procedimiento operativo — instalación inicial y despliegue de un cambio — documentado igual que
en facturación, sin la sección de tarea programada (no hay ninguna) ni la de catálogos SAT.

### `.claude/commands/deploy.md`

Calcado del de facturación, apuntando a `delivery.prosello.com.mx`. La tabla de detección de
cambios pierde las filas de `htaccess-apex`/`sw-apex.js` (solo existen en una mudanza) y la de
"variables de entorno nuevas" solo compara contra `backend/.env.example` y
`deploy/hostinger/env.production.example` de este mismo repositorio.

### `.gitignore` (raíz, nuevo)

`/deploy/config.sh` — mismo motivo que en facturación: contiene la ruta real de la cuenta de
hosting y no debe versionarse.

## Requisitos del servidor

Los mismos que facturación, por ser la misma cuenta: **PHP 8.3+**, extensiones `pdo_mysql`,
`mbstring`, `openssl`, `curl`, `dom`, `fileinfo`, `iconv`, `zip` (`gd` **no** hace falta todavía:
no hay generación de PDF/QR en inDriver), acceso SSH, certificado SSL para el subdominio, y la
misma restricción de entorno: `proc_open`, `exec`, `shell_exec`, `system`, `popen` y `symlink`
deshabilitados, sin `crontab` por línea de comandos (irrelevante aquí: no hay tarea programada que
instalar).

## Instalación inicial

1. En hPanel: subdominio creado como sitio web independiente, SSL activo, base de datos MySQL
   creada (anotar nombre, usuario y contraseña).
2. Subir `backend/` a `~/domains/delivery.prosello.com.mx/backend/` **sin** `public/`, `tests/`,
   `node_modules/` ni `.env`.
3. `composer install --no-dev --optimize-autoloader --no-scripts`, seguido de
   `php artisan package:discover`.
4. Crear el `.env` desde `deploy/hostinger/env.production.example`, con las credenciales reales.
   `php artisan key:generate`.
5. Permisos de escritura en `storage/` y `bootstrap/cache/`.
6. `php artisan migrate --force`.
7. `npm run build` **en local** → subir `frontend/dist/` a `public_html/`.
8. Copiar `deploy/hostinger/index.php` y `deploy/hostinger/htaccess-public_html` (renombrado a
   `.htaccess`) a `public_html/`, **después** del `dist/`.
9. `php artisan config:cache && php artisan route:cache && php artisan event:cache`.

## Despliegue de un cambio posterior

- **Solo frontend**: `deploy/deploy-frontend.sh`.
- **Solo backend**: `deploy/deploy-backend.sh` (o `--sin-migrar` si no hay migraciones nuevas).
- Verificar con `deploy/verify.sh`, o con el comando `/deploy` que hace todo lo anterior por ti.

## Fuera de alcance

- PWA, service worker, Capacitor — fuera de alcance de `001-inicio-proyecto.md`, y por lo tanto de
  este despliegue también.
- Cualquier tarea programada (cron): `routes/console.php` no declara ninguna todavía.
- Mapas/GPS: `GOOGLE_MAPS_API_KEY` viaja vacía; no hay ninguna llamada a un proveedor de mapas.
- CI/CD, staging, VPS, colas con worker.
- Cualquier cambio de lógica de negocio, pantallas o endpoints.
- Autenticación funcional: no hay rutas de login que verificar en producción todavía.

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
9. `deploy-backend.sh`, `deploy-frontend.sh` y `verify.sh` funcionan contra las rutas del
   subdominio, y un `config.sh` sin alguna de las cinco variables falla al arrancar con un mensaje
   que dice qué falta.

## Supuestos asumidos (registro completo)

1. Un solo origen para SPA y API bajo `delivery.prosello.com.mx`. No se separa en `app.`/`api.`.
2. El subdominio ya está creado en hPanel como sitio web independiente, docroot hermano del
   proyecto Laravel.
3. Misma cuenta de Hostinger que facturación: mismas restricciones (`proc_open` deshabilitado, sin
   `crontab` por CLI), mismo SSH alias.
4. No hay ninguna tarea programada que configurar: `routes/console.php` no declara comandos
   propios.
5. No se configura PWA ni service worker en este despliegue.
6. No hace falta `gd` ni `dompdf`: no hay generación de PDF/QR en inDriver.
7. No hay catálogos SAT ni nada equivalente que sincronizar.
8. `GOOGLE_MAPS_API_KEY` viaja vacía también en producción.
9. Instalación nueva: no hay datos previos, usuarios ni service worker que migrar o apagar.
10. `APP_DEBUG=false`, `LOG_LEVEL=warning`, MySQL propio del hosting, SMTP de Hostinger para
    correo saliente.
11. Se reutilizan los scripts de despliegue de facturación como plantilla, adaptados: mismo
    `lib.sh`/`artisan.sh` (genéricos), `deploy-backend.sh` sin el paso de catálogos SAT,
    `deploy-frontend.sh` igual, `verify.sh` sin las comprobaciones de PWA/mudanza.
12. Se crea `.claude/commands/deploy.md` en este repositorio, calcado del de facturación y
    apuntando a `delivery.prosello.com.mx`.
13. `robots.txt` con `Disallow: /`: todavía no hay ninguna pantalla pública.
14. El despliegue es manual y documentado, sin CI/CD, sin staging.
15. El código de Laravel vive fuera del docroot como carpeta hermana de `public_html`, nombrada
    `backend/` en el servidor (mismo nombre que en el repositorio local).
