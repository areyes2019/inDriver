# Spec: Vehículo propio del conductor (relación 1 a 1)

> Reemplaza por completo a la spec anterior de este mismo número
> (`tenant/004-crud-vehiculos-y-asignaciones.md`), que documentaba un CRUD de "vehículos de la
> flotilla" más una tabla de historial de asignaciones conductor↔vehículo. Ese modelo no aplica al
> negocio: el tenant nunca es dueño de vehículos.

## Historia de usuario

Como AdminCliente, no asigno vehículos a mis conductores porque no tengo vehículos propios: cada
conductor ya llega con su propio vehículo, en una relación que siempre es de uno a uno (nunca de uno
a varios). Cuando un conductor cambia de vehículo, quiero poder actualizar los datos de ese vehículo
desde la ficha del conductor, para que el sistema siempre refleje qué vehículo trae cada quien, sin
tener que administrar una flotilla ni un historial de asignaciones.

## Objetivo / Alcance

Corrige el modelo de datos de la tabla 04 (`vehiculos`) de `db/02-base-de-datos.md` y elimina la
tabla 05 (`conductor_vehiculo`). Modifica lo establecido en `tenant/003-crud-conductores.md`
(`ConductorController`, `ConductorResource`, `Conductor` model, `Crear`/`EditarConductorView.vue`)
para que el alta y edición de un conductor capturen también los datos de su vehículo, en el mismo
formulario — mismo criterio de "modifica lo ya establecido sin reescribir la spec original" que ya
usó `tenant/011-gestion-despachadores-tenant.md` sobre `conductores`.

Deja funcionando:

- Migración que liga `vehiculos` a `conductores` 1 a 1 (`vehiculos.id_conductor`, única) y elimina
  `conductor_vehiculo`.
- Alta de conductor: un solo formulario que crea, en la misma transacción, la fila de `conductores` y
  la de su vehículo.
- Edición de conductor: el mismo formulario permite editar también los datos del vehículo (esto es lo
  que representa "el conductor cambió de vehículo").

**No** incluye:

- Historial de vehículos anteriores de un conductor — al editar, los datos viejos se sobreescriben.
- Un CRUD de "Vehículos" independiente ni una pantalla de "Asignaciones" — ambos se eliminan del
  panel.
- Ajustar cómo `pedidos.id_vehiculo` se completa al aceptar un pedido, más allá de leer la relación
  directa `conductor→vehiculo` en vez de `conductor_vehiculo` (ese ajuste puntual, sobre código ya
  construido en la integración con `panda_express`, se documenta en
  `tenant/013-conexion-panda-express.md`).

## Decisión técnica

### Por qué `id_conductor` vive en `vehiculos` y no al revés

`vehiculos.id_conductor` (única) expresa la relación 1 a 1 sin duplicar el vehículo si algún día se
necesitara consultarlo desde `pedidos.id_vehiculo` (que ya existe y no se toca). Poner `id_vehiculo`
en `conductores` obligaría a lo mismo pero invertido, sin ninguna ventaja: se eligió el lado que ya
tenía la tabla dedicada.

### Por qué el vehículo se captura en el mismo formulario del conductor, no en uno propio

Un vehículo sin conductor no representa nada de negocio (nadie "tiene un vehículo en inventario" para
asignarlo después) — por eso no tiene sentido un alta independiente, igual razón por la que
`despachadores` (`tenant/002`) no tiene pantalla propia de alta separada de su usuario. Aquí el
vehículo nace y muere con su conductor.

### Qué pasa con los datos existentes

El sistema ya tiene, en producción, las tablas `vehiculos` y `conductor_vehiculo` con datos reales
(migraciones `create_vehiculos_table.php` y `create_conductor_vehiculo_table.php`, ya aplicadas). La
migración de esta spec:

1. Agrega `vehiculos.id_conductor` (nullable a nivel de base de datos, única, `foreignId` hacia
   `conductores` con `cascadeOnDelete()`).
2. Copia a esa columna el `id_vehiculo` de la fila `activo = true` de `conductor_vehiculo` de cada
   conductor (si algún conductor no tiene ninguna fila activa, queda sin vehículo hasta que alguien
   se lo capture a mano en su edición).
3. Borra las filas de `vehiculos` que quedaron sin `id_conductor` (vehículos históricos ya
   reemplazados) — no se conservan, según lo acordado en "no se guarda historial".
4. `Schema::dropIfExists('conductor_vehiculo')`.

