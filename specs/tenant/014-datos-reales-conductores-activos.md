# Spec: Conductores activos (columna derecha del Panel de Despachador) — datos reales

## Historia de usuario

Como dueño de tenant en mi papel de Despachador, o como Despachador propiamente, quiero ver una
lista de conductores activos en el lado derecho del Panel, con el mismo estilo que "Viajes en
turno", para tener a la vista rápidamente qué conductores están conectados y con qué disponibilidad,
sin salir del Panel.

## Objetivo / Alcance

`tenant/013-conexion-panda-express.md` dejó a la app del conductor escribiendo su estado real
(`conductor_estado.estado`, `ONLINE`/`OFFLINE`) al conectarse/desconectarse, pero ningún endpoint
existente permite al panel de despachador leer esa información: `GET /conductores`
(`ConductorController::index`) lista el CRUD de conductores, no su estado en vivo, y está reservado
a `AdminCliente` (`routes/api.php:84-92`), no a `Despachador`. Esta spec cierra ese vacío: agrega el
endpoint que falta y el componente visual que lo consume.

Deja funcionando:

- Endpoint nuevo `GET /t/{slug}/conductores/activos`, accesible para `AdminCliente` y `Despachador`
  (mismos roles que ya pueden ver `/panel` y "Viajes en turno").
- Componente nuevo `ConductoresActivos.vue`: panel fijo (`position: fixed`) pegado al borde
  **derecho** de la ventana, con el mismo header, medidas verticales, estados de carga/error/vacío y
  estilo de ítem (borde, nombre en negrita, badge, texto secundario) que ya usa
  `ServiciosEnTurno.vue` — una réplica visual del panel izquierdo, en vez de la `UiCard` +
  `UiPersonListItem` que planteaba la spec 010 original.
- Cada ítem muestra: nombre del conductor, un badge con su disponibilidad
  (`DISPONIBLE`/`OCUPADO`/`DESCANSO`/`FUERA_DE_SERVICIO`) y la placa de su vehículo.
- `MapaConductores.vue` gana un margen derecho (`mr-[30%]`), simétrico al margen izquierdo que ya
  tiene, para no quedar tapado por el panel nuevo.

**No** incluye (por ahora):

- Actualización en tiempo real (websockets/polling) — la lista se carga al entrar a `/panel`, igual
  que "Viajes en turno" antes de tener su propio disparador de recarga (que aquí tampoco aplica: no
  hay un evento equivalente a "agendar un envío" para conductores).
- Click, filtros, búsqueda o acciones sobre los ítems (cambiar la disponibilidad de un conductor
  desde este panel, ver su pedido asignado, etc.).
- Resaltar en el mapa al conductor seleccionado en la lista, ni viceversa.
- Paginación o scroll infinito (se asume una operación con pocos conductores conectados a la vez;
  igual que "Viajes en turno", la lista se scrollea completa dentro del panel).
- Cualquier cambio a la app `panda_express` o a cómo escribe `conductor_estado` (ya resuelto por
  spec 013).

## Decisión técnica

### "Activo" = conectado a la app (`ONLINE`), sin filtrar además por disponibilidad

`conductor_estado.estado` (`ONLINE`/`OFFLINE`) y `conductores.disponibilidad`
(`DISPONIBLE`/`OCUPADO`/`DESCANSO`/`FUERA_DE_SERVICIO`) son dos columnas distintas en dos tablas
distintas, pero la primera gobierna a la segunda: el conductor decide su disponibilidad al
conectarse/desconectarse desde `panda_express`, y `EstadoController@actualizar` sincroniza
`conductores.disponibilidad` en el mismo momento (`ONLINE → DISPONIBLE`, `OFFLINE →
FUERA_DE_SERVICIO`, spec `tenant/013-conexion-panda-express.md`). El AdminCliente/Despachador no
edita ese campo — su único control sobre un conductor es su `estado` (`ACTIVO`/`INACTIVO`/
`BLOQUEADO`) desde el CRUD de conductores (`tenant/003-crud-conductores.md`), es decir, darlo de
baja del equipo si ya no labora ahí. Se define "activo" como "conectado ahora mismo" (`ONLINE`), sin
excluir por disponibilidad — en la práctica, como esta app solo produce `DISPONIBLE`/
`FUERA_DE_SERVICIO` y aquí solo aparecen conductores `ONLINE`, hoy todo ítem de esta lista mostrará
`DISPONIBLE`; el campo se conserva como informativo (y quedaría listo para distinguir `OCUPADO`/
`DESCANSO` el día que la app permita elegirlos).

