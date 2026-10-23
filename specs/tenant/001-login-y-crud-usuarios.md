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

- **Identificación del tenant por slug en la URL** (`/t/{slug}/...`; desde la enmienda "el slug
  va inmediatamente después del dominio", al final de esta spec, la ruta de frontend es
  `/{slug}/...` y la de API sigue en `/api/v1/t/{slug}/...`), en vez de por subdominio — ver
  "Decisión técnica" para el porqué.
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

> **Enmendado:** el prefijo `/t/` se retiró del frontend; la dirección del tenant es
> `delivery.prosello.com.mx/acme/...` y la API conserva `/api/v1/t/acme/...`. Ver la enmienda al
> final de esta spec.

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
  escribe — se genera aleatoria (`App\Support\PasswordGenerada`: letras, números y un solo
  símbolo, sin diagonales), se guarda hasheada, y se envía por correo,
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
- **Rutas** (`router/index.ts`): ~~`/t/:slug/login`, `/t/:slug/forgot-password`,
  `/t/:slug/reset-password/:token`, `/t/:slug/panel/usuarios`,
  `/t/:slug/panel/usuarios/crear`, `/t/:slug/panel/usuarios/:id/editar`~~ **Enmendado:**
  `/:slug` (login, nombre `tenant-login`), `/:slug/forgot-password`,
  `/:slug/reset-password/:token`, `/:slug/panel/usuarios`, `/:slug/panel/usuarios/crear`,
  `/:slug/panel/usuarios/:id/editar`, protegidas con
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

1. El tenant se identifica por un `slug` en la URL (`/t/{slug}/...`; enmendado a `/{slug}/...`
   en el frontend, ver enmienda al final), no por subdominio — un
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

## Enmienda: el slug va inmediatamente después del dominio

> Historia del dueño del tenant: *"como usuario tenant no quiero ver la ruta de entrada así:
> `http://localhost:5173/t/moto-celaya/login`; quiero verla así: `http://localhost:5173/moto-celaya`.
> Quiero que mi slug se vea inmediatamente después del dominio principal. Prefiero que el
> admin_central tenga una ruta especial, pero la ruta del tenant debe ser fácil de entender."*
>
> Esta enmienda cambia **solo la dirección que ve el navegador**. La API sigue en
> `/api/v1/t/{slug}/...` y no se toca: el prefijo `/t/` deja de existir en las rutas de Vue Router,
> no en las de Laravel. Donde una spec anterior escriba una ruta de frontend como `/t/{slug}/...` o
> `/t/:slug/...`, léase `/{slug}/...` o `/:slug/...`; las rutas de API (`GET/POST /t/{slug}/...`,
> `/api/v1/t/...`) se leen tal cual.

### Historia de usuario

Como usuario de un tenant (AdminCliente, Despachador o Conductor), quiero que la dirección de mi
sistema sea `{dominio}/{mi-slug}` —sin prefijos ni palabras técnicas— para poder dictarla,
recordarla y compartirla con mi equipo sin explicar qué significa `/t/`.

### Objetivo / Alcance

Deja funcionando:

- **El slug como primer segmento de la ruta.** Todas las rutas de frontend del tenant pierden el
  prefijo `/t/`: `/moto-celaya`, `/moto-celaya/panel`, `/moto-celaya/panel/clientes`,
  `/moto-celaya/forgot-password`, `/moto-celaya/reset-password/{token}`, etc. Los nombres de ruta
  (`tenant-login`, `tenant-panel`, `tenant-clientes-lista`…) no cambian, así que ningún
  `router.push({ name, params: { slug } })` existente se toca.
- **`/{slug}` es la única entrada del tenant.** Sin sesión, muestra el login **ahí mismo**, sin
  cambiar la dirección. Con sesión, lleva al Panel (`/{slug}/panel`); el guard existente decide
  después si esa persona es operativa o va a Clientes (spec 007, RN-A1/RN-A2). Deja de existir
  `/{slug}/login` como ruta propia: la ruta `tenant-login` pasa a vivir en `/:slug`.
