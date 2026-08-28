# Spec: Login de tenant por slug y CRUD de usuarios

## Historia de usuario

Como AdminCliente (dueño/administrador del negocio dentro de un tenant), quiero iniciar sesión en
el panel de mi propia empresa y gestionar ahí a los usuarios que trabajan en ella (otros
AdminCliente, Despachadores y Conductores) — crearlos, verlos, editarlos y eliminarlos — para operar
mi negocio sin depender de que alguien intervenga la base de datos a mano.

## Objetivo / Alcance

Esta historia resuelve, en un solo paquete, el prerrequisito de acceso (login de tenant) que
`004-auth-admin-central.md` y `010-alta-admin-cliente-tenant.md` dejaron explícitamente pendiente
("problema de identificación de tenant"), y el CRUD completo de la tabla `usuarios` (inciso 01 de
`db/02-base-de-datos.md`) que depende de ese acceso.

Deja funcionando:

- **Identificación del tenant por slug en la URL** (`/t/{slug}/...`), en vez de por subdominio —
  ver "Decisión técnica" para el porqué.
- **Login, logout, "quién soy", recuperar y restablecer contraseña** para usuarios de tenant
  (AdminCliente/Despachador/Conductor), con el mismo nivel de completitud que ya existe para
  ADMIN_CENTRAL (`004-auth-admin-central.md`, `006-rediseno-login-admin.md`).
- **CRUD completo de `usuarios`**: listar (con búsqueda y paginación), crear, editar, eliminar —
  accesible únicamente para el rol `AdminCliente`.

**No** incluye:

- Crear automáticamente el registro relacionado en `despachadores`/`conductores` (tablas 02/03 de
  `db/02-base-de-datos.md`) al dar de alta un usuario con esos roles — el perfil operativo queda
  para una historia futura.
- Una pantalla de "detalle" de solo lectura separada de la de edición (el CRUD cubre listar/crear/
  editar/eliminar, sin una cuarta pantalla adicional).
- Verificación de email, segundo factor, o forzar cambio de contraseña en el primer login.
- Subdominios reales por tenant (evaluados y descartados, ver más abajo).

## Decisión técnica

### Por qué slug en la URL y no subdominio

`002-despliegue-hostinger.md` publica todo el sistema bajo **un solo origen**
(`delivery.prosello.com.mx`) y deja "autenticación funcional" fuera de su alcance. Un subdominio
real por tenant (`acme.delivery.prosello.com.mx`) necesitaría DNS wildcard
(`*.delivery.prosello.com.mx`) y un certificado SSL wildcard, que el modelo de hPanel usado en esa
spec (cada subdominio como "sitio web independiente", creado a mano uno por uno) no soporta de forma
automática en hosting compartido. Se descarta por eso, no por preferencia de diseño.

En su lugar, el tenant se identifica por un **slug** (identificador corto, único, url-friendly, ej.
`acme`) como primer segmento de la ruta: `delivery.prosello.com.mx/t/acme/...` tanto en el frontend
(Vue Router) como en el backend (`/api/v1/t/acme/...`). Esto no requiere tocar DNS ni SSL, y
convive sin conflicto con las rutas ya existentes de ADMIN_CENTRAL bajo `/admin/...`.

### Columna `slug` nueva en `tenants` (base central)

- Migración nueva: agrega `slug` (string, único) a la tabla `tenants`. Se agrega **nullable** a
  nivel de base de datos (no `NOT NULL`) para no romper filas de tenants que ya existan en la base
  de desarrollo sin slug asignado ([memoria: nunca `migrate:fresh` en dev]); la misma migración
  hace un backfill de las filas existentes, generando el slug desde `nombre_comercial`
  (`Str::slug()`) con sufijo numérico si hay choque de unicidad. A nivel de aplicación, sin embargo,
  `TenantController@store` siempre lo genera y lo exige — un tenant nuevo nunca queda sin slug.