### Por qué hace falta un endpoint nuevo (y no alcanza con `GET /conductores`)

`ConductorController::index` no incluye el estado en vivo (no carga la relación con
`conductor_estado`) y, sobre todo, vive dentro del grupo de rutas restringido a
`rol.tenant:AdminCliente` (`routes/api.php:73-110`) — un `Despachador` recibiría `403` si lo
llamara. A diferencia de "Viajes en turno" (spec 012), que fue 100% frontend porque `GET /pedidos`
ya estaba disponible para ambos roles, aquí no existe ningún endpoint que un `Despachador` pueda
usar para esto. Se agrega `GET /t/{slug}/conductores/activos` dentro del grupo
`rol.tenant:AdminCliente,Despachador` (el mismo que ya usa `/pedidos`), no dentro del grupo exclusivo
de `AdminCliente` donde vive el resto de `ConductorController`.

### Relación nueva `Conductor::estadoActual()` y filtro por `whereHas`

`Conductor` no tiene hoy ninguna relación hacia `ConductorEstado` (`ConductorEstado::conductor()` sí
existe, pero no la relación inversa). Se agrega `estadoActual(): HasOne` (`hasOne(ConductorEstado::class,
'id_conductor', 'id_conductor')`). El método nuevo del controlador filtra con
`whereHas('estadoActual', fn ($q) => $q->where('estado', 'ONLINE'))` y carga `with(['usuario',
'vehiculo', 'estadoActual'])` — así un conductor que nunca se ha conectado (sin fila en
`conductor_estado`) queda excluido automáticamente, sin necesitar un `LEFT JOIN` ni un caso especial
para "sin estado".

### Resource nuevo y liviano, no se reutiliza `ConductorResource`

`ConductorResource` expone campos que este panel no necesita (licencia, email, fecha de
vencimiento, saldo de viajes, etc.). Se agrega `ConductorActivoResource`, con solo los 4 campos que
usa el componente (`id_conductor`, `nombre` completo, `disponibilidad`, `placa`), para no acoplar
este panel a cambios futuros en `ConductorResource` ni mandar datos de más.

### Estilo visual: réplica de `ServiciosEnTurno.vue`, no `UiPersonListItem`

`UiPersonListItem` (avatar circular con iniciales + nombre + badge en línea) existe en
`components/ui/` pero hoy solo se usa en la guía de estilos (`StyleGuideView.vue`), nunca en una
pantalla real. Como el pedido explícito es "mismo estilo que Viajes en turno" (tarjetas con borde,
sin avatar), `ConductoresActivos.vue` no usa `UiPersonListItem`: replica el marcado de `<li>` con
borde que ya tiene `ServiciosEnTurno.vue`
(nombre en negrita + badge en la fila superior, texto secundario debajo).

### Colores del badge de disponibilidad

Se reutiliza el mismo patrón de `estadoColor` que ya tiene `ServiciosEnTurno.vue` (un `Record` que
traduce cada valor de estado a un color de `UiBadge`): `DISPONIBLE: blue`, `OCUPADO: orange`,
`DESCANSO: gray`, `FUERA_DE_SERVICIO: gray`.

### Ajuste de márgenes de `MapaConductores.vue`

Hoy el contenedor del mapa en `PanelView.vue` tiene `class="ml-[30%] ..."` para no quedar bajo el
panel izquierdo, sin margen derecho porque no había nada a la derecha. Se agrega `mr-[30%]` a esa
misma clase para que el mapa quede centrado entre los dos paneles fijos, en vez de que su franja
derecha quede tapada por el panel nuevo.

## Reglas de negocio

1. "Conductor activo" = tiene una fila en `conductor_estado` con `estado = 'ONLINE'`. Sin fila (nunca
   se conectó) u `OFFLINE` → no aparece.
2. No se filtra además por `disponibilidad`: un conductor `ONLINE` en cualquier valor de
   `disponibilidad` (`DISPONIBLE`, `OCUPADO`, `DESCANSO`, `FUERA_DE_SERVICIO`) aparece en la lista.
