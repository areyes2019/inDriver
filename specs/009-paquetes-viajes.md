# Spec: Catálogo de paquetes de viajes (crear, listar, editar, activar/desactivar, eliminar)

## Historia de usuario

Como ADMIN_CENTRAL, quiero crear y administrar paquetes de viajes para mis tenants, para poder
ofrecerles una forma de comprar viajes adicionales sin depender de un plan de suscripción con
límite mensual.

## Objetivo / Alcance

Dejar funcionando el catálogo global de paquetes de viajes: crear, listar (con paginación),
editar, activar/desactivar y eliminar (borrado lógico) un paquete, todo desde el panel de
ADMIN_CENTRAL. **No** incluye el flujo de que un tenant compre efectivamente un paquete (la tabla
`compras_paquetes`, que ya existe en la base de cada tenant, se conecta a este catálogo en una
historia futura), ni ninguna lógica de bloqueo de acceso por falta de viajes disponibles.

## Decisión técnica

Los paquetes de viajes **reemplazan por completo** el modelo de planes/suscripciones descrito
originalmente en `db/01-base-de-datos.md`: no existe límite mensual de pedidos por plan, ni
vigencia de suscripción. Un tenant simplemente consume viajes de los paquetes que ha comprado (la
lógica de consumo queda para una historia futura). Como consecuencia:

- Se eliminan las migraciones `create_planes_table` y `create_suscripciones_table`
  (`backend/database/migrations/2026_08_28_041514_create_planes_table.php` y
  `2026_08_28_041517_create_suscripciones_table.php`): nunca llegaron a tener modelo, controlador
  ni datos, así que no hay nada que migrar ni preservar.
- Se actualiza `db/01-base-de-datos.md`: se quitan las secciones de `PLANES` y `suscripciones`
  (tabla, relaciones y menciones en reglas de negocio), se agrega la tabla `paquetes_viajes` en su
  lugar, y se deja explícito que la regla "el sistema bloquea el acceso si la suscripción está
  vencida" queda derogada — cómo un tenant se queda sin acceso por falta de viajes es una decisión
  pendiente para una historia futura.

El catálogo vive en `delivery_central` (tabla `paquetes_viajes`) porque lo administra
ADMIN_CENTRAL, que no tiene acceso directo a la base de cada tenant. La futura compra
(`compras_paquetes`, en la base del tenant) seguirá referenciando un paquete por
`codigo_paquete` (string libre), no por llave foránea real — no es posible una FK entre bases de
datos distintas, mismo motivo ya documentado para esa tabla.

"Eliminar" es un borrado lógico (`SoftDeletes` de Laravel, columna `deleted_at`), no un `DELETE`
físico: como las compras futuras solo van a referenciar el `codigo_paquete` como texto libre, no
hay forma de saber desde `delivery_central` si algún tenant ya compró un paquete antes de
borrarlo. Mantener la fila (oculta de listados y ya no comprable) evita que una compra histórica
quede apuntando a un `codigo_paquete` sin ningún registro que la explique.

## Backend (Laravel)

- **Migración** `create_paquetes_viajes_table` (conexión central, `delivery_central`):
  - `id_paquete` (PK autoincremental).
  - `codigo_paquete` (string, único) — el identificador libre que en el futuro usará
    `compras_paquetes.codigo_paquete`.
  - `nombre` (string, requerido).
  - `descripcion` (text, nullable).
  - `cantidad_viajes` (entero sin signo, requerido).
  - `precio` (decimal 10,2, requerido).
  - `estado` (enum `Activo`/`Inactivo`, default `Activo`).
  - `timestamps()` + `softDeletes()`.
- **Migraciones a eliminar**: `2026_08_28_041514_create_planes_table.php` y
  `2026_08_28_041517_create_suscripciones_table.php` (se borran del repo; nunca se corrieron en
  producción — ver `project_sistema_sin_produccion` — así que no hace falta migración de
  reversión).