- `App\Models\Tenant::getCustomColumns()` debe incluir `'slug'` en su lista (spec `003`): cualquier
  columna real que falte ahí queda redirigida por error a la columna `data` (json) en vez de a su
  propia columna.
- `TenantController@store` genera el slug a partir de `nombre_comercial` (`Str::slug()`), y si ya
  existe, agrega un sufijo numérico (`acme`, `acme-2`, `acme-3`...) hasta encontrar uno libre. No es
  editable desde el formulario — se deriva, no se captura.
- `TenantResource` expone `slug` (lo necesita el frontend de ADMIN_CENTRAL para armar el enlace/aviso
  "panel de este tenant: .../t/acme").

### Identificación del tenant en cada petición

Middleware nuevo `App\Http\Middleware\IdentificarTenantPorSlug`, registrado como alias de ruta
(`tenant.slug`). No se usa el resolver de rutas por path que trae `stancl/tenancy`
(`InitializeTenancyByPath`) porque ese resuelve por la llave primaria del tenant (`id_tenant`), no
por una columna arbitraria como `slug`, y cambiar `getTenantKeyName()` para que sea `slug` afectaría
a todo el resto del paquete (cachés, relaciones internas) sin necesidad. En su lugar, el middleware
es manual y simple, mismo estilo que ya usa `CrearAdminClienteInicial` para inicializar tenencia a
mano:

1. Lee `{slug}` de la ruta.
2. Busca `Tenant::where('slug', $slug)->first()` en la base central.
3. Si no existe, responde `404` en JSON (nunca deja pasar la petición).
4. Si existe, llama a `tenancy()->initialize($tenant)` — a partir de aquí, cualquier modelo de
   `App\Models\Tenant\*` (como `Usuario`) consulta la base de ese tenant.
5. `$route->forgetParameter('slug')` para que los controladores no reciban ese parámetro.

Este middleware va **antes** de `auth:usuario` en cualquier grupo de rutas de tenant — sin él, el
guard no tiene forma de saber en qué base buscar al usuario autenticado.

### Guard, provider y password broker nuevos

`config/auth.php` gana un tercer guard, paralelo a `web` y `admin`:

```php
'guards' => [
    // ...
    'usuario' => ['driver' => 'session', 'provider' => 'usuarios'],
],
'providers' => [
    // ...
    'usuarios' => ['driver' => 'eloquent', 'model' => App\Models\Tenant\Usuario::class],
],
'passwords' => [
    // ...
    'usuarios' => ['provider' => 'usuarios', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60],
],
```

La sesión (`SESSION_DRIVER=database`) sigue viviendo en la tabla `sessions` de la base **central**
(igual que ya pasa con el guard `admin`) — solo guarda el id del usuario autenticado; en cada
petición, `IdentificarTenantPorSlug` ya dejó activa la base correcta antes de que el guard intente
cargar ese id, así que el usuario se resuelve siempre contra el tenant correcto.

`password_reset_tokens` no existe hoy en las bases de tenant (solo en la central, creada por la
migración default de Laravel). Se agrega una migración nueva en
`database/migrations/tenant/`, igual a la que ya trae Laravel para la base central.

### URL de restablecimiento de contraseña, por broker

`AppServiceProvider::boot()` ya define un único `ResetPassword::createUrlUsing(...)` que hoy
siempre arma `/admin/reset-password/{token}`. Se cambia para que distinga por tipo de notifiable:

- Si `$notifiable instanceof App\Models\Tenant\Usuario` → construye
  `/t/{slug}/reset-password/{token}?email=...`, usando `tenant('slug')` (la tenencia sigue activa
  en ese momento, porque el envío del correo ocurre de forma síncrona dentro de la misma petición
  de `forgotPassword`).
- Si no, se mantiene el comportamiento actual (`/admin/reset-password/{token}`).

### Reglas del CRUD de `usuarios`

- Solo `AdminCliente` accede a las rutas de gestión de usuarios (middleware de rol, no solo de
  autenticación).