- **Las direcciones viejas siguen funcionando.** `/t/{slug}`, `/t/{slug}/login` y cualquier
  `/t/{slug}/lo-que-sea` redirigen a su equivalente sin `/t/` conservando el resto de la ruta,
  para que correos ya enviados, marcadores y enlaces guardados por despachadores y conductores
  no se rompan.
- **Los correos mandan la dirección nueva.** `UrlPanelTenant::login()` devuelve
  `{FRONTEND_URL}/{slug}`, y el enlace de restablecimiento pasa a
  `{FRONTEND_URL}/{slug}/reset-password/{token}?email=...`. El texto del correo de bienvenida del
  AdminCliente (spec 011, enmienda) se reescribe: en vez de "la parte `/t/{slug}/` identifica a tu
  negocio", dice que el nombre que va justo después del dominio (`/{slug}`) es el que identifica a
  su negocio.
- **Palabras reservadas.** Como el slug ahora ocupa el primer lugar después del dominio, ningún
  tenant puede tener un slug que sea una ruta del sistema. Al crear un tenant cuyo nombre comercial
  produzca uno de esos slugs, `generarSlugUnico` le agrega sufijo numérico (`admin-2`), igual que
  hoy cuando el slug ya está ocupado. La lista vive **una sola vez**, en el backend.
- **Slug inexistente.** Entrar a `/{slug}` con un slug que no corresponde a ningún tenant muestra,
  en la misma pantalla de entrada, "No encontramos ningún negocio en esta dirección" en vez del
  formulario de login. No se manda al admin central ni a una página en blanco.
- **Sin parpadeo al entrar con sesión.** Mientras se resuelve "¿esta persona ya inició sesión?",
  se muestra una pantalla neutra (logo centrado); el login y el Panel nunca se pintan uno detrás
  del otro.

**No** incluye:

- Cambios a la API (`/api/v1/t/{slug}/...`), a `IdentificarTenantPorSlug`, ni a la app de
  conductor (`panda_expess`), cuyo `VITE_API_BASE_URL` sigue incluyendo `/api/v1/t/{slug}`.
- Cambios al admin central: `/admin`, `/admin/login`, `/admin/tenants`, etc. se quedan donde están.
  Es la "ruta especial" que pidió el dueño.
- `/` (HomeView) y `/about`: siguen existiendo tal cual.
- Cambiar el slug de tenants ya creados, o hacerlo editable desde el formulario. Solo cambia la
  forma de la ruta.
- Subdominios por tenant (descartados en la decisión técnica original de esta spec; el motivo no
  cambió).

### Decisión técnica

#### Por qué `/:slug` no choca con `/admin` ni con `/about`

Vue Router ordena las rutas por especificidad, no por el orden en que se declaran: un segmento
estático (`/admin`, `/about`) siempre gana a uno dinámico (`/:slug`) para la misma URL. Así,
`/admin/tenants` sigue cayendo en `admin-tenants-lista` aunque exista `/:slug/panel/...`. No hace
falta reordenar el arreglo de rutas; sí hace falta que ningún tenant se llame `admin` o `about`,
que es lo que resuelve la lista de palabras reservadas.

Lo que Vue Router **no** ve son las rutas que nginx atiende antes de entregar `index.html`
(`deploy/vps/nginx-delivery.prosello.com.mx.conf`): `api`, `sanctum`, `up`, `gps`, `gps-health`, y
`assets` (carpeta del build de Vite). Un tenant con slug `gps` tendría una dirección que nginx
nunca le entregaría al SPA. Por eso la lista reservada incluye tanto las rutas de Vue como las de
nginx.

#### Lista única de palabras reservadas, en el backend (adición técnica 1)

Un arreglo `App\Support\SlugsReservados::LISTA` con: `admin`, `api`, `sanctum`, `up`, `gps`,
`gps-health`, `assets`, `about`, `t`, `index`, `login`, `panel`. `generarSlugUnico` lo consulta
igual que consulta `Tenant::where('slug', ...)`: si el slug base está reservado o en uso, prueba
`{base}-2`, `{base}-3`… El frontend no tiene copia de la lista: no la necesita, porque la única
puerta por la que entra un slug es el alta de tenant, y esa es del backend. Agregar una palabra
nueva es un cambio de un renglón en un archivo.