A nivel de aplicación (no de base de datos, mismo criterio ya usado en `conductores.id_usuario` y en
`despachadores.id_usuario`), `ConductorController@store` exige siempre los datos del vehículo: un
conductor nuevo nunca se crea sin uno.

## Reglas de negocio

- Relación `vehiculos`↔`conductores` siempre 1 a 1: un conductor tiene como máximo un vehículo: un
  vehículo pertenece como máximo a un conductor (`vehiculos.id_conductor`, única).
- Un conductor siempre tiene un vehículo desde su alta — el formulario de "Nuevo conductor" exige
  también los datos del vehículo.
- "Cambiar de vehículo" es editar los campos de la fila de `vehiculos` del conductor (`placa`,
  `marca`, `modelo`, `anio`, `color`, `tipo`, `numero_economico`, `estado`) — nunca se crea una fila
  nueva ni se conserva la anterior.
- `placa` sigue siendo única entre vehículos (ya lo era en la spec anterior).
- Borrar el conductor borra en cascada su vehículo (`cascadeOnDelete`, mismo mecanismo que ya usa
  `conductores.id_usuario` hacia `usuarios`).
- Solo `AdminCliente` accede a estos datos, dentro del mismo flujo de alta/edición de conductores
  (mismo middleware de rol y límite de peticiones `tenant-usuarios` que `tenant/003`).

## Backend (Laravel)

- **Migración nueva** `add_id_conductor_to_vehiculos_table.php` + `drop_conductor_vehiculo_table.php`
  (o ambas en una sola migración): ver pasos 1-4 de "Qué pasa con los datos existentes".
- **Elimina**: `App\Models\Tenant\ConductorVehiculo`,
  `App\Http\Controllers\Tenant\ConductorVehiculoController`,
  `App\Http\Resources\Tenant\ConductorVehiculoResource`, y las rutas `/conductor-vehiculo*`.
- **Elimina** el CRUD independiente de vehículos: `App\Http\Controllers\Tenant\VehiculoController`,
  `App\Http\Resources\Tenant\VehiculoResource`, y las rutas `/vehiculos*`.
- **Modifica** `App\Models\Tenant\Vehiculo`: quita cualquier relación hacia `ConductorVehiculo`,
  agrega `belongsTo(Conductor::class, 'id_conductor', 'id_conductor')`.
- **Modifica** `App\Models\Tenant\Conductor`: agrega `hasOne(Vehiculo::class, 'id_conductor',
  'id_conductor')`.
- **Modifica** `App\Http\Controllers\Tenant\ConductorController`:
  - `store`: valida, junto a los campos de licencia ya existentes, los del vehículo (`placa` única,
    `marca`, `modelo`, `anio`, `color`, `tipo`, `numero_economico` — todos requeridos); en una
    transacción, crea la fila de `conductores` y luego la de `vehiculos` con su `id_conductor`.
  - `update`: valida y actualiza también los campos del vehículo del conductor
    (`$conductor->vehiculo()->update(...)`), incluido su `estado`, en la misma transacción que el
    resto de los campos.
  - `index`/`show`: agrega `with('vehiculo')`.
- **Modifica** `App\Http\Resources\Tenant\ConductorResource`: agrega los campos del vehículo
  relacionado (`placa`, `marca`, `modelo`, `anio`, `color`, `tipo`, `numero_economico`,
  `estado_vehiculo`) vía `whenLoaded('vehiculo')`.
- **Auditoría**: el cambio de datos del vehículo queda dentro del mismo registro `ALTA`/`EDICION` de
  `conductores` — no hay un registro de auditoría separado para `vehiculos`.

## Frontend (Vue 3)

- **Elimina** `views/tenant/vehiculos/` completo (`ListaVehiculosView.vue`, `CrearVehiculoView.vue`,
  `EditarVehiculoView.vue`) y `views/tenant/asignaciones/` completo (`ListaAsignacionesView.vue`,
  `AsignarVehiculoView.vue`).
- **Elimina** del router las rutas `/panel/vehiculos*` y `/panel/asignaciones*`.
- **Elimina** de `layouts/TenantLayout.vue` las entradas de menú "Vehículos" y "Asignaciones".
- **Modifica** `views/tenant/conductores/CrearConductorView.vue` y `EditarConductorView.vue`: agrega
  una sección "Datos del vehículo" con los campos `placa`, `marca`, `modelo`, `anio`, `color`, `tipo`,
  `numero_economico` (y `estado` del vehículo, solo en edición) dentro del mismo formulario.
