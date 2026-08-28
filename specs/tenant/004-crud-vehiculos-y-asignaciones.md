# Spec: CRUD de vehículos y de asignaciones conductor-vehículo

## Historia de usuario

Como AdminCliente, quiero dar de alta y editar los vehículos de mi flotilla, y quiero asignar cada
vehículo a un conductor (y poder cambiar esa asignación o darla por terminada), para saber en todo
momento quién trae qué vehículo y conservar el historial de esas asignaciones.

## Objetivo / Alcance

Cubre las tablas 04 (`vehiculos`) y 05 (`conductor_vehiculo`) de `db/02-base-de-datos.md`. Se
documentan juntas porque la segunda no tiene sentido sin la primera y se construyen en el mismo
empuje — a diferencia de `despachadores`/`conductores` (`tenant/002`/`tenant/003`), que sí se
separaron porque cada una dependía de `usuarios` de forma distinta.

Deja funcionando:

- CRUD completo de `vehiculos`: listar (búsqueda + paginación), crear, editar (todos los campos,
  incluido `estado`).
- Historial de asignaciones `conductor_vehiculo`: asignar un vehículo a un conductor, listar el
  historial completo (búsqueda + paginación), y finalizar una asignación activa.

**No** incluye:

- Borrado físico de vehículos (ver "Decisión técnica").
- Editar una fila de `conductor_vehiculo` ya creada (ni activa ni cerrada) — es una tabla de
  historial, solo se crea y se cierra.
- Cualquier relación con `pedidos` (tabla 08) — los vehículos ahí solo se referencian por
  `id_vehiculo`, sin lógica nueva en esta historia.

## Decisión técnica

### Por qué `vehiculos` no tiene borrado físico

`conductor_vehiculo.id_vehiculo` tiene `cascadeOnDelete()` hacia `vehiculos` (migración
`create_conductor_vehiculo_table.php`, ya aplicada). Borrar físicamente un vehículo destruiría todo
su historial de asignaciones pasadas, que es justo lo que esta tabla existe para conservar (según su
propio comentario en `db/02-base-de-datos.md`: "esto permite conservar historial"). En su lugar,
retirar un vehículo de operación es cambiar su `estado` a `INACTIVO`, igual patrón que ya usa
`ClienteController@cambiarEstado`, pero aquí integrado al mismo `update` que edita el resto de los
campos (igual que `ConductorController@update`).

### `conductor_vehiculo` es historial, no un CRUD de filas editables