`t` se reserva por las redirecciones de compatibilidad (`/t/...` sigue siendo una ruta del SPA);
`login` y `panel`, para que `/{slug}` y `/{slug}/panel` nunca sean ambiguos con un tenant que se
llame así.

#### `tenant-login` vive en `/:slug`; la entrada decide sola

- La ruta `{ path: '/:slug', name: 'tenant-login', component: LoginView }` sustituye a
  `/t/:slug/login` **y** a la redirección `/t/:slug → tenant-panel` de la spec 007 (RN-A2).
- `beforeEach` gana una rama para `to.name === 'tenant-login'`: llama `auth.fetchMe(slug)` (si no
  está `checked` o el slug cambió) y, si `auth.isAuthenticated`, devuelve
  `{ name: 'tenant-panel', params: { slug } }`. El guard de `tenant-panel` hace el resto
  (operativo → mapa; no operativo → Clientes). Sin sesión, deja pasar y se pinta el login en
  `/{slug}`.
- `LoginView.vue` no cambia su formulario ni su destino tras autenticarse (RN-A1 de 007). Solo
  gana la variante "negocio no encontrado" (abajo).
- Todo lo que hoy "manda al login" —guard de `requiresTenantAuth`, `TenantLayout` al cerrar
  sesión, `ResetPasswordView` al terminar— ya usa `{ name: 'tenant-login', params: { slug } }` y
  resuelve solo a `/{slug}`. Sin cambios en esos archivos.

#### Slug inexistente se distingue de "sin sesión"

Hoy `tenantAuth.fetchMe` traga cualquier error y deja `usuario = null`, así que un slug
inexistente y una sesión vencida se ven iguales: se pinta el login y, al enviarlo, aparece "Correo
o contraseña incorrectos" (la API responde `404` desde `IdentificarTenantPorSlug`). Con el slug
pegado al dominio, cualquier URL mal escrita cae en `/:slug`, así que el caso se vuelve común y
merece un mensaje propio. `fetchMe` distingue el `404` del resto: guarda `tenantExiste = false`
(estado nuevo en el store) y `LoginView` muestra "No encontramos ningún negocio en esta dirección.
Revisa el enlace que te compartieron." en lugar del formulario. `401` y errores de red se siguen
tratando como "sin sesión".

#### Pantalla neutra hasta que el router resuelva (adición técnica 2)

El guard de `tenant-login` espera a `fetchMe` antes de dejar pintar nada, así que el formulario
nunca se muestra "un instante" a quien ya tiene sesión. Lo que sí se ve durante esa espera, hoy,
es un fondo blanco vacío. `App.vue` muestra el logo centrado (mismo `logo.svg` de los logins)
mientras `router.isReady()` no haya resuelto la navegación inicial, y después monta `<RouterView>`.
Es un cambio de `App.vue`, no de cada vista, y cubre también al admin central.

#### Redirecciones de compatibilidad

Una sola ruta `/t/:slug/:resto(.*)*` con `redirect` calculado (`'/' + slug` más el resto de
segmentos, si los hay) cubre `/t/{slug}`, `/t/{slug}/login` y cualquier ruta profunda
(`/t/{slug}/panel/clientes` → `/{slug}/panel/clientes`). Como `login` deja de existir como
segmento, se agrega también `/:slug/login` → `/:slug`, de modo que el correo ya enviado con
`/t/{slug}/login` termina en `/{slug}` en dos saltos y sin `404`. Las redirecciones se quedan
indefinidamente: no cuestan nada y los correos viejos no caducan.

#### Backend: dos constantes, una lista

- `UrlPanelTenant::login()` devuelve `{FRONTEND_URL}/{slug}` (el nombre del método se conserva: el
  destino sigue siendo "la pantalla de entrada", que ahora es la raíz del tenant).
- `AppServiceProvider::boot()`, en `ResetPassword::createUrlUsing`, arma
  `{FRONTEND_URL}/{slug}/reset-password/{token}?email=...`.
- `CredencialesAdminCliente::toMail()` reescribe la línea de explicación (ver RN-S6).
- `SlugsReservados` (nuevo) y `TenantController::generarSlugUnico` (lo consulta).

### Reglas de negocio (de esta enmienda)