- **Modelo** `App\Models\PaqueteViaje` (`SoftDeletes`), `$primaryKey = 'id_paquete'`. Sin
  `$connection` explícita (conexión por defecto, ya es `delivery_central`).
- **Rutas** (`routes/api.php`, dentro del grupo `admin` ya protegido por `middleware('auth:admin')`,
  reutilizando el limitador `admin-tenants` existente — incluir "paquetes" en su nombre no aporta
  nada distinto, ya que ambos limitan la misma clase de acciones administrativas):
  - `GET /api/v1/admin/paquetes-viajes` — lista paginada, con búsqueda por nombre.
  - `POST /api/v1/admin/paquetes-viajes` — crea un paquete.
  - `GET /api/v1/admin/paquetes-viajes/{paquete}` — detalle.
  - `PUT /api/v1/admin/paquetes-viajes/{paquete}` — edita.
  - `PATCH /api/v1/admin/paquetes-viajes/{paquete}/estado` — alterna Activo/Inactivo.
  - `DELETE /api/v1/admin/paquetes-viajes/{paquete}` — borrado lógico (`$paquete->delete()`).
- **Controlador** `App\Http\Controllers\Admin\PaqueteViajeController`:
  - `index(Request $request)`: pagina con `PaqueteViaje::query()`, ordenado por `created_at`
    descendente. Si llega `search`, filtra `nombre` con `LIKE %search%`. Tamaño de página fijo
    (15). Responde `PaqueteViajeResource::collection(...)` paginado.
  - `store(Request $request)`: valida inline (mismo estilo que `TenantController`):
    - `codigo_paquete`: requerido, string, único en `paquetes_viajes.codigo_paquete` (incluyendo
      borrados lógicos, para no reutilizar el código de un paquete eliminado).
    - `nombre`: requerido, string.
    - `descripcion`: opcional, string.
    - `cantidad_viajes`: requerido, entero, mínimo 1.
    - `precio`: requerido, numérico, mínimo 0.
    - Responde `422` con el error de validación si `codigo_paquete` ya existe. Crea el paquete
      (`estado = Activo` por defecto). Inserta en `logs_centrales`: `id_tenant = null`, `id_admin`
      (el admin autenticado), `tipo = 'PAQUETE'`, `accion = 'ALTA'`, `descripcion` con el nombre
      del paquete. Responde `201` con `PaqueteViajeResource`.
  - `show(PaqueteViaje $paquete)`: responde `PaqueteViajeResource`.
  - `update(Request $request, PaqueteViaje $paquete)`: valida igual que `store`, pero `unique` de
    `codigo_paquete` ignora el propio `id_paquete`. Actualiza, guarda, inserta en `logs_centrales`
    (`tipo = 'PAQUETE'`, `accion = 'EDICION'`). Responde `200`.
  - `cambiarEstado(PaqueteViaje $paquete)`: alterna `estado` entre `Activo` e `Inactivo`. Inserta
    en `logs_centrales` (`tipo = 'PAQUETE'`, `accion = 'CAMBIO_ESTADO'`, `descripcion` con el
    estado nuevo). Responde `200` con `PaqueteViajeResource`.
  - `destroy(PaqueteViaje $paquete)`: `$paquete->delete()` (soft delete). Inserta en
    `logs_centrales` (`tipo = 'PAQUETE'`, `accion = 'BAJA'`, `descripcion` con el nombre del
    paquete). Responde `204`.
- **Resource** `App\Http\Resources\Admin\PaqueteViajeResource`: expone todas las columnas propias
  del paquete.
- **`logs_centrales`**: la columna `id_tenant` ya es nullable de origen (no hay tenant asociado a
  estas acciones); se usa `null` en los cuatro casos de esta historia.

## Frontend (Vue 3)