Una fila de asignación representa un hecho que ya ocurrió ("el conductor X trajo el vehículo Y desde
tal fecha"). Editar una fila cerrada reescribiría el historial; por eso solo hay dos operaciones:
crear (`store`, asignar) y cerrar (`finalizar`) — nunca `update` sobre una fila existente.

### Cierre automático de la fila activa anterior, por ambos lados

La regla de negocio ya documentada en `db/02-base-de-datos.md` dice que un conductor solo puede
tener una fila `activo = true` a la vez. Por simetría (no documentada ahí, pero consistente con el
propósito de la tabla — un vehículo físico no puede estar con dos conductores al mismo tiempo), esta
historia también cierra la fila activa anterior de un vehículo cuando se le asigna a otro conductor.
`ConductorVehiculoController@store` hace ambos cierres (por conductor y por vehículo) dentro de la
misma transacción que crea la fila nueva, para no dejar dos filas activas a medio camino si algo
falla.

### Selectores de "Asignar vehículo"

Solo muestran conductores con `estado = ACTIVO` (tabla `conductores`) y vehículos con
`estado = ACTIVO` (tabla `vehiculos`) — asignar un vehículo en mantenimiento o un conductor
bloqueado no tiene sentido operativo. No se filtra por si ya tienen una fila activa (asignar un
conductor/vehículo que ya la tiene es válido: cierra la anterior automáticamente, como describe el
punto anterior — es el mecanismo normal para "cambiar" a alguien de vehículo).

## Reglas de negocio

- Un conductor solo puede tener una fila `activo = true` en `conductor_vehiculo` a la vez (ya
  documentado en `db/02-base-de-datos.md`).
- Un vehículo solo puede tener una fila `activo = true` a la vez (supuesto nuevo de esta historia,
  por simetría — ver "Decisión técnica").
- Asignar un vehículo a un conductor que ya tenía uno, o un vehículo que ya estaba asignado a otro
  conductor, cierra automáticamente la fila activa anterior correspondiente (`fecha_fin` = fecha de
  inicio de la nueva asignación, `activo = false`).
- "Finalizar" una asignación activa no crea ninguna fila nueva — el vehículo queda sin conductor
  asignado hasta la siguiente alta.
- `placa` es única entre vehículos.
- Solo `AdminCliente` accede a estas rutas — mismo middleware de rol y mismo límite de peticiones
  (`tenant-usuarios`) que el resto del panel de tenant.

## Backend (Laravel)

- **Modelo nuevo** `App\Models\Tenant\Vehiculo`: `$table = 'vehiculos'`,
  `$primaryKey = 'id_vehiculo'`.
- **Resource nuevo** `App\Http\Resources\Tenant\VehiculoResource`: expone todas las columnas.
- **Controlador nuevo** `App\Http\Controllers\Tenant\VehiculoController`: `index` (búsqueda por
  placa/marca/modelo/número económico, paginado), `store` (valida `placa` única), `show`, `update`
  (todos los campos, incluido `estado`) — mismo patrón que `ConductorController`.
- **Modelo nuevo** `App\Models\Tenant\ConductorVehiculo`: `$table = 'conductor_vehiculo'`,
  relaciones `belongsTo` a `Conductor` y a `Vehiculo`.
- **Resource nuevo** `App\Http\Resources\Tenant\ConductorVehiculoResource`: expone la fila más el
  nombre del conductor y la placa/marca/modelo del vehículo relacionados.
- **Controlador nuevo** `App\Http\Controllers\Tenant\ConductorVehiculoController`:
  - `disponibles`: conductores `estado = ACTIVO` y vehículos `estado = ACTIVO`, para los selectores.
  - `index`: historial completo, `with(['conductor.usuario', 'vehiculo'])`, búsqueda por
    nombre/apellido del conductor o placa del vehículo, paginado, más reciente primero
    (`orderByDesc('fecha_inicio')`).
  - `store`: valida `id_conductor`, `id_vehiculo`, `fecha_inicio`; en una transacción, cierra la fila
    activa previa del conductor y la del vehículo (si existen), y crea la fila nueva
    (`activo = true`).
  - `finalizar(ConductorVehiculo $conductorVehiculo)`: valida que la fila esté `activo = true`
    (`422` si no), pone `fecha_fin = hoy`, `activo = false`.
- **Rutas** (`routes/api.php`), mismo grupo protegido:

  ```php
  Route::get('/vehiculos', [Tenant\VehiculoController::class, 'index']);
  Route::post('/vehiculos', [Tenant\VehiculoController::class, 'store']);
  Route::get('/vehiculos/{vehiculo}', [Tenant\VehiculoController::class, 'show']);
  Route::put('/vehiculos/{vehiculo}', [Tenant\VehiculoController::class, 'update']);

  Route::get('/conductor-vehiculo/disponibles', [Tenant\ConductorVehiculoController::class, 'disponibles']);
  Route::get('/conductor-vehiculo', [Tenant\ConductorVehiculoController::class, 'index']);
  Route::post('/conductor-vehiculo', [Tenant\ConductorVehiculoController::class, 'store']);
  Route::patch('/conductor-vehiculo/{conductorVehiculo}/finalizar', [Tenant\ConductorVehiculoController::class, 'finalizar']);
  ```

- **Auditoría**: `vehiculos` registra `ALTA`/`EDICION`; `conductor_vehiculo` registra `ASIGNACION`
  (en `store`) y `FINALIZACION` (en `finalizar`).

## Frontend (Vue 3)

- **Vistas nuevas** `views/tenant/vehiculos/`: `ListaVehiculosView.vue`, `CrearVehiculoView.vue`,
  `EditarVehiculoView.vue` — mismo patrón que `conductores`.
- **Vistas nuevas** `views/tenant/asignaciones/`: `ListaAsignacionesView.vue` (tabla: conductor,
  vehículo, fecha inicio, fecha fin, badge activo/finalizado, botón "Finalizar" solo en filas
  activas, botón "Asignar vehículo" arriba), `AsignarVehiculoView.vue` (selectores de conductor y
  vehículo + fecha de inicio).
- **Rutas** (`router/index.ts`): `/t/:slug/panel/vehiculos` (+`/crear`, `/:id/editar`),
  `/t/:slug/panel/asignaciones` (+`/asignar`), con `meta: { requiresTenantAuth: true }`.
- **`layouts/TenantLayout.vue`**: agrega "Vehículos" y "Asignaciones" al arreglo `items`.

## Fuera de alcance

- Borrado físico de vehículos.
- Editar una fila de `conductor_vehiculo` ya creada.
- Cualquier lógica de `pedidos` relacionada con el vehículo asignado.
- Restricciones `unique`/`check` nuevas a nivel de base de datos para "una fila activa a la vez" —
  se garantiza en la aplicación (mismo criterio ya usado en `despachadores`/`conductores`), porque
  las migraciones ya corrieron.

## Criterios de aceptación

1. `POST /api/v1/t/{slug}/vehiculos` con una `placa` ya usada responde `422`.
2. `PUT /api/v1/t/{slug}/vehiculos/{id}` cambia el `estado` a `INACTIVO` sin borrar el registro.
3. `GET /api/v1/t/{slug}/conductor-vehiculo/disponibles` solo devuelve conductores y vehículos en
   estado Activo.
4. `POST /api/v1/t/{slug}/conductor-vehiculo` crea una fila `activo = true`; si el conductor o el
   vehículo elegidos ya tenían una fila activa, esa fila queda `activo = false` con `fecha_fin`
   igual a la fecha de inicio de la nueva.
5. `PATCH /api/v1/t/{slug}/conductor-vehiculo/{id}/finalizar` sobre una fila ya finalizada responde
   `422`, sin cambiar nada.
6. `PATCH /api/v1/t/{slug}/conductor-vehiculo/{id}/finalizar` sobre una fila activa la cierra
   (`activo = false`, `fecha_fin = hoy`) sin crear ninguna fila nueva.
7. `GET /api/v1/t/{slug}/vehiculos` y `GET /api/v1/t/{slug}/conductor-vehiculo` sin sesión responden
   `401`; con sesión de `Despachador`/`Conductor` responden `403`.
8. El frontend expone `/t/:slug/panel/vehiculos` y `/t/:slug/panel/asignaciones`, con el menú del
   tenant incluyendo ambos enlaces.
9. Pint y ESLint/Prettier corren sin errores; `php artisan test` pasa.

## Supuestos asumidos (registro completo)

1. `vehiculos` no tiene borrado físico — retirar un vehículo es cambiar su `estado` a `INACTIVO`,
   para no destruir el historial de `conductor_vehiculo` vía `cascadeOnDelete`.
2. `conductor_vehiculo` no tiene "editar" — solo "asignar" (crear fila activa) y "finalizar" (cerrar
   fila activa). Es una tabla de historial, no de registros editables.
3. Un vehículo, igual que un conductor, solo puede tener una fila activa a la vez — supuesto nuevo,
   no estaba explícito en `db/02-base-de-datos.md`, agregado por simetría con la regla ya documentada
   para conductores.
4. Asignar un vehículo/conductor que ya tenía una fila activa cierra automáticamente esa fila
   anterior (no hace falta "finalizar" a mano antes de reasignar) — es el mecanismo normal para
   "cambiar" a alguien de vehículo.
5. Los selectores de "Asignar vehículo" solo muestran conductores y vehículos en estado Activo, sin
   filtrar por si ya tienen una asignación activa (justamente para permitir reasignar).
6. `fecha_inicio` se captura en el formulario (default hoy, editable), sin validar rangos.
7. Una spec nueva única (`004`) cubre ambas tablas, en vez de dos specs separadas, porque
   `conductor_vehiculo` no tiene ninguna función sin `vehiculos` y se construyen juntas.
8. Sin restricciones `unique`/`check` nuevas en base de datos para "una fila activa a la vez" — se
   garantiza en la aplicación, porque las migraciones de ambas tablas ya corrieron.
