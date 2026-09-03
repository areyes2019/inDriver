# Spec: Datos reales en "Viajes en turno" (barra lateral de envíos)

## Historia de usuario

Como Despachador (o AdminCliente operando el panel), quiero que la barra lateral "Viajes en turno"
muestre los envíos reales que se han creado desde "Nueva Entrega", en vez de los datos de ejemplo
actuales, para tener contexto real de la operación al entrar al Panel.

Flujo esperado: Nuevo envío → Laravel API → Base de datos → listado/barra lateral de envíos.

No se modifica el diseño visual actual salvo lo estrictamente necesario para conectar los datos
reales (indicador de carga y mensaje de error).

## Objetivo / Alcance

`tenant/006-crud-pedidos.md` dejó funcionando el guardado real de pedidos (`POST /pedidos`) y dejó
preparado, sin consumidor, el listado (`GET /pedidos`). `tenant/008-servicios.md` construyó el panel
`ServiciosEnTurno.vue` sobre el fixture `viajesEnTurnoFixture`, dejando explícitamente pendiente
"la conexión al endpoint real de pedidos". Esta spec cierra ese pendiente: es puramente frontend,
no requiere cambios de backend.

Deja funcionando:

- `ServiciosEnTurno.vue` consume `GET /t/{slug}/pedidos` en vez de importar el fixture.
- Estado de carga mientras llega la respuesta.
- Estado de error con botón "Reintentar" si la petición falla.
- Recarga automática de la lista apenas se agenda un envío nuevo desde "Nueva Entrega".
- Si el tenant tiene más de 15 pedidos en turno (límite de paginación del backend), el panel trae
  automáticamente las páginas siguientes hasta juntar la lista completa.
- Se elimina `viajesEnTurnoFixture` y el tipo `ViajeEnTurno` de
  `frontend/src/fixtures/panelDespachador.ts` (el fixture de conductores, `conductoresActivosFixture`,
  permanece intacto — lo sigue usando el mapa, fuera de alcance de esta spec).

**No** incluye:

- Cambios al backend (`PedidoController`, `PedidoResource`, rutas): ya exponen todo lo necesario.
- Actualización en tiempo real por polling o websockets.
- Conexión del mapa de conductores (`MapaConductores.vue`) a datos reales — sigue usando
  `conductoresActivosFixture`.
- Click, filtros, búsqueda o acciones de cambio de estado sobre las tarjetas (igual que en 008).
- Filtrado por despachador (igual que en 008: se ve la operación completa del tenant).

## Decisión técnica

### Por qué se filtra en el cliente y no con `?estado=` en la petición

