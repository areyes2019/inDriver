# Spec: Alta de tenants desde el panel de ADMIN_CENTRAL

## Historia de usuario

Como ADMIN_CENTRAL (dueño del sistema), quiero tener un acceso desde el Dashboard hacia un panel
de creación de tenants, para poder dar de alta un nuevo negocio en el sistema sin intervención
manual en la base de datos.

## Objetivo / Alcance

Dejar funcionando el alta de un tenant nuevo: el enlace en el Dashboard, el formulario con los
datos esenciales del negocio, y el endpoint que crea el registro en `tenants` (lo que dispara el
aprovisionamiento automático de su base de datos, ya funcional desde `003-multi-tenant-stancl.md`).
**No** incluye listado ni edición de tenants existentes, asignación de plan/suscripción, ni alta
del ADMIN_CLIENTE del tenant — todo eso queda para historias futuras.

## Decisión técnica

Cualquier ADMIN_CENTRAL autenticado (guardia `admin`) puede crear tenants — no se introduce un rol
"SuperAdmin" ni se usa la columna `rol` de `admins_centrales` para esto, siguiendo lo ya definido
en `db/01-base-de-datos.md` ("todos los ADMIN_CENTRAL tienen el mismo nivel de acceso").

## Backend (Laravel)

- **Ruta** (`routes/api.php`, dentro del grupo `admin`, bajo `middleware('auth:admin')`):
  - `POST /api/v1/admin/tenants` (`throttle:admin-tenants`) — crea un tenant.
- **Rate limiting**: `RateLimiter::for('admin-tenants', ...)` — 20 intentos por minuto por admin
  autenticado (más permisivo que el de login, ya que es una acción legítima y repetida, pero
  suficiente para evitar altas masivas por error o abuso).
- **Controlador** `App\Http\Controllers\Admin\TenantController@store`:
  - Valida inline (mismo estilo que `AuthController`, sin `FormRequest` aparte):
    - `nombre_comercial`: requerido, string.
    - `razon_social`: requerido, string.
    - `rfc`: opcional, string; único en `tenants.rfc` ignorando nulos.
    - `telefono`: opcional, string.
    - `email`: opcional, email; único en `tenants.email` ignorando nulos.
  - Si `rfc` o `email` ya existen en otro tenant, responde `422` con el error de validación
    correspondiente (mensaje estándar de Laravel, sin mensaje genérico — a diferencia del login,
    aquí sí es útil decirle al admin cuál dato está duplicado).
  - Crea el tenant con `$tenant->save()` dentro de un `try/catch` (sin envolver en
    `DB::transaction`: el aprovisionamiento de la base del tenant, evento `TenantCreated` -> job
    síncrono de stancl/tenancy, reconecta la conexión central por debajo y una transacción abierta
    ahí queda inválida). Si falla el aprovisionamiento, el rollback es manual: si el tenant llegó a
    insertarse (`$tenant->exists`), se borra (`$tenant->delete()`, que a su vez dispara la limpieza
    de la base física ya creada, si la hubo); se registra el error real con `Log::error()`, y se
    responde `500` con un mensaje genérico ("No se pudo crear el tenant, intenta de nuevo").
  - Tras crear el tenant con éxito, inserta un registro en `logs_centrales`: `id_tenant` (el
    recién creado), `id_admin` (el admin autenticado), `tipo = 'TENANT'`,
    `accion = 'ALTA'`, `descripcion` con el nombre comercial del tenant.
  - Responde `201` con el tenant creado (`TenantResource`, sin exponer `database_password`).
- **Resource** `App\Http\Resources\Admin\TenantResource`: expone las columnas propias del tenant
  excepto `database_password` (credencial interna de la base del tenant, no debe viajar al
  frontend).

## Frontend (Vue 3)

- **Vista nueva** `frontend/src/views/admin/tenants/CrearTenantView.vue`: formulario con
  `nombre_comercial`, `razon_social`, `rfc`, `telefono`, `email`. Al enviar, llama al endpoint de
  creación; en éxito muestra un mensaje de confirmación y redirige a `/admin` (Dashboard); en error
  muestra los mensajes de validación (incluyendo RFC/email duplicado) junto a cada campo. El botón
  "Crear tenant" lleva un espacio adicional por encima, siguiendo el patrón de formularios de la
  guía de diseño base (`005-guia-diseno-base.md`).
- **Ruta** (`router/index.ts`): `/admin/tenants/crear`, nombre `admin-tenants-crear`, protegida con
  `meta: { requiresAdminAuth: true }` (mismo guard que ya usa `/admin`).
- **`DashboardView.vue`**: se agrega un botón/tarjeta ("Crear tenant") que navega a
  `/admin/tenants/crear`, junto al botón de cerrar sesión existente.
