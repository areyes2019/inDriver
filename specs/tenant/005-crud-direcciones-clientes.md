# Spec: CRUD de direcciones de cliente

## Historia de usuario

Como AdminCliente, quiero registrar varias direcciones para un mismo cliente (casa, trabajo,
negocio...), verlas, editarlas y eliminarlas, para poder usarlas al armar un pedido sin volver a
capturar la dirección completa cada vez.

## Objetivo / Alcance

Cubre la tabla 07 (`direcciones_clientes`) de `db/02-base-de-datos.md`, que depende de `clientes`
(tabla 06, ya implementada — `ClienteController`, sin historia de spec dedicada previa).

Deja funcionando, dentro de la ficha de un cliente puntual:

- Listar sus direcciones.
- Crear una dirección nueva.
- Editar una dirección existente.
- Eliminar una dirección (borrado físico).

**No** incluye:

- Una pantalla "todas las direcciones" a nivel tenant — siempre se navega en el contexto de un
  cliente.
- Un mapa o selector visual de coordenadas — latitud/longitud son inputs numéricos simples.
- Cualquier relación con `pedidos` — ahí la dirección se sigue capturando como texto/coordenadas
  propias (`direccion_recogida`, `direccion_entrega`), sin referenciar esta tabla.
- Una pantalla de "detalle" de cliente de solo lectura — se navega directo desde el listado de
  clientes al listado de direcciones.

## Decisión técnica

### Por qué sí hay borrado físico (a diferencia de `vehiculos`)

`conductor_vehiculo.id_vehiculo` obligó a que `vehiculos` no se pudiera borrar físico, porque
destruiría el historial de asignaciones. `direcciones_clientes` no tiene ninguna tabla que la
referencie por llave foránea — ni siquiera `pedidos`, que guarda la dirección como texto/coordenadas
propias en el momento del pedido, no como un `id_direccion`. Por eso eliminar una dirección aquí es
un borrado físico simple, sin ningún historial que proteger.

### Por qué no hay selector de cliente en el formulario

A diferencia de `conductor_vehiculo` (que sí necesitaba elegir entre varios conductores/vehículos
disponibles), una dirección solo tiene sentido dentro de la ficha de un cliente ya abierto — el
`id_cliente` viaja fijo en la URL (`/clientes/{cliente}/direcciones/...`), no se captura en el
formulario.

### `estado` es texto libre, no un enum de ciclo de vida

La migración `create_direcciones_clientes_table.php` (ya aplicada) define `estado` como
`string()->nullable()` — es el estado/provincia de la dirección postal (ej. "CDMX"), sin relación con
el patrón `estado: Activo/Suspendido/Inactivo` que sí tienen `usuarios`, `despachadores`,
`conductores`, `vehiculos` y el propio `clientes`. No se le agrega ningún badge de color ni lógica de
activo/inactivo.

### Sin búsqueda ni paginación

Un cliente tiene típicamente 2-3 direcciones (el propio ejemplo de `db/02-base-de-datos.md`: "Casa",
"Trabajo", "Negocio"). El listado de `index` trae todas las direcciones de ese cliente sin paginar ni
filtrar, a diferencia de los listados a nivel tenant (`usuarios`, `clientes`, etc.).

## Reglas de negocio

- Una dirección siempre pertenece a un cliente (`id_cliente` obligatorio, `cascadeOnDelete` ya
  existente en la migración).
- `calle` es el único campo obligatorio de la dirección; todo lo demás es opcional.
- Latitud debe estar entre -90 y 90, longitud entre -180 y 180 si se capturan — es la definición
  matemática de una coordenada, no una regla de negocio nueva.
- Solo `AdminCliente` accede a estas rutas — mismo middleware de rol y mismo límite de peticiones
  (`tenant-usuarios`) que el resto del panel de tenant.

## Backend (Laravel)

- **Modelo nuevo** `App\Models\Tenant\DireccionCliente`: `$table = 'direcciones_clientes'`,
  `$primaryKey = 'id_direccion'`, relación `belongsTo(Cliente::class, 'id_cliente', 'id_cliente')`.
- **Resource nuevo** `App\Http\Resources\Tenant\DireccionClienteResource`: expone todas las columnas.
- **Controlador nuevo** `App\Http\Controllers\Tenant\DireccionClienteController`:
  - `index(Cliente $cliente)`: todas las direcciones de ese cliente, `orderBy('id_direccion')`.
  - `store(Request $request, Cliente $cliente)`: valida y crea, fijando `id_cliente` desde la ruta
    (no desde el body).
  - `show(Cliente $cliente, DireccionCliente $direccion)`: para precargar el formulario de edición.
  - `update(Request $request, Cliente $cliente, DireccionCliente $direccion)`: valida y actualiza.
  - `destroy(Cliente $cliente, DireccionCliente $direccion)`: borra físico.
  - Todas usan binding implícito (`Cliente $cliente`, `DireccionCliente $direccion`) — mismo criterio
    ya usado en `ClienteController`/`ConductorController`, seguro por el ajuste de prioridad de
    middleware descrito en `tenant/001`.