3. Se listan los conductores activos de todo el tenant, sin filtrar por el despachador que consulta
   (mismo criterio que "Viajes en turno").
4. Orden: por nombre del conductor, ascendente.
5. Cada ítem muestra nombre completo, badge de disponibilidad (color según la tabla de la decisión
   técnica) y placa del vehículo asignado.
6. Si no hay conductores activos, se muestra "No hay conductores activos".
7. Si la petición falla, se muestra un mensaje de error con botón "Reintentar".
8. Mientras se carga, se muestra un indicador de carga.
9. La lista se carga al entrar a `/panel` y no se refresca sola; para verla actualizada hay que
   volver a entrar a la página (sin recarga automática ni en tiempo real).
10. Acceso: mismos roles que ya ven `/panel`, `AdminCliente` y `Despachador`.

## Backend (Laravel)

- **`app/Models/Tenant/Conductor.php`**: nueva relación `estadoActual(): HasOne` →
  `hasOne(ConductorEstado::class, 'id_conductor', 'id_conductor')`.
- **`app/Http/Controllers/Tenant/ConductorController.php`**: nuevo método `activos()`:
  ```php
  public function activos(): AnonymousResourceCollection
  {
      $conductores = Conductor::query()
          ->with(['usuario', 'vehiculo', 'estadoActual'])
          ->whereHas('estadoActual', fn ($q) => $q->where('estado', 'ONLINE'))
          ->join('usuarios', 'usuarios.id_usuario', '=', 'conductores.id_usuario')
          ->orderBy('usuarios.nombre')
          ->select('conductores.*')
          ->get();

      return ConductorActivoResource::collection($conductores);
  }
  ```
- **Nuevo recurso** `app/Http/Resources/Tenant/ConductorActivoResource.php`: expone
  `id_conductor`, `nombre` (concatena `usuario->nombre` + `usuario->apellido_paterno`),
  `disponibilidad`, `placa` (`vehiculo?->placa`, `null` si el conductor no tiene vehículo cargado).
- **`routes/api.php`**: dentro del grupo `rol.tenant:AdminCliente,Despachador` (línea 112, el mismo
  que ya contiene `/pedidos`), se agrega:
  ```php
  Route::get('/conductores/activos', [ConductorController::class, 'activos']);
  ```
- Sin migraciones nuevas: `conductor_estado` y `conductores.disponibilidad` ya existen.

## Frontend (Vue 3)

- **Componente nuevo** `frontend/src/components/panel/ConductoresActivos.vue`:
  - `<script setup lang="ts">`, mismo patrón que `ServiciosEnTurno.vue`: `ref`s `conductores`,
    `cargando`, `error`; función `cargarConductores()` que pide `GET /t/{slug}/conductores/activos`
    con `http` (`@/lib/http`) y `route.params.slug`; `onMounted(cargarConductores)`.
  - No necesita `AbortController` ni `defineExpose` (no hay recarga disparada desde otro
    componente, a diferencia de `ServiciosEnTurno`).
  - `estadoColor` local (`Record<'DISPONIBLE' | 'OCUPADO' | 'DESCANSO' | 'FUERA_DE_SERVICIO', ...>`),
    igual patrón que `ServiciosEnTurno.vue`.
  - Template: `<aside>` con `position: fixed`, `right-0` (en vez de `left-0`), mismas clases de alto
    (`top-[4.25rem]`, `h-[calc(100vh-4.25rem)]`), mismo ancho (`w-[30%]`) y `z-index` que el panel
    izquierdo; header "Conductores activos"; mismos bloques `v-if="cargando"` /
    `v-else-if="error"` (con botón "Reintentar") / lista vacía / lista con ítems (`<li>` con borde,
    nombre + badge arriba, placa debajo) que ya usa `ServiciosEnTurno.vue`.
- **`frontend/src/views/tenant/panel/PanelView.vue`**:
  - Importa y monta `<ConductoresActivos />` junto a `<ServiciosEnTurno>` y `<MapaConductores>`.
  - El `<div>` que envuelve `<MapaConductores />` cambia de `class="ml-[30%] ..."` a
    `class="ml-[30%] mr-[30%] ..."`.

## Fuera de alcance