- **Store/cliente HTTP**: reutiliza `lib/http.ts` tal cual (cookies de sesión Sanctum), sin store
  Pinia nuevo — el formulario llama al endpoint directamente, igual de simple que el alcance de
  esta historia.

## Fuera de alcance

- Listado, edición, suspensión o eliminación de tenants existentes.
- Asignación de plan o suscripción al crear el tenant (tabla `suscripciones`).
- Alta del ADMIN_CLIENTE inicial del tenant (vive en la base del propio tenant, fuera de
  `delivery_central`).
- Selección manual de `estado` o `modo_estado` al crear — quedan en sus valores por defecto
  (`Activo` / `AUTOMATICO`).
- Campos de dirección del tenant (`calle`, `numero_int`, `numero_ext`, `colonia`, `cp`, `ciudad`,
  `estado_direccion`, `pais`) — no se piden en este formulario.
- Un rol "SuperAdmin" distinto del resto de ADMIN_CENTRAL, o cualquier uso de la columna `rol` de
  `admins_centrales`.
- Pantalla de detalle del tenant recién creado.

## Criterios de aceptación

1. `POST /api/v1/admin/tenants` sin sesión de admin responde `401`.
2. `POST /api/v1/admin/tenants` con `nombre_comercial` y/o `razon_social` faltantes responde `422`.
3. `POST /api/v1/admin/tenants` con un `rfc` o `email` que ya existe en otro tenant responde `422`
   señalando el campo duplicado, sin crear el registro.
4. `POST /api/v1/admin/tenants` con datos válidos responde `201`, crea el registro en `tenants`
   (`estado = Activo`, `modo_estado = AUTOMATICO`), aprovisiona su base de datos
   (`delivery_tenant_<id_tenant>`, migrada), y el body no incluye `database_password`.
5. Tras una creación exitosa, existe un registro en `logs_centrales` con `tipo = 'TENANT'`,
   `accion = 'ALTA'`, el `id_tenant` recién creado y el `id_admin` del admin autenticado.
6. Si el aprovisionamiento de la base de datos falla, no queda ningún registro huérfano en
   `tenants` (se borra a mano el que se alcanzó a insertar) y la respuesta es `500` con mensaje
   genérico.
7. El 21º intento de creación en 60 segundos para el mismo admin responde `429`.
8. El frontend expone `/admin/tenants/crear`, protegida por el mismo guard de `/admin`.
9. `DashboardView.vue` muestra un botón/enlace hacia `/admin/tenants/crear`.
10. Al crear un tenant desde el formulario, se muestra confirmación y se regresa al Dashboard; los
    errores de validación (incluyendo duplicados) se muestran junto a cada campo.
11. El botón "Crear tenant" se ve claramente separado del campo anterior (o del mensaje de error/
    éxito), no pegado a él.
12. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. "SuperAdmin" es el nombre que se le da al ADMIN_CENTRAL en este contexto, no un rol nuevo:
   cualquier ADMIN_CENTRAL autenticado puede crear tenants, igual que ya establece
   `db/01-base-de-datos.md`.
2. El alcance es el enlace en el Dashboard más el panel/formulario de creación con su endpoint —
   sin listado ni edición de tenants existentes.
3. El formulario solo pide `nombre_comercial`, `razon_social`, `rfc`, `telefono`, `email`; los
   campos de dirección quedan para una historia futura.
4. Crear el tenant no asigna plan/suscripción — eso es una historia futura de gestión de
   suscripciones.
5. Crear el tenant no crea el ADMIN_CLIENTE inicial — también queda para una historia futura.
6. `estado` y `modo_estado` quedan en sus valores por defecto; el formulario no los expone.
7. Tras crear el tenant, se confirma y se regresa al Dashboard — sin pantalla de detalle.
8. Se evita duplicar tenants validando unicidad de `rfc` y `email` (ignorando nulos).
9. Si el aprovisionamiento de la base del tenant falla, se revierte todo a mano (sin registros
   huérfanos, sin `DB::transaction` porque el aprovisionamiento invalida una transacción abierta
   en la conexión central) y se informa con un mensaje genérico, dejando el detalle real en el log
   del servidor.
10. Se registra el alta en `logs_centrales` (`tipo = 'TENANT'`, `accion = 'ALTA'`), siguiendo la
    regla de negocio de la spec 01 de auditar altas de tenants.
11. Se limita a 20 intentos de creación por minuto por admin autenticado, para evitar altas
    masivas accidentales o abusivas.
12. El botón de envío del formulario sigue el patrón de espaciado de la guía de diseño base (005):
    más separación por encima que la que ya hay entre los campos del formulario, para distinguirse
    como la acción final.