- **Vista nueva** `frontend/src/views/admin/paquetes/ListaPaquetesView.vue`: mismo patrón que
  `ListaTenantsView.vue` — buscador con debounce de 300ms (filtra por nombre), tabla con columnas
  (código, nombre, cantidad de viajes, precio, estado), paginación, y por fila: "Editar",
  "Activar"/"Desactivar" (con `UiConfirmDialog`, reutilizado tal cual de la spec 008) y "Eliminar"
  (también con `UiConfirmDialog`).
- **Vista nueva** `frontend/src/views/admin/paquetes/CrearPaqueteView.vue`: formulario con
  `codigo_paquete`, `nombre`, `descripcion`, `cantidad_viajes`, `precio`. Al enviar, llama al
  endpoint de creación; en éxito muestra confirmación y redirige a la lista; en error muestra los
  mensajes de validación junto a cada campo.
- **Vista nueva** `frontend/src/views/admin/paquetes/EditarPaqueteView.vue`: mismo formulario que
  `CrearPaqueteView.vue` (sin `codigo_paquete` editable — se muestra de solo lectura, ya que es el
  identificador que usarán las compras futuras), precargado con los datos actuales. Al enviar,
  llama a `PUT /admin/paquetes-viajes/{id}`; en éxito confirma y redirige a la lista.
- **Rutas** (`router/index.ts`), todas con `meta: { requiresAdminAuth: true }`:
  - `/admin/paquetes` → `admin-paquetes-lista`.
  - `/admin/paquetes/crear` → `admin-paquetes-crear`.
  - `/admin/paquetes/:id/editar` → `admin-paquetes-editar`.
- **`DashboardView.vue`**: se agrega un enlace ("Paquetes de viajes") hacia `/admin/paquetes`,
  junto a los botones/enlaces existentes de tenants.
- **Store/cliente HTTP**: igual que las vistas de tenants, se usa `lib/http.ts` directamente, sin
  store Pinia nuevo.

## Fuera de alcance

- Que un tenant compre efectivamente un paquete (conectar este catálogo con `compras_paquetes`,
  que ya existe en la base de cada tenant) — historia futura.
- Cualquier lógica de consumo de viajes o bloqueo de acceso a un tenant por falta de viajes
  disponibles — historia futura, pendiente de definir.
- Pantalla de detalle del paquete separada de la edición (a diferencia de tenants, no hay
  `DetallePaqueteView.vue`; "Editar" ya muestra todos los campos).
- Reactivar (revertir) un paquete eliminado lógicamente desde la interfaz.
- Descuentos, vigencia, o cualquier campo adicional del paquete más allá de código, nombre,
  descripción, cantidad de viajes, precio y estado.
- Cualquier vista o endpoint accesible por tenants o sus usuarios (AdminCliente, Despachador,
  Conductor) — este catálogo solo lo administra ADMIN_CENTRAL.

## Criterios de aceptación

1. Las migraciones `create_planes_table` y `create_suscripciones_table` ya no existen en el
   repositorio; las tablas `planes` y `suscripciones` no se crean al migrar.
2. `db/01-base-de-datos.md` ya no menciona `planes` ni `suscripciones`; documenta `paquetes_viajes`
   en su lugar y deja explícito que el bloqueo de acceso por suscripción vencida queda derogado.
3. `POST /api/v1/admin/paquetes-viajes` sin sesión de admin responde `401`.
4. `POST /api/v1/admin/paquetes-viajes` con `codigo_paquete`, `nombre`, `cantidad_viajes` o
   `precio` faltantes o inválidos responde `422`.
5. `POST /api/v1/admin/paquetes-viajes` con un `codigo_paquete` ya existente (incluyendo el de un
   paquete eliminado lógicamente) responde `422`.
6. `POST /api/v1/admin/paquetes-viajes` con datos válidos responde `201`, crea el paquete
   (`estado = Activo`), y existe un registro en `logs_centrales` con `tipo = 'PAQUETE'`,
   `accion = 'ALTA'`.
7. `GET /api/v1/admin/paquetes-viajes` responde una página de paquetes (máximo 15), sin incluir
   los eliminados lógicamente; `?search=<texto>` filtra por nombre.