- Tiempo real (websockets/polling) para refrescar la lista sola.
- Click, filtros, búsqueda o acciones sobre los ítems (incluyendo cambiar `disponibilidad` desde
  aquí).
- Resaltar en el mapa al conductor seleccionado, o viceversa.
- Paginación o scroll infinito.
- Cambios a `panda_express` o a cómo se escribe `conductor_estado` (ya resuelto por spec 013).
- Conectar `MapaConductores.vue` a datos reales de posición (sigue usando
  `conductoresActivosFixture`, fuera de alcance de esta spec).

## Criterios de aceptación

1. `GET /t/{slug}/conductores/activos` responde 200 tanto para `AdminCliente` como para
   `Despachador`, y 401/403 sin sesión o con otro rol.
2. La respuesta solo incluye conductores con `conductor_estado.estado = 'ONLINE'`; un conductor
   `OFFLINE` o sin fila en `conductor_estado` no aparece.
3. La columna derecha de `/t/{slug}/panel` muestra un panel fijo pegado al borde derecho, con el
   mismo alto, header y estilo de ítem que "Viajes en turno" (columna izquierda).
4. Cada ítem muestra nombre, badge de disponibilidad (color correcto según el valor) y placa del
   vehículo.
5. Si no hay conductores activos, se muestra "No hay conductores activos".
6. Si la petición falla, se muestra un mensaje de error con botón "Reintentar" que reintenta la
   carga.
7. El mapa central ya no queda tapado por el panel derecho (mantiene visible su franja derecha).
8. El componente no depende de `conductoresActivosFixture` ni de `UiPersonListItem`.
9. ESLint/Prettier corren sin errores; Pint corre sin errores; `php artisan test` pasa (incluye
   prueba nueva del endpoint).

## Supuestos asumidos (registro completo)

1. "Conductores activos" = conductores `ONLINE` en `conductor_estado` (conectados desde la app en
   este momento); un conductor que nunca se conectó, o que está `OFFLINE`, no aparece.
2. No se filtra además por `disponibilidad`: un conductor `ONLINE` en `DESCANSO` o
   `FUERA_DE_SERVICIO` sí aparece, mostrando su disponibilidad como dato informativo.
3. Cada ítem muestra nombre, badge de disponibilidad y placa del vehículo — no se muestra el pedido
   asignado ni la ubicación GPS (ya cubiertos por otras partes del panel).
4. La lista es de todo el tenant, no filtrada por el despachador que la ve.
5. Lista de solo lectura: sin click, sin acciones, sin búsqueda ni filtros.
6. Se carga al entrar a `/panel` (con estados de carga/error/reintentar); no hay refresco automático
   ni en tiempo real.
7. El panel es un `<aside>` fijo (`position: fixed`) pegado al borde derecho, réplica visual exacta
   del panel izquierdo "Viajes en turno" (mismo alto, header, borde de ítem).
8. Acceso: mismos roles que ya ven `/panel` y "Viajes en turno" (`AdminCliente`, `Despachador`), sin
   roles nuevos.
9. Se necesita un endpoint nuevo de backend (`GET /conductores/activos`) porque no existe hoy ninguno
   que junte `conductor_estado` + conductor + vehículo, y el único listado de conductores existente
   (`GET /conductores`) está restringido a `AdminCliente`.
10. Estado vacío: "No hay conductores activos", mismo patrón que "Viajes en turno".
11. Se agrega la relación `Conductor::estadoActual()` (no existía) y un recurso nuevo
    `ConductorActivoResource` liviano (4 campos), en vez de reutilizar `ConductorResource`.
12. El nuevo endpoint vive en el grupo de rutas `rol.tenant:AdminCliente,Despachador` (el mismo que
    ya usa `/pedidos`), no en el grupo exclusivo de `AdminCliente` donde vive el resto de
    `ConductorController`.
13. `MapaConductores.vue` gana `mr-[30%]` simétrico al `ml-[30%]` que ya tenía, para no quedar tapado
    por el panel nuevo.
14. Colores del badge de disponibilidad: `DISPONIBLE: blue`, `OCUPADO: orange`, `DESCANSO: gray`,
    `FUERA_DE_SERVICIO: gray` — mismo patrón (`Record` de color por estado) que ya usa
    `ServiciosEnTurno.vue`.
