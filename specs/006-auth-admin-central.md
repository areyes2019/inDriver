# Spec: Autenticación de ADMIN_CENTRAL (login, logout, recuperar contraseña)

## Historia de usuario

Como ADMIN_CENTRAL (dueño del sistema, quien da de alta los tenants), quiero tener la interfaz
para iniciar sesión, cerrar sesión y recuperar mi contraseña, para poder acceder al panel
administrativo sin depender de que alguien más gestione mis credenciales cada vez.

## Objetivo / Alcance

Dejar funcionando el login, logout y recuperación de contraseña de `admins_centrales` (base
`delivery_central`), usando Mailpit en local para probar el correo de recuperación. **No** incluye
registro público, ni el login de los usuarios de tenant (AdminCliente/Despachador/Conductor), ni la
interfaz para crear/administrar tenants.

## Decisión técnica

`admins_centrales` no vive en `users` (la tabla que Laravel usa por defecto para autenticar), así
que se agrega una guardia de sesión aparte, `admin`, con su propio proveedor Eloquent apuntando al
nuevo modelo `AdminCentral`. Como `admins_centrales` vive en la conexión por defecto
(`delivery_central`, ver `003-multi-tenant-stancl.md`), no hace falta resolver ningún tenant para
este login — es la única guardia del sistema que no depende de tenancy.

## Backend (Laravel)

- **Migración** `add_rol_to_admins_centrales_table`: agrega columna `rol` (string, nullable) a
  `admins_centrales`. Reservada, sin validarse ni exigirse en ningún endpoint de esta historia — la
  diferenciación de roles/permisos entre ADMIN_CENTRAL es una historia futura.
- **Modelo** `App\Models\AdminCentral` (`Authenticatable`, `Notifiable`, `CanResetPassword`),
  `$primaryKey = 'id_admin'`, `$hidden = ['password']`. Sin `$connection` explícita (usa la
  conexión por defecto, ya es `delivery_central`).
- **`config/auth.php`**:
  - `guards.admin` → `['driver' => 'session', 'provider' => 'admins_centrales']`.
  - `providers.admins_centrales` → `['driver' => 'eloquent', 'model' => AdminCentral::class]`.
  - `passwords.admins_centrales` → `['provider' => 'admins_centrales', 'table' =>
    'password_reset_tokens', 'expire' => 60, 'throttle' => 60]`. Reutiliza la tabla
    `password_reset_tokens` que ya trae el scaffolding (`0001_01_01_000000_create_users_table.php`)
    — no requiere migración nueva, esa tabla no es específica de `users`.
- **Rate limiting**: `RateLimiter::for('admin-login', ...)` — 5 intentos por minuto por combinación
  email + IP. Middleware `throttle:admin-login` en la ruta de login.
- **Rutas** (`routes/api.php`, prefijo `/api/v1/admin`, fuera de cualquier middleware de tenancy):
  - `POST /api/v1/admin/login` (`throttle:admin-login`) — email + password, guardia `admin`.
  - `POST /api/v1/admin/logout` (`auth:admin`).
  - `GET /api/v1/admin/me` (`auth:admin`) — datos del admin autenticado, sin password.
  - `POST /api/v1/admin/forgot-password` — genera el enlace y dispara la notificación de reseteo.
  - `POST /api/v1/admin/reset-password` — token + email + password nueva.
  - Todas responden JSON (API Resources), con mensajes de error genéricos en login (no distinguen
    "email no existe" de "contraseña incorrecta" ni de "cuenta no Activa").
- **Controlador** `App\Http\Controllers\Admin\AuthController` con los métodos anteriores.
- **Seeder** `database/seeders/AdminCentralSeeder.php`: crea (con `updateOrCreate` por email, para
  poder correrlo más de una vez sin duplicar) un ADMIN_CENTRAL con credenciales fijas de
  desarrollo. Se ejecuta con `php artisan db:seed --class=AdminCentralSeeder`; en producción, la
  contraseña se cambia a mano después de correrlo una vez.
- **`.env.example`** (backend, local): cambia `MAIL_MAILER=log` y `MAIL_PORT=2525` por
  `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`, `MAIL_USERNAME=null`,
  `MAIL_PASSWORD=null`, `MAIL_ENCRYPTION=null` — puerto SMTP por defecto de Mailpit. La bandeja se
  revisa en `http://localhost:8025`. No se toca `deploy/hostinger/env.production.example` (spec
  002): en producción sigue el SMTP de Hostinger, mismas variables `MAIL_*`, sin cambiar código.

## Frontend (Vue 3)

- Vistas nuevas bajo `frontend/src/views/admin/`: `LoginView.vue`, `ForgotPasswordView.vue`,
  `ResetPasswordView.vue`, y un placeholder `DashboardView.vue` (página vacía, solo para probar que
  la sesión protege la ruta).
- Rutas (`router/index.ts`): `/admin/login`, `/admin/forgot-password`,
  `/admin/reset-password/:token`, `/admin` (protegida).