8. `PUT /api/v1/admin/paquetes-viajes/{id}` actualiza el paquete y responde `200`; reenviar el
   mismo `codigo_paquete` que ya tenía no falla por duplicado; el `codigo_paquete` de otro paquete
   sí responde `422`. Existe un registro en `logs_centrales` con `accion = 'EDICION'`.
9. `PATCH /api/v1/admin/paquetes-viajes/{id}/estado` alterna `Activo`/`Inactivo` y responde `200`;
   existe un registro en `logs_centrales` con `accion = 'CAMBIO_ESTADO'`.
10. `DELETE /api/v1/admin/paquetes-viajes/{id}` deja el paquete con `deleted_at` distinto de nulo
    (no lo borra físicamente), responde `204`, ya no aparece en `GET /paquetes-viajes`, y existe un
    registro en `logs_centrales` con `accion = 'BAJA'`.
11. El frontend expone `/admin/paquetes`, `/admin/paquetes/crear` y `/admin/paquetes/:id/editar`,
    protegidas por el mismo guard que `/admin`.
12. `DashboardView.vue` muestra un enlace hacia `/admin/paquetes`.
13. En `/admin/paquetes`, "Activar"/"Desactivar" y "Eliminar" piden confirmación mediante
    `UiConfirmDialog` antes de ejecutarse; cancelar no llama al endpoint ni altera la fila.
14. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. Los paquetes de viajes reemplazan por completo el modelo de planes/suscripciones: no hay
   límite mensual de pedidos por plan ni vigencia de suscripción. Cómo un tenant consume sus
   viajes o qué pasa cuando se le acaban queda para una historia futura.
2. Como consecuencia, se eliminan del repositorio las migraciones (sin uso, sin modelo, sin datos)
   de `planes` y `suscripciones`, y se actualiza `db/01-base-de-datos.md` para reflejarlo.
3. "Paquetes de viajes" es un catálogo global (tabla `paquetes_viajes` en `delivery_central`), no
   uno distinto por tenant — cualquier tenant podrá comprar el mismo paquete en el futuro. No hay
   selector de tenant en el formulario de creación/edición.
4. El catálogo vive en `delivery_central` porque lo administra ADMIN_CENTRAL, que no tiene acceso
   directo a la base de cada tenant.
5. Un paquete tiene: `codigo_paquete` (identificador libre único, el que usará
   `compras_paquetes.codigo_paquete` en el futuro), `nombre`, `descripcion`, `cantidad_viajes`,
   `precio` y `estado` (Activo/Inactivo).
6. Esta historia cubre el CRUD completo del catálogo: crear, listar, editar, activar/desactivar y
   eliminar. No incluye el flujo de compra de un tenant ni la lógica de consumo de viajes.
7. "Eliminar" es un borrado lógico (`SoftDeletes`), no físico: al no poder verificar desde
   `delivery_central` si algún tenant ya compró el paquete, se prefiere ocultarlo en vez de
   borrarlo, para no dejar compras históricas apuntando a un código sin explicación.
8. Cualquier ADMIN_CENTRAL autenticado puede administrar el catálogo — mismo nivel de acceso sin
   roles diferenciados, siguiendo la regla ya establecida para tenants.
9. Se accede desde un enlace nuevo en `DashboardView.vue`, junto a los ya existentes de tenants.
10. Las rutas reutilizan el limitador `admin-tenants` ya definido (20 intentos por minuto por
    admin autenticado), sin crear uno nuevo específico para paquetes.
11. Todas las acciones (alta, edición, cambio de estado, baja) se registran en `logs_centrales`
    con `tipo = 'PAQUETE'` y `id_tenant = null`, siguiendo el mismo patrón de auditoría que ya usa
    la spec 007 para tenants.
12. No hay pantalla de detalle separada de la edición (a diferencia de tenants): "Editar" ya
    muestra y permite modificar todos los campos del paquete.