- **Rutas** (`routes/api.php`), mismo grupo protegido:

  ```php
  Route::get('/clientes/{cliente}/direcciones', [Tenant\DireccionClienteController::class, 'index']);
  Route::post('/clientes/{cliente}/direcciones', [Tenant\DireccionClienteController::class, 'store']);
  Route::get('/clientes/{cliente}/direcciones/{direccion}', [Tenant\DireccionClienteController::class, 'show']);
  Route::put('/clientes/{cliente}/direcciones/{direccion}', [Tenant\DireccionClienteController::class, 'update']);
  Route::delete('/clientes/{cliente}/direcciones/{direccion}', [Tenant\DireccionClienteController::class, 'destroy']);
  ```

- **Auditoría**: `ALTA`/`EDICION`/`BAJA` sobre `tabla_afectada = 'direcciones_clientes'`.

## Frontend (Vue 3)

- **Vistas nuevas** `views/tenant/clientes/direcciones/`: `ListaDireccionesClienteView.vue` (tabla:
  alias, calle + número, ciudad, botones "Editar"/"Eliminar", con el nombre del cliente como
  encabezado de contexto y botón "Nueva dirección"), `CrearDireccionClienteView.vue`,
  `EditarDireccionClienteView.vue`.
- **`ListaClientesView.vue`**: cada fila gana un enlace "Direcciones" que lleva a la lista anidada de
  ese cliente.
- **Rutas** (`router/index.ts`): `/t/:slug/panel/clientes/:id/direcciones` (+`/crear`,
  `/:direccionId/editar`), con `meta: { requiresTenantAuth: true }`. Sin ítem nuevo en el menú lateral
  — se llega solo desde la ficha de un cliente.
- **Eliminar**: `UiConfirmDialog` sin `require-password` (a diferencia del borrado de usuarios) —
  una dirección no es una cuenta con acceso al sistema.

## Fuera de alcance

- Pantalla "todas las direcciones" a nivel tenant.
- Mapa o selector visual de coordenadas.
- Cualquier vínculo con `pedidos`.
- Pantalla de detalle de cliente de solo lectura.

## Criterios de aceptación

1. `GET /api/v1/t/{slug}/clientes/{cliente}/direcciones` sin sesión responde `401`; con sesión de
   `Despachador`/`Conductor` responde `403`.
2. `POST /api/v1/t/{slug}/clientes/{cliente}/direcciones` sin `calle` responde `422`.
3. `POST /api/v1/t/{slug}/clientes/{cliente}/direcciones` con datos válidos crea la dirección con el
   `id_cliente` de la URL, sin importar qué `id_cliente` traiga el body (si acaso).
4. `POST` con `latitud`/`longitud` fuera de rango (ej. `latitud: 200`) responde `422`.
5. `PUT /api/v1/t/{slug}/clientes/{cliente}/direcciones/{id}` edita la dirección y lo refleja en la
   respuesta.
6. `DELETE /api/v1/t/{slug}/clientes/{cliente}/direcciones/{id}` la borra físicamente.
7. El frontend expone `/t/:slug/panel/clientes/:id/direcciones` con la tabla, "Nueva dirección",
   "Editar" y "Eliminar" (con confirmación simple, sin contraseña); el listado de clientes tiene un
   enlace "Direcciones" por fila.
8. Pint y ESLint/Prettier corren sin errores; `php artisan test` pasa.

## Supuestos asumidos (registro completo)

1. Borrado físico de direcciones — ninguna otra tabla las referencia por llave foránea (a diferencia
   de `vehiculos`, protegido por el historial de `conductor_vehiculo`).
2. Sin selector de cliente en el formulario — el `id_cliente` viene fijo de la URL.
3. `estado` es texto libre (provincia/estado postal), no un enum de ciclo de vida — no se le agrega
   badge ni lógica de activo/inactivo.
4. Todos los campos opcionales salvo `calle`, igual que la migración.
5. Latitud (-90 a 90) y longitud (-180 a 180) se validan por rango matemático, no por una regla de
   negocio nueva; sin mapa ni selector visual.
6. Sin búsqueda ni paginación en el listado de direcciones de un cliente — son pocas filas en la
   práctica.
7. Sin pantalla "todas las direcciones" a nivel tenant, ni pantalla de detalle de cliente — se navega
   directo desde el listado de clientes a la lista de direcciones de ese cliente.
8. Eliminar una dirección pide confirmación simple, sin contraseña — no es una cuenta con acceso al
   sistema.
