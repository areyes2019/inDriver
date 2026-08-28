# Spec: CRUD de pedidos

## Historia de usuario

Como AdminCliente o Despachador, quiero registrar un pedido con los datos del solicitante, las
direcciones de recogida y entrega, el horario y la forma de pago, poder asignarle despachador,
conductor y vehículo, y moverlo a través de su ciclo de vida (publicado, tomado, en camino,
entregado o cancelado), para operar el flujo central del negocio de delivery.

## Objetivo / Alcance

Cubre la tabla 08 (`pedidos`) de `db/02-base-de-datos.md`, la tabla central del sistema operativo.
Depende de `clientes` (06), `despachadores` (02), `conductores` (03) y `vehiculos` (04), ya
implementadas.

Deja funcionando:

- Listar pedidos con búsqueda y paginación.
- Crear un pedido nuevo (con `numero_pedido` autogenerado y `estado` inicial `PENDIENTE`).
- Editar los datos de un pedido mientras no esté en un estado final.
- Cambiar el estado de un pedido siguiendo transiciones válidas (`PATCH .../estado`).

**No** incluye:

- Las tablas 09 (`pedido_asignaciones`) y 10 (`pedido_estados`) — historial de intentos de
  asignación y bitácora de cambios de estado. El campo `pedidos.estado` se actualiza directo, sin
  historial todavía.
- El motor de asignación automática (radio de búsqueda, expiración, ofrecer al siguiente
  conductor) descrito en las reglas de negocio de la tabla 09. Asignar despachador/conductor/
  vehículo a un pedido es manual, vía select.
- Borrado físico de pedidos — un pedido se cancela, no se elimina.

## Decisión técnica

### Por qué `Despachador` sí tiene acceso (a diferencia del resto de CRUDs)

Las reglas de negocio de la base de datos del tenant indican que "un despachador ve y puede operar
sobre todos los pedidos y conductores del tenant por igual". Por eso las rutas de `pedidos` usan
`rol.tenant:AdminCliente,Despachador` en vez de solo `AdminCliente`. `Conductor` no tiene acceso a
este panel de escritorio (su interacción es vía app móvil, fuera de alcance).

### `numero_pedido` autogenerado

Se genera en el backend al crear (`PED-000001`, correlativo basado en `max(id_pedido) + 1`), no lo
captura el despachador. Es un identificador simple para uso operativo, no una regla de negocio.

### Máquina de estados en el controlador, sin tabla nueva

Las transiciones válidas viven como una constante (`PedidoController::TRANSICIONES`) que mapea cada
estado a la lista de estados a los que puede saltar:

```
PENDIENTE           → PUBLICADO, CANCELADO
PUBLICADO           → TOMADO, RECHAZADO, CANCELADO
TOMADO              → ARRIBADO, CANCELADO
ARRIBADO            → EN_CAMINO, CANCELADO
EN_CAMINO           → ARRIBADO_A_ENTREGA, CANCELADO
ARRIBADO_A_ENTREGA  → ENTREGADO, CANCELADO
ENTREGADO / RECHAZADO / CANCELADO → (estados finales, sin salida)
```

Al llegar a `PUBLICADO`, `TOMADO`, `ENTREGADO` o `CANCELADO` se sella automáticamente
`fecha_publicacion`, `fecha_asignacion`, `fecha_entrega` o `fecha_cancelacion` respectivamente. No
se crea una tabla `pedido_estados` para esto todavía (ver Fuera de alcance).

### Edición bloqueada en estados finales

`update()` rechaza con `422` si el pedido ya está en `ENTREGADO`, `CANCELADO` o `RECHAZADO` — un
pedido cerrado no se vuelve a tocar. El único camino para modificar su estado desde ahí sería una
funcionalidad nueva, no contemplada aquí.

### Horario: "lo antes posible" contra horario fijo

Si `lo_antes_posible = true`, `hora_desde`/`hora_hasta` pueden ir vacíos. Si es `false`, ambos son
obligatorios y `hora_hasta` debe ser posterior a `hora_desde`. Se valida a mano en el controlador
(no con `required_if`, para evitar el problema de comparar booleanos JSON contra el string `'false'`
que usa esa regla de Laravel).