- **Listar**: búsqueda por `nombre`/`email`, paginado — mismo patrón que
  `TenantController@index` (spec 007/008).
- **Crear**: el AdminCliente captura nombre/apellidos/teléfono/email/rol; la contraseña **no** la
  escribe — se genera aleatoria (`Str::password()`), se guarda hasheada, y se envía por correo,
  igual mecanismo que `010-alta-admin-cliente-tenant.md` usa para el primer AdminCliente. Se
  reutiliza el mismo mecanismo de correo pero con una notificación nueva y más genérica,
  `App\Notifications\CredencialesUsuarioTenant` (en vez de reutilizar
  `CredencialesAdminCliente`, que ya está en producción para el flujo automático de alta de tenant y
  cuyo nombre de clase asume ese caso específico) — incluye el slug del tenant en la URL de acceso
  del correo.
- **Editar**: cualquier campo excepto `password` (no hay pantalla de "resetear contraseña de otro
  usuario" en esta historia — para eso ya existe "olvidé mi contraseña").
- **Eliminar**: borrado físico (`delete`), igual que `TenantController@destroy` en spec 007 — exige
  reingresar la contraseña de la sesión actual del AdminCliente que ejecuta la acción.
- Un AdminCliente no puede eliminarse a sí mismo ni cambiar su propio rol (evita que un tenant se
  quede sin ningún AdminCliente con acceso). Sí puede editar sus otros datos propios, y sí puede
  eliminar o cambiar el rol de **otros** usuarios, incluidos otros AdminCliente.
- El email es único **por tenant** (ya lo impone la migración de `usuarios`, `unique` dentro de su
  propia base) — no hay validación de unicidad entre tenants distintos.

## Backend (Laravel)

- **Migración** `database/migrations/2026_08_28_060000_add_slug_to_tenants_table.php`: agrega
  `slug` (string, único, nullable) a `tenants`, con backfill de filas existentes.
- **Migración** `database/migrations/tenant/2026_08_28_060100_create_password_reset_tokens_table.php`:
  tabla `password_reset_tokens` (`email` PK, `token`, `created_at`) dentro de cada base de tenant.
- **`App\Models\Tenant`**: agrega `'slug'` a `getCustomColumns()`.
- **`App\Http\Middleware\IdentificarTenantPorSlug`**: descrito arriba. Se registra como alias
  `tenant.slug` en `bootstrap/app.php`.
- **`config/auth.php`**: guard `usuario`, provider `usuarios`, password broker `usuarios` (arriba).
- **`App\Providers\AppServiceProvider`**: `ResetPassword::createUrlUsing` distingue por tipo de
  notifiable (arriba); nuevo `RateLimiter::for('tenant-login', ...)` (5/min por email+IP, igual que
  `admin-login`) y `RateLimiter::for('tenant-usuarios', ...)` (20/min por usuario autenticado, igual
  que `admin-tenants`).
- **Controlador nuevo** `App\Http\Controllers\Tenant\AuthController`: `login`, `logout`, `me`,
  `forgotPassword`, `resetPassword` — calcado de `Admin\AuthController`, usando el guard `usuario` y
  el broker `usuarios`. `login` también actualiza `ultimo_acceso`.
- **Controlador nuevo** `App\Http\Controllers\Tenant\UsuarioController`: `index`, `store`, `show`,
  `update`, `destroy` — mismo estilo que `Admin\TenantController` (validación inline, sin
  `FormRequest` aparte). `store` genera la contraseña aleatoria, crea el usuario, envía
  `CredencialesUsuarioTenant`. `destroy` valida `password` contra
  `Hash::check($data['password'], $request->user('usuario')->password)` y bloquea autoeliminación.
  `update` bloquea que el usuario autenticado cambie su propio `rol`.
- **Resource nuevo** `App\Http\Resources\Tenant\UsuarioResource`: expone las columnas de `usuarios`
  excepto `password`.
- **Notificación nueva** `App\Notifications\CredencialesUsuarioTenant($nombreComercial, $slug,
  $email, $password)`: mismo contenido que `CredencialesAdminCliente`, con la URL de acceso
  (`/t/{slug}/login`) incluida en el correo.
- **Rutas** (`routes/api.php`), grupo nuevo paralelo al de `admin`:

  ```php
  Route::prefix('t/{slug}')->middleware('tenant.slug')->group(function () {
      Route::post('/login', [Tenant\AuthController::class, 'login'])->middleware('throttle:tenant-login');
      Route::post('/forgot-password', [Tenant\AuthController::class, 'forgotPassword']);
      Route::post('/reset-password', [Tenant\AuthController::class, 'resetPassword']);

      Route::middleware('auth:usuario')->group(function () {
          Route::post('/logout', [Tenant\AuthController::class, 'logout']);
          Route::get('/me', [Tenant\AuthController::class, 'me']);

          Route::middleware(['throttle:tenant-usuarios', 'rol.tenant:AdminCliente'])->group(function () {
              Route::get('/usuarios', [Tenant\UsuarioController::class, 'index']);
              Route::post('/usuarios', [Tenant\UsuarioController::class, 'store']);
              Route::get('/usuarios/{usuario}', [Tenant\UsuarioController::class, 'show']);
              Route::put('/usuarios/{usuario}', [Tenant\UsuarioController::class, 'update']);
              Route::delete('/usuarios/{usuario}', [Tenant\UsuarioController::class, 'destroy']);
          });
      });
  });
  ```

  `rol.tenant:AdminCliente` es un middleware nuevo y pequeño (`App\Http\Middleware\AsegurarRolTenant`)
  que compara `$request->user('usuario')->rol` contra el/los roles permitidos, respondiendo `403` si
  no coincide.

## Frontend (Vue 3)

- **Store nueva** `stores/tenantAuth.ts`, calcada de `stores/adminAuth.ts`: agrega el estado `slug`
  (tomado de la ruta activa) y arma cada llamada HTTP contra `/t/${slug}/...`.
- **Vistas nuevas** en `views/tenant/`: `LoginView.vue`, `ForgotPasswordView.vue`,
  `ResetPasswordView.vue` — mismo patrón visual que sus equivalentes de `views/admin/` (guía de
  diseño base, spec 005/006).
- **Vistas nuevas** en `views/tenant/usuarios/`: `ListaUsuariosView.vue` (tabla con búsqueda,
  paginación, botones "Editar"/"Eliminar" por fila, botón "Nuevo usuario"), `CrearUsuarioView.vue`,
  `EditarUsuarioView.vue` — mismo patrón que `views/admin/tenants/*View.vue`. "Eliminar" reutiliza
  `UiConfirmDialog.vue` con el campo de contraseña (ya extendido en spec 007). La tabla ocupa el
  100% del ancho interior disponible del card, sin `max-width` ni centrado propio, igual que la
  corrección ya aplicada en `ListaTenantsView.vue` (spec 008).
- **Rutas** (`router/index.ts`): `/t/:slug/login`, `/t/:slug/forgot-password`,
  `/t/:slug/reset-password/:token`, `/t/:slug/panel/usuarios`,
  `/t/:slug/panel/usuarios/crear`, `/t/:slug/panel/usuarios/:id/editar`, protegidas con
  `meta: { requiresTenantAuth: true }` (excepto login/forgot/reset). El guard `router.beforeEach`
  replica el de `requiresAdminAuth`, usando `useTenantAuthStore()` y el `slug` de `route.params`.

## Fuera de alcance

- Alta automática de `despachadores`/`conductores` al crear un usuario con esos roles — historia
  futura.
- Pantalla de detalle de solo lectura separada de la de edición.
- Verificación de email, 2FA, forzar cambio de contraseña en primer login.
- Subdominios reales por tenant (evaluado y descartado — ver "Decisión técnica").
- Resetear la contraseña de otro usuario desde el CRUD (el propio usuario usa "olvidé mi
  contraseña").
- Cambiar el correo de notificación de bienvenida del AdminCliente inicial
  (`CredencialesAdminCliente`, spec 010) — sigue como está; `CredencialesUsuarioTenant` es una clase
  aparte para este flujo.
- Login combinado (que un mismo formulario detecte automáticamente el tenant a partir del email) —
  el slug siempre viaja explícito en la URL.

## Criterios de aceptación

1. `POST /api/v1/t/{slug}/login` con un `slug` inexistente responde `404` en JSON, sin intentar
   autenticar nada.
2. `POST /api/v1/t/{slug}/login` con credenciales válidas de un usuario `Activo` de ese tenant
   responde con los datos del usuario (sin `password`), y dos peticiones subsecuentes a
   `GET /api/v1/t/{slug}/me` devuelven el mismo usuario mientras la sesión viva.
3. Un usuario autenticado en el tenant `acme` no puede acceder a `GET /api/v1/t/otro-tenant/me` como
   si fuera válido — cada slug resuelve una base de datos distinta.
4. `POST /api/v1/t/{slug}/forgot-password` con un email existente en ese tenant envía un correo
   (Mailpit en local) con un enlace que apunta a `/t/{slug}/reset-password/{token}`.
5. `GET/POST /api/v1/t/{slug}/usuarios` sin sesión responde `401`; con sesión de un usuario
   `Despachador` o `Conductor` responde `403`.
6. `POST /api/v1/t/{slug}/usuarios` con datos válidos crea el usuario, con `password` hasheada y
   nunca expuesta en la respuesta; el correo de bienvenida llega con una contraseña que, hasheada,
   coincide con la guardada.
7. `DELETE /api/v1/t/{slug}/usuarios/{id}` con la contraseña de sesión incorrecta responde `422` y
   no borra nada; con la contraseña correcta borra el usuario y responde `200`.
8. `DELETE /api/v1/t/{slug}/usuarios/{id}` donde `{id}` es el propio usuario autenticado responde
   `422`, sin borrar nada.
9. `PUT /api/v1/t/{slug}/usuarios/{id}` donde `{id}` es el propio usuario autenticado y el payload
   trae un `rol` distinto al actual responde `422`, sin cambiar el rol.
10. `GET /api/v1/t/{slug}/usuarios?search=...` filtra por nombre o email y devuelve resultados
    paginados.
11. El frontend expone `/t/:slug/login` y, tras iniciar sesión, `/t/:slug/panel/usuarios` con la
    tabla, búsqueda y los tres botones (Nuevo usuario / Editar / Eliminar); "Eliminar" pide la
    contraseña en `UiConfirmDialog` antes de ejecutar.
12. En `/t/:slug/panel/usuarios`, la tabla ocupa el 100% del ancho interior del card (sin franjas
    vacías a los lados) en pantallas anchas, y sigue siendo legible con scroll horizontal propio en
    pantallas angostas.
13. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. El tenant se identifica por un `slug` en la URL (`/t/{slug}/...`), no por subdominio — un
   subdominio real requeriría DNS/SSL wildcard, no soportado de forma simple en el hosting
   compartido de `002-despliegue-hostinger.md`. (Corrige la primera versión de esta spec, que asumía
   que la identificación de tenant ya estaba resuelta en `007-crear-tenants.md`; se verificó que esa
   spec no toca el tema.)
2. El slug se genera automáticamente desde `nombre_comercial` al crear el tenant (`Str::slug()`, con
   sufijo numérico si hay choque) — no es un campo capturado a mano en el formulario.
3. Solo el rol `AdminCliente` accede a la gestión de usuarios; Despachador y Conductor no tienen
   esta pantalla.
4. El alcance es únicamente la tabla `usuarios` — no se crea el registro relacionado en
   `despachadores`/`conductores` al dar de alta un usuario con esos roles.
5. Al crear un usuario nuevo desde el panel, la contraseña se genera igual que en la spec 010
   (aleatoria + envío por correo) — el AdminCliente no la escribe a mano.
6. El AdminCliente puede crear otros AdminCliente adicionales desde esta misma pantalla — no hay
   límite de uno solo por tenant.
7. "Eliminar" es borrado físico, no baja lógica vía `estado`.
8. Eliminar un usuario exige confirmar la contraseña de la sesión actual, igual que
   `TenantController@destroy`.
9. El AdminCliente no puede eliminarse a sí mismo ni cambiar su propio rol.
10. El listado admite búsqueda por nombre/email y paginación, siguiendo el patrón de
    `TenantController@index`.
11. El email es único dentro del tenant, no globalmente entre tenants.
12. **Sí se implementa "olvidé mi contraseña"** para usuarios de tenant en esta historia (corrige la
    primera versión, que lo dejaba fuera) — mismo mecanismo de token + correo que ya usa
    ADMIN_CENTRAL, con un broker y una tabla `password_reset_tokens` propios por tenant.
13. La sesión de usuarios de tenant vive en la tabla `sessions` de la base central (igual que la de
    ADMIN_CENTRAL) — solo se cambia la base activa (vía el middleware de slug) al resolver el
    usuario autenticado, no el almacenamiento de la sesión en sí.
14. Se crea una notificación de correo nueva (`CredencialesUsuarioTenant`), separada de
    `CredencialesAdminCliente` (spec 010, ya en uso para el alta automática del primer AdminCliente)
    — no se reutiliza esa clase para evitar acoplar un flujo ya funcionando a este nuevo caso.
15. No se usa `Stancl\Tenancy\Middleware\InitializeTenancyByPath` del paquete `stancl/tenancy`: ese
    resolver identifica por la llave primaria del tenant (`id_tenant`), no por `slug`; se construye
    un middleware propio y simple en su lugar, siguiendo el mismo estilo manual que ya usa
    `CrearAdminClienteInicial`.
16. La columna `slug` se agrega `nullable` a nivel de base de datos (con backfill de filas
    existentes) para no romper tenants ya creados en desarrollo, aunque la aplicación siempre la
    exige al crear un tenant nuevo.
17. (Descubierto al implementar) `SESSION_CONNECTION` debe fijarse explícitamente a la conexión
    central (`mysql`) en `.env`/`.env.example`/`deploy/hostinger/env.production.example`: sin esto,
    `SESSION_DRIVER=database` usa la conexión *default*, que `tenant.slug` cambia a la conexión
    `tenant` durante la petición — y esa base no tiene tabla `sessions`, rompiendo cualquier login
    de tenant. La conexión de sesión debe quedar fija a la central sin importar la tenencia activa.
18. (Descubierto al implementar) Laravel corre los middleware de tipo "auth" muy temprano según su
    lista interna de prioridad (`$middlewarePriority`), sin importar en qué grupo de rutas se
    declaren — por default `auth:usuario` podía terminar ejecutándose antes que `tenant.slug` y
    cargar al usuario contra la base equivocada. Se resolvió con
    `$middleware->prependToPriorityList()` en `bootstrap/app.php`, insertando `tenant.slug` justo
    antes de `AuthenticatesRequests` en esa lista. Por la misma razón, el binding implícito de ruta
    (`Usuario $usuario` en la firma del controlador) tampoco es seguro aquí — `SubstituteBindings`
    no tiene garantizado correr después de `tenant.slug` — así que `UsuarioController` resuelve el
    usuario a mano (`Usuario::findOrFail($id)`) en vez de depender del binding automático.
19. La tabla de `ListaUsuariosView.vue` ocupa el 100% del ancho interior del card, sin `max-width`
    ni centrado propio; solo conserva un ancho mínimo para pantallas angostas, resuelto con scroll
    horizontal en su propio contenedor — misma corrección aplicada a `ListaTenantsView.vue` (spec
    008).