El backend (`PedidoController::index`) solo acepta un único valor en `?estado=`, pero "viajes en
turno" son pedidos en 6 estados distintos (`PENDIENTE, PUBLICADO, TOMADO, ARRIBADO, EN_CAMINO,
ARRIBADO_A_ENTREGA`). Pedir 6 veces el mismo listado sería peor que pedirlo una vez sin filtro y
excluir en el cliente los 3 estados finales (`ENTREGADO, RECHAZADO, CANCELADO`) — mismo criterio de
"estado no final" que ya usaba el fixture y que define `tenant/006`. Se acepta como conocido que,
según crezca el historial de pedidos `ENTREGADO`/`CANCELADO` de un tenant, esto puede traer páginas
de más solo para descartarlas; optimizar eso (por ejemplo agregando un filtro `?estado[]=` al
backend) queda fuera de esta spec.

### Por qué se recorren todas las páginas en vez de mostrar solo la primera

Ya se acordó (spec 008 + esta) que la lista no tiene paginación visible para el usuario: se scrollea
completa dentro del panel. Como el backend pagina de 15 en 15, el componente pide `page=1`, revisa
`meta.last_page` de la respuesta y sigue pidiendo páginas hasta la última antes de mostrar nada,
igual que se haría al hojear todas las páginas de un listado hasta el final.

### Por qué se refresca con una función expuesta y no con un evento nuevo

`PanelView.vue` ya escucha `@agendado` de `NuevaEntregaPanel.vue` (hoy solo para cerrar el panel
deslizante). Se agrega una referencia (`ref`) a `ServiciosEnTurno` y se expone un método
`recargar()` con `defineExpose`; `PanelView.vue` lo llama desde el mismo manejador de `@agendado`,
justo antes de cerrar el panel. No hace falta un bus de eventos ni un store nuevo para una relación
padre-hijo tan directa.

### Por qué se cancela la petición pendiente al desmontar

Con `AbortController` (API nativa del navegador, ya usable vía la opción `signal` de Axios): si el
usuario navega fuera de `/panel` mientras la lista o alguna página siguiente todavía está en
camino, se cancela la petición en el `onUnmounted` del componente. Evita que una respuesta tardía
intente actualizar un componente que ya no existe.

### Tipo de datos: se reemplaza `ViajeEnTurno` por un subconjunto de `PedidoResource`

El fixture definía su propio tipo `ViajeEnTurno` con los mismos 6 campos que ya expone
`PedidoResource` (`numero_pedido`, `cliente_nombre`, `direccion_entrega`, `estado`,
`lo_antes_posible`, `hora_desde`). Se conserva el nombre de tipo `ViajeEnTurno`, ahora definido
dentro del propio `ServiciosEnTurno.vue` (ya no en el archivo de fixtures) como la forma esperada de
cada elemento de `data` en la respuesta de `GET /pedidos`.

## Reglas de negocio

1. "Viajes en turno" = pedidos del tenant en estado `PENDIENTE, PUBLICADO, TOMADO, ARRIBADO,
   EN_CAMINO o ARRIBADO_A_ENTREGA` (se excluyen `ENTREGADO`, `RECHAZADO`, `CANCELADO`).
2. Orden: primero los pedidos con `lo_antes_posible = true`, después el resto ordenado por
   `hora_desde` ascendente (mismo criterio ya vigente).
3. No se filtra por despachador: se listan los viajes en turno de todo el tenant.
4. Si no hay viajes en turno (o todos son filtrados por ser de estado final), se muestra "No hay
   viajes en turno".
5. Si la petición falla, se muestra un mensaje de error con un botón "Reintentar" que vuelve a
   pedir la lista desde cero.
6. Al agendar un envío exitosamente desde "Nueva Entrega", la lista se recarga automáticamente sin
   que el usuario tenga que hacer nada.
7. Mientras se carga (la primera vez, o al reintentar/recargar), se muestra un indicador de carga
   en vez de la lista o del mensaje vacío.

## Backend (Laravel)

Sin cambios. Se reutiliza tal como está:

- `GET /t/{slug}/pedidos` (`PedidoController::index`), protegido por
  `rol.tenant:AdminCliente,Despachador` (`routes/api.php:111-114`) — mismo rol que ya requiere ver
  el panel.
- `PedidoResource`, que ya expone `numero_pedido`, `cliente_nombre`, `direccion_entrega`, `estado`,
  `lo_antes_posible`, `hora_desde` (además de otros campos no usados por este panel).
- Paginación existente de 15 por página (`Pedido::query()->paginate(15)`).

## Frontend (Vue 3)

- **`frontend/src/components/panel/ServiciosEnTurno.vue`**:
  - Quita `import { viajesEnTurnoFixture } from '@/fixtures/panelDespachador'`.
  - Define localmente `interface ViajeEnTurno` (los mismos 6 campos que antes).
  - Estado local: `viajesRaw = ref<ViajeEnTurno[]>([])`, `cargando = ref(false)`,
    `error = ref(false)`.
  - Función `cargarViajes()`: usa `http` (`@/lib/http`) y `route.params.slug` (mismo patrón que
    `NuevaEntregaPanel.vue`) para pedir `GET /t/{slug}/pedidos?page=N` empezando en `page=1`,
    acumulando `data` de cada respuesta y siguiendo hasta `meta.current_page === meta.last_page`;
    filtra el resultado acumulado a los 6 estados de la regla de negocio 1; maneja `cargando` y
    `error`; usa un `AbortController` cuya señal se pasa a cada petición y se cancela en
    `onUnmounted`.
  - El `computed` `viajes` existente (orden `lo_antes_posible` primero, luego `hora_desde`
    ascendente) ahora ordena `viajesRaw` en vez del fixture — sin cambios en su lógica interna.
  - `onMounted(cargarViajes)`.
  - `defineExpose({ recargar: cargarViajes })`.
  - Template: agrega un bloque `v-if="cargando"` (mensaje/spinner simple, mismas clases de texto
    que ya usa el panel) y un bloque `v-else-if="error"` (mensaje + botón "Reintentar" que llama
    `cargarViajes`) antes de los `v-if`/`v-else` ya existentes para lista vacía / lista con ítems.
- **`frontend/src/views/tenant/panel/PanelView.vue`**:
  - Agrega `const serviciosRef = ref<InstanceType<typeof ServiciosEnTurno>>()` y `ref="serviciosRef"`
    en `<ServiciosEnTurno />`.
  - El manejador de `@agendado` (hoy `cerrarNuevaEntrega`) llama primero
    `serviciosRef.value?.recargar()` y después cierra el panel deslizante.
- **`frontend/src/fixtures/panelDespachador.ts`**: se elimina `export interface ViajeEnTurno` y
  `export const viajesEnTurnoFixture`. `conductoresActivosFixture` y su tipo permanecen sin cambios.

## Fuera de alcance

- Cualquier cambio al backend (rutas, controlador, resource, migraciones).
- Polling, websockets o cualquier otra forma de actualización en tiempo real sin acción del usuario.
- Conexión de `MapaConductores.vue` a datos reales de conductores.
- Click, filtros, búsqueda o acciones (cambio de estado) sobre las tarjetas del panel.
- Filtrado por despachador.
- Optimización del backend para filtrar por múltiples estados en una sola petición (`?estado[]=` o
  endpoint dedicado "en turno") — se acepta traer y filtrar en el cliente por ahora.
- Rediseño visual del panel más allá de agregar los estados de carga y error.

## Criterios de aceptación

1. Al entrar a `/t/{slug}/panel`, el panel "Viajes en turno" ya no importa ni muestra datos de
   `viajesEnTurnoFixture` (el archivo de fixtures ya no lo exporta).
2. El panel muestra un indicador de carga mientras espera la respuesta de `GET /pedidos`.
3. El panel lista únicamente pedidos reales en estado `PENDIENTE, PUBLICADO, TOMADO, ARRIBADO,
   EN_CAMINO o ARRIBADO_A_ENTREGA`; los pedidos `ENTREGADO`, `RECHAZADO` o `CANCELADO` no aparecen.
4. Con más de 15 pedidos en turno, el panel los muestra todos (no solo los primeros 15) sin acción
   del usuario.
5. El orden se mantiene: `lo_antes_posible` primero, luego por `hora_desde` ascendente.
6. Si no hay pedidos en turno, se muestra "No hay viajes en turno".
7. Si la petición falla (simulable cortando la API o forzando un error), se muestra un mensaje de
   error con un botón "Reintentar" que, al presionarlo, vuelve a pedir los datos.
8. Al crear un envío desde "Nueva Entrega" y que la operación sea exitosa, el nuevo pedido aparece
   en la barra lateral sin recargar la página.
9. Navegar fuera del panel mientras la carga está en curso no produce errores en la consola del
   navegador.
10. El diseño visual (posición, ancho, tipografía, colores, esquinas rectas, badges de estado) no
    cambia respecto al estado actual, salvo los bloques nuevos de carga y error.
11. ESLint/Prettier corren sin errores; no se requieren cambios ni pruebas nuevas en el backend.

## Supuestos asumidos (registro completo)

1. "Barra lateral de envíos" es el panel "Viajes en turno" (`ServiciosEnTurno.vue`); el fixture de
   conductores (`conductoresActivosFixture`, usado por el mapa) no se toca.
2. "Viajes en turno" excluye pedidos en estado final (`ENTREGADO`, `RECHAZADO`, `CANCELADO`), igual
   que definía el fixture y que la máquina de estados de `tenant/006`.
3. El orden se mantiene igual que hoy: `lo_antes_posible` primero, luego `hora_desde` ascendente.
4. No hay actualización automática en tiempo real; la lista se carga al entrar al panel y se
   recarga automáticamente al agendar un envío nuevo desde "Nueva Entrega".
5. Se listan todos los pedidos en turno del tenant sin límite de paginación visible — el componente
   recorre automáticamente todas las páginas que devuelva el backend (15 por página).
6. Estado vacío: se conserva el mensaje actual "No hay viajes en turno".
7. Estado de error: mensaje simple + botón "Reintentar", sin romper el resto de la pantalla (mapa y
   botón "Nueva Entrega" siguen funcionando).
8. Los permisos de acceso son los mismos que ya existen (`AdminCliente`, `Despachador`), sin cambios
   de rol ni de middleware.
9. No se requieren cambios en el backend: `GET /pedidos` y `PedidoResource` ya devuelven todo lo
   necesario.
10. `cliente_nombre = null` se sigue mostrando como "Solicitante ocasional".
11. El filtrado por estado "no final" se hace en el cliente (una sola petición sin `?estado=`,
    recorriendo todas las páginas), no con múltiples peticiones por estado ni con un endpoint nuevo
    — optimizarlo queda fuera de esta spec.
12. La recarga tras crear un envío se resuelve exponiendo un método `recargar()` desde
    `ServiciosEnTurno` (vía `defineExpose`) que `PanelView.vue` llama en su manejador de
    `@agendado` ya existente — no se agrega un evento ni un store nuevo.
13. La petición en curso se cancela con `AbortController` si el componente se desmonta antes de que
    termine, para evitar errores de consola o actualizaciones sobre un componente ya destruido.
14. El tipo `ViajeEnTurno` se conserva con el mismo nombre y forma, pero pasa a vivir dentro de
    `ServiciosEnTurno.vue` en vez del archivo de fixtures, ya que ahora describe la respuesta real
    de la API y no un dato de ejemplo.