## Reglas de negocio

- `id_cliente` es opcional: pedido de cliente frecuente (con `id_cliente`) o solicitante ocasional
  (`id_cliente = null`), según el caso de uso del spec de base de datos.
- `modalidad_pago` fija los tres casos de pago documentados
  (`RECEPTOR_PAGA_ENVIO`, `REMITENTE_PAGA_ENVIO`, `RECEPTOR_PAGA_ENVIO_PRODUCTOS`).
- `importe_envio`/`importe_cobro` son numéricos ≥ 0, con default `0` si no llegan.
- Latitud/longitud de recogida y entrega son opcionales, validadas por rango matemático
  (-90/90, -180/180) si se capturan.
- `id_despachador`/`id_conductor`/`id_vehiculo` son opcionales en todo momento — no hay validación
  de disponibilidad ni de conflicto de horario entre pedidos (eso pertenece al motor de asignación,
  fuera de alcance).
- Solo `AdminCliente` y `Despachador` acceden a estas rutas, mismo límite de peticiones
  (`tenant-usuarios`) que el resto del panel de tenant.

## Backend (Laravel)

- **Modelo nuevo** `App\Models\Tenant\Pedido`: `$table = 'pedidos'`, `$primaryKey = 'id_pedido'`,
  `casts()` para fechas/horas/booleano/decimales, relaciones `belongsTo` a `Cliente`,
  `Despachador`, `Conductor`, `Vehiculo`.
- **Resource nuevo** `App\Http\Resources\Tenant\PedidoResource`: expone las columnas más nombres
  derivados de las relaciones (`cliente_nombre`, `despachador_nombre`, `conductor_nombre`,
  `vehiculo_placa`).
- **Controlador nuevo** `App\Http\Controllers\Tenant\PedidoController`:
  - `recursos()`: catálogos simples (clientes, despachadores activos, conductores activos,
    vehículos activos) para poblar los selects del formulario.
  - `index(Request $request)`: búsqueda por número/solicitante/teléfono, filtro opcional por
    `estado`, paginado de 15.
  - `store(Request $request)`: valida, autogenera `numero_pedido`, fuerza `estado = PENDIENTE`.
  - `show(Pedido $pedido)`: para precargar el formulario de edición.
  - `update(Request $request, Pedido $pedido)`: rechaza si el pedido está en un estado final;
    si no, valida y actualiza (sin tocar `estado`).
  - `cambiarEstado(Request $request, Pedido $pedido)`: valida la transición contra el mapa de
    estados y sella la fecha correspondiente.
  - Todas las mutaciones registran `Auditoria` (`ALTA`/`EDICION`/`CAMBIO_ESTADO`) sobre
    `tabla_afectada = 'pedidos'`.
- **Rutas** (`routes/api.php`), en un grupo nuevo con `rol.tenant:AdminCliente,Despachador`:

  ```php
  Route::get('/pedidos/recursos', [PedidoController::class, 'recursos']);
  Route::get('/pedidos', [PedidoController::class, 'index']);
  Route::post('/pedidos', [PedidoController::class, 'store']);
  Route::get('/pedidos/{pedido}', [PedidoController::class, 'show']);
  Route::put('/pedidos/{pedido}', [PedidoController::class, 'update']);
  Route::patch('/pedidos/{pedido}/estado', [PedidoController::class, 'cambiarEstado']);
  ```

## Frontend (Vue 3)

- **Vistas nuevas** `views/tenant/pedidos/`: `ListaPedidosView.vue` (tabla con número, solicitante,
  cliente, fecha de servicio, badge de estado por color, y botones de acción rápida — "Publicar",
  "Marcar tomado", etc., según el siguiente estado válido, más "Cancelar" si el pedido no está en
  un estado final), `CrearPedidoView.vue` y `EditarPedidoView.vue` (mismo formulario, con selects
  de cliente/despachador/conductor/vehículo poblados desde `/pedidos/recursos`). La tabla de
  `ListaPedidosView.vue` ocupa el 100% del ancho interior disponible del card, sin `max-width` ni
  centrado propio, igual que la corrección ya aplicada en `ListaTenantsView.vue` (spec 008).