- **Modifica** `views/tenant/conductores/ListaConductoresView.vue`: agrega la columna `placa` a la
  tabla, para ver de un vistazo qué vehículo trae cada conductor sin abrir una pantalla aparte.

## Fuera de alcance

- Historial de vehículos anteriores de un conductor.
- CRUD independiente de "Vehículos" o pantalla de "Asignaciones" — ambos se eliminan.
- Restricción `NOT NULL` a nivel de base de datos sobre `vehiculos.id_conductor` — se garantiza en la
  aplicación (mismo criterio ya usado para otras relaciones 1 a 1 de este proyecto, como
  `conductores.id_usuario`).
- Cualquier ajuste a `pedidos.id_vehiculo` distinto de leer `conductor->vehiculo` en vez de
  `conductor_vehiculo` (documentado en `tenant/013`).

## Criterios de aceptación

1. Tras la migración, ya no existe la tabla `conductor_vehiculo`; `vehiculos.id_conductor` es única y
   conserva el vehículo que cada conductor tenía activo antes del cambio.
2. `POST /api/v1/t/{slug}/conductores` sin los datos del vehículo, o con una `placa` ya usada por
   otro vehículo, responde `422` sin crear nada.
3. `POST /api/v1/t/{slug}/conductores` con datos válidos crea, en una sola operación, la fila de
   `conductores` y la de `vehiculos` ligada a ese conductor.
4. `PUT /api/v1/t/{slug}/conductores/{id}` permite editar los campos del vehículo del conductor junto
   con el resto de sus datos, en el mismo request.
5. `GET /api/v1/t/{slug}/conductores` y `GET /api/v1/t/{slug}/conductores/{id}` incluyen los datos
   del vehículo de cada conductor en la respuesta.
6. Las rutas `/api/v1/t/{slug}/vehiculos*` y `/api/v1/t/{slug}/conductor-vehiculo*` ya no existen
   (`404`).
7. El frontend ya no expone `/t/:slug/panel/vehiculos` ni `/t/:slug/panel/asignaciones`, ni sus
   entradas de menú.
8. `/t/:slug/panel/conductores/crear` y `/t/:slug/panel/conductores/:id/editar` incluyen los campos
   del vehículo en el mismo formulario; la lista de conductores muestra la columna `placa`.
9. Pint y ESLint/Prettier corren sin errores; `php artisan test` pasa — `VehiculoTest.php` y
   `ConductorVehiculoTest.php` se eliminan y sus casos relevantes (placa única, alta con vehículo,
   edición de vehículo) se cubren dentro de las pruebas de `ConductorController`.

## Supuestos asumidos (registro completo)

1. `vehiculos` deja de representar "vehículos de la flotilla del tenant" y pasa a representar el
   vehículo actual de un conductor: relación siempre 1 a 1 (`vehiculos.id_conductor`, única).
2. Se elimina la tabla `conductor_vehiculo` — no se conserva historial de vehículos anteriores de un
   conductor; al cambiar de vehículo se sobreescriben los mismos campos.
3. El AdminCliente ya no da de alta vehículos por separado ni los "asigna": los datos del vehículo se
   capturan junto con los del conductor, en el mismo formulario de alta y edición.
4. Un conductor siempre tiene un vehículo — es obligatorio desde el alta, garantizado en la
   aplicación (`ConductorController@store`), no con una restricción `NOT NULL` en base de datos.
5. El `estado` del vehículo (ACTIVO/INACTIVO/MANTENIMIENTO) se conserva como campo informativo
   editable en el mismo formulario, pero ya no se usa para filtrar "vehículos disponibles para
   asignar", porque ya no existe ninguna asignación.
6. Se eliminan del panel las secciones "Vehículos" y "Asignaciones" (rutas, controladores, vistas,
   menú) descritas en la spec anterior de este número.
7. La migración de datos existentes toma la fila activa de `conductor_vehiculo` de cada conductor
   para poblar `vehiculos.id_conductor`, y borra las filas de `vehiculos` que queden sin conductor
   (vehículos históricos ya reemplazados) — no se migran a ningún otro lugar.
8. Esta spec reemplaza el contenido anterior del número 004 (se renombra el archivo de
   `004-crud-vehiculos-y-asignaciones.md` a `004-vehiculo-del-conductor.md`) en vez de crear un
   número nuevo, porque describe la versión correcta del mismo pendiente (tablas 04/05).