- Store Pinia `useAdminAuthStore` (`stores/adminAuth.ts`): estado de sesión, acciones
  `login`, `logout`, `forgotPassword`, `resetPassword`, `fetchMe`.
- Guard de router: antes de entrar a `/admin`, si no hay sesión (`fetchMe` falla con 401),
  redirige a `/admin/login`.
- Cliente HTTP ya preparado desde la spec 001 (`lib/http.ts`) para enviar cookies (Sanctum
  stateful) — se usa tal cual, sin tokens en `localStorage`.

## Fuera de alcance

- Registro público de ADMIN_CENTRAL (no existe esa pantalla ni endpoint).
- Uso real de la columna `rol` (permisos diferenciados entre distintos ADMIN_CENTRAL).
- Login, registro o recuperación de contraseña de AdminCliente, Despachador o Conductor (usuarios
  de tenant) — sigue pendiente el problema de identificación de tenant que dejó abierto
  `003-multi-tenant-stancl.md`.
- Interfaz para crear/administrar tenants, planes o suscripciones.
- 2FA, login social, verificación de email (`MustVerifyEmail`).
- La resolución de tenant por dominio en `routes/tenant.php`
  (`InitializeTenancyByDomain`/`PreventAccessFromCentralDomains`) sigue como boilerplate de
  `stancl/tenancy`, sin tocar.

## Criterios de aceptación

1. `POST /api/v1/admin/login` con credenciales válidas de un admin en estado `Activo` responde
   200/204, deja cookie de sesión (guardia `admin`), y el body nunca incluye `password`.
2. `POST /api/v1/admin/login` con credenciales inválidas, o con un admin cuyo `estado` no es
   `Activo`, responde con el mismo mensaje genérico en ambos casos.
3. El 6º intento de login en 60 segundos para la misma combinación email+IP responde `429`, sin
   validar credenciales.
4. `GET /api/v1/admin/me` responde con los datos del admin autenticado si hay sesión, `401` si no.
5. `POST /api/v1/admin/logout` cierra la sesión; una petición posterior a `/me` responde `401`.
6. `POST /api/v1/admin/forgot-password` con un email existente crea un registro en
   `password_reset_tokens` y Mailpit recibe el correo con el enlace de reseteo
   (`http://localhost:8025` en local).
7. `POST /api/v1/admin/reset-password` con token válido permite loguearse con la nueva contraseña;
   reusar el mismo token después falla.
8. La columna `rol` existe en `admins_centrales` (migración aplicada), nullable, sin validarse en
   ningún endpoint de esta historia.
9. `php artisan db:seed --class=AdminCentralSeeder` deja un ADMIN_CENTRAL con credenciales de
   desarrollo conocidas, sin duplicar el registro si se corre de nuevo.
10. El frontend expone `/admin/login`, `/admin/forgot-password`, `/admin/reset-password/:token` y
    `/admin` (esta última redirige a `/admin/login` sin sesión).
11. No existe ninguna ruta ni pantalla de registro público para ADMIN_CENTRAL.
12. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. Esta historia es sobre ADMIN_CENTRAL (tabla `admins_centrales`, base `delivery_central`), el
   dueño del sistema que crea tenants — no sobre AdminCliente/Despachador/Conductor (usuarios de
   tenant).
2. Login vía email + contraseña, con guardia de sesión aparte (`admin`) y cookie de sesión Sanctum
   — sin 2FA ni login social.
3. Se agrega una columna `rol` a `admins_centrales`, reservada pero sin usar ni validar en este
   login; la diferenciación de roles/permisos entre ADMIN_CENTRAL queda para otra historia.
4. No existe pantalla de registro público. La cuenta de ADMIN_CENTRAL se crea con un
   `AdminCentralSeeder` de Laravel (credenciales fijas para desarrollo); en producción la
   contraseña se cambia manualmente tras el primer despliegue.
5. Recuperar contraseña: flujo estándar de Laravel (pedir con email → correo con link → nueva
   contraseña), reutilizando la tabla `password_reset_tokens` ya existente en `delivery_central` —
   sin preguntas de seguridad ni SMS.
6. Si el `estado` del admin no es `Activo`, el login se rechaza con un mensaje genérico, sin
   revelar la causa exacta.
7. Esta historia no incluye la interfaz para crear/administrar tenants — solo login, logout y
   recuperar contraseña de la propia cuenta de ADMIN_CENTRAL.
8. El login del ADMIN_CENTRAL vive en rutas y pantallas separadas (`/admin/...` y
   `/api/v1/admin/...`) del futuro login de usuarios de tenant.
9. Mailpit se usa solo en local para capturar los correos de recuperación; en producción se usa el
   SMTP de Hostinger ya definido en `002-despliegue-hostinger.md` — mismas variables `MAIL_*`, sin
   cambiar código.
10. Límite de 5 intentos de login fallidos por minuto (por email+IP), para proteger esta cuenta de
    alto privilegio contra fuerza bruta.