- **Rutas** (`router/index.ts`): `/t/:slug/panel/pedidos` (+`/crear`, `/:id/editar`), con
  `meta: { requiresTenantAuth: true }`.
- **Menú lateral** (`TenantLayout.vue`): nuevo ítem "Pedidos", primero en la lista.
- **Cambio de estado**: se hace con `UiConfirmDialog`, mostrando a qué estado pasará el pedido antes
  de confirmar.

## Fuera de alcance

- Tablas `pedido_asignaciones` y `pedido_estados` (bitácora e historial de intentos de asignación).
- Motor de asignación automática (radio de búsqueda, expiración, oferta al siguiente conductor).
- Validación de disponibilidad/conflicto de horario al asignar conductor o vehículo.
- Interacción del conductor con el pedido (app móvil).

## Criterios de aceptación

1. `GET /api/v1/t/{slug}/pedidos` sin sesión responde `401`; con sesión de `Conductor` responde
   `403`; con `Despachador` responde `200`.
2. `POST /api/v1/t/{slug}/pedidos` sin campos requeridos responde `422`.
3. `POST` con `lo_antes_posible: false` y sin `hora_desde`/`hora_hasta` responde `422`.
4. `POST` con datos válidos crea el pedido con `numero_pedido` autogenerado (`PED-######`) y
   `estado: PENDIENTE`, y registra `Auditoria` con `accion = ALTA`.
5. `PUT /api/v1/t/{slug}/pedidos/{id}` edita el pedido y registra `Auditoria` con
   `accion = EDICION`; si el pedido ya está en un estado final, responde `422`.
6. `PATCH /api/v1/t/{slug}/pedidos/{id}/estado` con una transición válida cambia el estado, sella
   la fecha correspondiente y registra `Auditoria` con `accion = CAMBIO_ESTADO`; con una transición
   inválida responde `422`.
7. El frontend expone `/t/:slug/panel/pedidos` con listado, búsqueda, alta, edición y botones de
   cambio de estado con confirmación; el menú lateral incluye "Pedidos".
8. En `/t/:slug/panel/pedidos`, la tabla ocupa el 100% del ancho interior del card (sin franjas
   vacías a los lados) en pantallas anchas, y sigue siendo legible con scroll horizontal propio en
   pantallas angostas.
9. Pint y ESLint/Prettier corren sin errores; `php artisan test` pasa.

## Supuestos asumidos (registro completo)

1. Alcance limitado a la tabla 08 — tablas 09/10 y el motor de asignación automática quedan para un
   spec futuro.
2. Asignación de despachador/conductor/vehículo manual, vía select, sin lógica de disponibilidad.
3. Migración reutilizada tal cual, sin cambios.
4. `numero_pedido` autogenerado por el backend, formato `PED-######`.
5. Acceso para `AdminCliente` y `Despachador` (no solo `AdminCliente`, a diferencia de los demás
   CRUDs), sin acceso para `Conductor`.
6. Endpoints a nivel tenant (no anidados bajo cliente), sin `DELETE` — un pedido se cancela, no se
   borra.
7. Transiciones de estado validadas contra un mapa fijo en el controlador; fechas de ciclo de vida
   selladas automáticamente según el estado destino.
8. Edición bloqueada cuando el pedido está en un estado final (`ENTREGADO`, `CANCELADO`,
   `RECHAZADO`).
9. Horario obligatorio (`hora_desde` < `hora_hasta`) solo cuando `lo_antes_posible = false`.
10. Listado con badge de color por estado y botones de acción rápida (siguiente estado + cancelar),
    sin un select libre de estado en la interfaz.
11. La tabla de `ListaPedidosView.vue` ocupa el 100% del ancho interior del card, sin `max-width`
    ni centrado propio; solo conserva un ancho mínimo para pantallas angostas, resuelto con scroll
    horizontal en su propio contenedor — misma corrección aplicada a `ListaTenantsView.vue` (spec
    008).