- **RN-S1** Toda ruta de frontend del tenant tiene la forma `/{slug}[/...]`; ninguna lleva `/t/`.
- **RN-S2** `/{slug}` sin sesión muestra el login en esa misma dirección; con sesión redirige a
  `tenant-panel`, y desde ahí aplican RN-A1/RN-A2 de la spec 007 (operativo → Panel; no operativo
  → Clientes).
- **RN-S3** No existe `/{slug}/login` como pantalla; si se escribe, redirige a `/{slug}`.
- **RN-S4** `/t/{slug}[/...]` redirige a `/{slug}[/...]` conservando el resto de la ruta.
- **RN-S5** El slug de un tenant nuevo nunca coincide con una palabra de `SlugsReservados::LISTA`;
  si el nombre comercial la produce, recibe sufijo numérico como cualquier choque de unicidad.
- **RN-S6** Los correos de credenciales (AdminCliente inicial y usuarios del tenant) enlazan a
  `{FRONTEND_URL}/{slug}`; el correo del AdminCliente explica que el nombre que va justo después
  del dominio identifica a su negocio. El correo de restablecimiento enlaza a
  `{FRONTEND_URL}/{slug}/reset-password/{token}?email=...`.
- **RN-S7** Un slug que no corresponde a ningún tenant muestra "No encontramos ningún negocio en
  esta dirección" en `/{slug}`, sin formulario, sin redirigir a `/admin` ni a `/`.
- **RN-S8** Mientras el router resuelve la navegación inicial se muestra el logo centrado; el login
  y el Panel nunca se pintan en secuencia para una misma carga.
- **RN-S9** El admin central, `/` y `/about` no cambian. Los slugs de tenants existentes no cambian.

### Backend (Laravel) — cambios de esta enmienda

- **`App\Support\SlugsReservados`** (nuevo): `const LISTA = [...]` y `static function
  esReservado(string $slug): bool`.
- **`App\Http\Controllers\Admin\TenantController::generarSlugUnico`**: la condición del `while`
  pasa a ser `SlugsReservados::esReservado($slug) || Tenant::where('slug', $slug)->exists()`.
- **`App\Support\UrlPanelTenant::login`**: `"/{$slug}"` en lugar de `"/t/{$slug}/login"`.
- **`App\Providers\AppServiceProvider::boot`**: `"{$frontendUrl}/".tenant('slug')."/reset-password/..."`.
- **`App\Notifications\CredencialesAdminCliente::toMail`**: línea de explicación nueva: "Esa
  dirección es exclusiva de {nombre comercial}: el nombre que va justo después del dominio
  (`/{slug}`) identifica a tu negocio. Úsala siempre para entrar y compártela con tus
  despachadores y conductores; es la misma para todo tu equipo."
- **`tests/Feature/Admin/TenantTest.php`**: la aserción del correo pasa a
  `http://localhost:5173/{slug}`; prueba nueva: crear un tenant con `nombre_comercial = 'Admin'`
  produce slug `admin-2`.
- **Prueba de la URL de reset** (`UsuarioTest` o el que cubra `forgotPassword`): el enlace del
  correo contiene `/{slug}/reset-password/`.

### Frontend (Vue 3) — cambios de esta enmienda

- **`router/index.ts`**:
  - Todas las rutas `'/t/:slug...'` pasan a `'/:slug...'`. Nombres y `meta` sin cambios.
  - `'/t/:slug/login'` desaparece; `tenant-login` es `{ path: '/:slug' }`.
  - La redirección `'/t/:slug' → tenant-panel` (spec 007) desaparece; la sustituye la rama de
    `beforeEach` descrita arriba.
  - Redirecciones nuevas: `'/t/:slug/:resto(.*)*'` → `'/:slug/:resto'`, y `'/:slug/login'` →
    `'/:slug'`.
  - `beforeEach`: rama para `tenant-login` (fetchMe + redirección a `tenant-panel` si hay sesión).
- **`stores/tenantAuth.ts`**: estado nuevo `tenantExiste` (`true` por defecto); `fetchMe` lo pone
  en `false` cuando la respuesta es `404`.
- **`views/tenant/LoginView.vue`**: `v-if="!auth.tenantExiste"` muestra el mensaje de RN-S7 en el
  lugar del formulario (mismo layout, banner y logo).
- **`App.vue`**: pantalla neutra con el logo hasta `router.isReady()`.
- **`layouts/TenantLayout.vue`**: sin cambios (ya navega por nombre de ruta).

### Fuera de alcance (de esta enmienda)

- Renombrar o hacer editable el slug.
- Un mensaje 404 general para rutas desconocidas fuera del patrón `/:slug` (p. ej.
  `/admin/lo-que-sea`): sigue comportándose como hoy.
- Cambios en la app de conductor.

### Criterios de aceptación (de esta enmienda)

1. Visitar `/{slug}` sin sesión muestra el formulario de login y la barra de direcciones sigue
   diciendo `/{slug}`.
2. Visitar `/{slug}` con sesión operativa termina en `/{slug}/panel`; con sesión no operativa, en
   `/{slug}/panel/clientes`. En ninguno de los dos casos se alcanza a ver el formulario de login.
3. Visitar `/{slug}/login`, `/t/{slug}` y `/t/{slug}/login` termina en `/{slug}`.
4. Visitar `/t/{slug}/panel/clientes/5/direcciones` termina en `/{slug}/panel/clientes/5/direcciones`.
5. Cerrar sesión desde el layout del tenant deja en `/{slug}`.
6. `/admin`, `/admin/login`, `/admin/tenants`, `/` y `/about` se comportan exactamente igual que
   antes.
7. Visitar `/un-slug-que-no-existe` muestra "No encontramos ningún negocio en esta dirección" sin
   formulario; la pestaña Red muestra un solo `GET /api/v1/t/un-slug-que-no-existe/me` con `404`.
8. Crear un tenant con nombre comercial "Admin" produce slug `admin-2`; con "GPS", `gps-2`; con
   "Assets", `assets-2`. Un segundo tenant "Admin" produce `admin-3`.
9. Al crear un tenant con slug `cafe-luna`, el correo en Mailpit contiene
   `http://localhost:5173/cafe-luna` como texto y como botón "Ir al panel", y la frase "el nombre
   que va justo después del dominio".
10. El correo de "olvidé mi contraseña" de un usuario de tenant enlaza a
    `http://localhost:5173/{slug}/reset-password/{token}?email=...`, y ese enlace abre la pantalla
    de restablecimiento.
11. Mientras carga `/{slug}` (throttling de red en DevTools) se ve el logo centrado, no un fondo
    blanco ni el formulario.
12. `TenantTest`, `UsuarioTest` y Pint pasan; ESLint/Prettier sin errores.

### Supuestos asumidos (continúa el registro de arriba)

20. **Todas** las rutas de frontend del tenant pierden `/t/`, no solo el login.
21. ~~`/{slug}` es una puerta que redirige a `/{slug}/login`.~~ **Ajustado al preguntar**:
    `/{slug}` es la única entrada; sin sesión muestra el login ahí mismo y con sesión va al Panel.
    `/{slug}/login` deja de existir como ruta propia.
22. Cualquier lugar que "mande al login" manda a `/{slug}`.
23. El admin central se queda en `/admin/...`, sin cambios.
24. Las direcciones viejas `/t/{slug}/...` siguen funcionando y redirigen a su equivalente nuevo.
25. Los correos de credenciales mandan `/{slug}`, y el texto que explicaba `/t/{slug}/` se
    reescribe.
26. Un slug no puede chocar con una ruta del sistema; si el nombre comercial lo produce, recibe
    sufijo numérico automáticamente.
27. Un slug inexistente muestra "negocio no encontrado" en `/{slug}`, sin ir al admin central ni a
    una página en blanco. (Al verificar el código: hoy no existe ese mensaje —se muestra el login
    y falla con "Correo o contraseña incorrectos"—, así que se agrega en esta enmienda.)
28. `/` y `/about` no se tocan.
29. Los tenants ya creados no cambian de slug.

### Adiciones técnicas aceptadas

1. Lista única de palabras reservadas en el backend (`SlugsReservados`), consultada por
   `generarSlugUnico`; incluye las rutas que atiende nginx además de las de Vue.
2. Pantalla neutra con el logo mientras se resuelve la sesión al entrar a `/{slug}`, en `App.vue`.
