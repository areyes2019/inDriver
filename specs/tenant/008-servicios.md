# Spec: Viajes en turno (panel izquierdo del Panel de Despachador)

## Historia de usuario

Como Despachador, quiero ver un resumen de los viajes en turno (pedidos que aún no llegaron a un
estado final) al entrar a mi Panel, para tener contexto inmediato de la operación sin ir al listado
completo de Pedidos.

Como Despachador, quiero además poder abrir cualquiera de esos viajes para ver el detalle del envío
(ruta, quién recibe, teléfono e importe) y cancelarlo desde ahí mismo, para resolver un problema
operativo sin salir del Panel.

## Objetivo / Alcance

Componente `ServiciosEnTurno.vue`: un panel fijo que se sobrepone al mapa central
(`tenant/009-mapa.md`) descrito en la ampliación de `tenant/007-panel-despachador.md`, y su panel
deslizante de detalle `DetalleEnvioPanel.vue`.

Esta spec describe el **estado final** del panel: estructura, estilo de las tarjetas, apertura del
detalle y cancelación del viaje. La mecánica de carga de datos reales (paginación completa, estados
de carga y error, `AbortController`, recarga tras agendar) vive en
`tenant/012-datos-reales-servicios-en-turno.md` y no se repite aquí.

> **Reemplazada en su parte de "lista única" por `tenant/027-panel-reactivo-sin-recargas.md` (v1.1).**
> Lo que esta spec define sobre **cómo se agrupan** las tarjetas dejó de aplicar: la lista dejó de ser
> una sola y pasó a dos secciones —"Viajes pendientes" (`PENDIENTE`, `PUBLICADO`) y "Viajes en curso"
> (`TOMADO`, `ARRIBADO`, `EN_CAMINO`, `ARRIBADO_A_ENTREGA`)— con encabezado, contador y estado vacío
> propios, y un viaje cruza de la primera a la segunda solo, en el acto, cuando un conductor lo toma.
> El orden que define esta spec sigue vigente, pero **dentro de cada sección** (SPEC-027, RN-37), y el
> vacío "No hay viajes en turno" queda solo para cuando las dos están vacías (RN-32).
>
> Para **qué se muestra en cada tarjeta y cómo se ve**, sigue mandando esta spec: la 027 cambia el
> agrupamiento, no el diseño de la tarjeta.

Deja funcionando:

- Panel con título "Viajes en turno", pegado al borde izquierdo real de la ventana del navegador,
  justo debajo del navbar fijo de `TenantLayout`, con el resto del alto de la pantalla.
- Lista scrolleable de tarjetas de viaje construidas con los pedidos reales del tenant que devuelve
  `GET /t/{slug}/pedidos`.
- Cada tarjeta muestra: ícono de calendario a color, folio con prefijo `#`, fecha y hora en color de
  acento, dirección de recogida con punto azul y dirección de entrega con punto rojo. Barra vertical
  de acento a la izquierda, esquinas redondeadas y sombra.
- Orden: primero los marcados "lo antes posible", luego el resto por `hora_desde` ascendente.
- Estado vacío ("No hay viajes en turno") si no hay pedidos en turno.
- Click sobre una tarjeta: abre `DetalleEnvioPanel.vue`, un panel que se desliza desde la izquierda
  y se superpone al panel de la lista, con el detalle del envío seleccionado.
- Botón "Cancelar viaje" dentro del detalle: previa confirmación, pasa el pedido a `CANCELADO` y lo
  saca de la lista.

**No** incluye:

- Filtros, búsqueda ni ordenamiento configurable sobre las tarjetas.
- Edición de ningún campo del pedido desde el detalle.
- Cualquier otro cambio de estado que no sea la cancelación (publicar, asignar, entregar).
- Paginación visible — la lista completa se muestra dentro del panel con scroll propio.
- Comportamiento específico para pantallas angostas (mobile).

## Decisión técnica

### Por qué "viajes en turno" excluye estados finales

Igual que la máquina de estados definida en `tenant/006-crud-pedidos.md`, un pedido en `ENTREGADO`,
`RECHAZADO` o `CANCELADO` ya salió de operación. El panel solo lista pedidos en `PENDIENTE`,
`PUBLICADO`, `TOMADO`, `ARRIBADO`, `EN_CAMINO` o `ARRIBADO_A_ENTREGA`.

### Por qué no se filtra por despachador

El panel muestra la operación completa del tenant (todos los pedidos en turno), no solo los que
gestionó el despachador que inició sesión — mismo criterio que ya usa `tenant/006`: "un despachador
ve y puede operar sobre todos los pedidos... por igual".

### Posición: `fixed` contra el viewport, no `absolute` contra un contenedor

El panel debe pegarse al borde izquierdo real de la ventana del navegador, no al borde del área de
contenido con padding que usa el resto de `TenantLayout` (`max-w-screen-xl`, `px-4`/`md:px-8`).
`position: fixed` logra esto sin romper el layout general: mientras ningún ancestro tenga
`transform`/`filter`/`contain` (no es el caso en `TenantLayout.vue` ni en `App.vue` para las rutas
`/t/*`), un elemento `fixed` se posiciona contra el viewport sin importar el padding o el centrado
de sus contenedores. Por eso no hace falta envolver el componente en una caja `relative` especial en
`PanelView.vue`.

### El panel empieza debajo del navbar, no se lo tapa

`UiNavbar.vue` ya es `fixed inset-x-0 top-0 z-40` y mide `4.25rem` de alto (franja de color de
`0.25rem` + barra de `4rem`) — el mismo valor que ya usa el propio navbar para su menú móvil
(`top-[4.25rem]`). El panel usa `top-[4.25rem]` y `h-[calc(100vh-4.25rem)]` para ocupar el resto de
la pantalla sin superponerse al navbar, y `z-30` (por debajo del `z-40` del navbar) para no competir
con él si algún ajuste futuro llegara a solaparlos.

### No se reusa `UiCard`

`UiCard` está pensada para tarjetas normales dentro del flujo del contenido (sombra suave, sin
posición propia). Este panel necesita comportarse distinto — posición fija, alto fijo, sombra más
marcada, esquinas rectas — así que se construye con marcado propio (`<aside>` con `<header>` +
lista), reusando solo las clases de texto (`text-heading`, `text-body`) para verse consistente con
el resto de la app.

### Esquinas: rectas en el panel, redondeadas en las tarjetas

El `<aside>` contenedor conserva esquinas rectas — está pegado a dos bordes de la ventana, donde un
redondeado no tendría a qué recortar. Las **tarjetas de viaje sí llevan `rounded-lg`**: flotan
dentro del panel, sobre un fondo gris, y el redondeado es lo que las separa visualmente del
contenedor. Esto reemplaza la regla anterior de "esquinas rectas también en las tarjetas".

### El área de la lista va sobre gris, no sobre blanco

Las tarjetas son blancas con sombra. Sobre un contenedor blanco la sombra prácticamente no se
percibe y las tarjetas se leen como bloques flotando sin borde. Por eso el contenedor scrolleable
usa `bg-slate-50`, mientras el `<header>` del panel se queda en blanco: el contraste hace que la
sombra y el redondeado hagan su trabajo.

### La barra de acento se hace con `border-l-4`, no con un `<span>` posicionado

Un borde izquierdo grueso da el mismo resultado visual que una barra absoluta, pero respeta el
`border-radius` de la tarjeta sin necesidad de `overflow-hidden` ni de un elemento extra en el
árbol. Se usa el token `accent` (`#6366f1`), ya definido en `@theme`; no se agrega ningún color
nuevo.

### La fecha se arma en el cliente con `Intl`, no en el backend

El backend devuelve `fecha_servicio` (`YYYY-MM-DD`) y `hora_desde` por separado, en crudo. La
etiqueta `sáb 5 de sept 09:41 a.m.` se compone en el cliente con `Intl.DateTimeFormat('es-MX', ...)`:
una llamada para las partes de fecha (`weekday`, `day`, `month` cortos) y otra para la hora
(`hour`/`minute` con `hour12: true`). Se acepta la abreviatura que devuelve `Intl` — en español es
**`sept`**, no `sep`; forzar tres letras exigiría mapear los doce meses a mano y no vale el código.
Las abreviaturas exactas pueden variar levemente según la versión de ICU del navegador, y se acepta.

### El ícono de calendario tiene que entrar a la lista blanca de Iconify

El proyecto **no usa `@lucide/vue`** (lo que aún dice `004-guia-diseno-base.md` está
desactualizado): usa `@iconify/vue` con las colecciones `flat-color-icons` y `fluent-color`, y un
paso previo (`frontend/scripts/generate-icon-data.mjs`, `npm run icons:build`) que extrae **solo los
íconos nombrados en una lista blanca** hacia `src/assets/icon-data.json`. Un ícono que no esté en
esa lista simplemente no se renderiza. Por eso agregar `calendar`, `businessman`, `phone` y
`todo-list` al script y regenerar el JSON es parte obligatoria de esta spec, no un detalle de
implementación.

### La tarjeta ya no muestra el estado ni el cliente

El badge de estado (`UiBadge`) y el nombre del cliente salen de la tarjeta: el diseño prioriza
identificar el envío por su ruta (de dónde a dónde) y su horario. El estado sigue existiendo y sigue
decidiendo qué pedidos entran al panel — simplemente ya no se dibuja. Con el badge se va también el
mapa `estadoColor`, y con el nombre del cliente se va la regla de "Solicitante ocasional" **para
esta vista** (sigue vigente en el listado de Pedidos de `tenant/006`).

### Cuál viaje está seleccionado vive en `PanelView`, no en `ServiciosEnTurno`

`PanelView.vue` es el único punto que ve a los dos paneles deslizantes (`NuevaEntregaPanel` y
`DetalleEnvioPanel`), así que es donde puede garantizarse que nunca estén los dos abiertos a la vez.
`ServiciosEnTurno` solo emite `seleccionar(viaje)` y recibe de vuelta cuál está seleccionado para
marcarlo; no guarda esa decisión.

### El detalle no hace una petición extra

`GET /t/{slug}/pedidos` ya devuelve todos los campos que muestra el detalle (`direccion_recogida`,
`direccion_entrega`, `nombre_solicitante`, `telefono_solicitante`, `importe_envio`). Pedir
`GET /pedidos/{id}` al abrir el panel sería una llamada de red para traer datos que ya están en
memoria, y agregaría un estado de carga a una interacción que hoy es instantánea.

### La tarjeta clickeable es un `<button>`, no un `<li @click>`

Un `<li>` con manejador de click no entra en el orden de tabulación ni responde a Enter/Espacio sin
código extra (`tabindex`, `role`, manejador de teclado). Un `<button type="button">` a ancho
completo dentro del `<li>` lo resuelve con marcado nativo. Las clases de estilo de la tarjeta viven
en el `<button>`.

### Se reusa `PATCH /pedidos/{pedido}/estado`, no se crea un endpoint de cancelación

El endpoint ya existe, ya valida la transición contra `PedidoEstadoService::TRANSICIONES` (que
permite `CANCELADO` desde los seis estados del panel), ya escribe `fecha_cancelacion`,
`cancelado_por` y `motivo_cancelacion`, ya deja registro en `auditoria` y, cuando el pedido tenía
conductor asignado, ya dispara `PedidoCanceladoParaConductor` para avisarle. Un endpoint nuevo
duplicaría todo eso. Esta spec no toca el backend.

### "Cancelar" saca el viaje del panel; no borra el pedido

Cancelar transiciona el pedido a `CANCELADO`, que es estado final y por lo tanto queda fuera del
filtro de "viajes en turno" — por eso desaparece de la lista. El registro sigue existiendo en la
base de datos y sigue siendo visible en el listado de Pedidos con su historial y su auditoría. El
texto del diálogo de confirmación lo dice explícitamente para que nadie lea "cancelar" como
"eliminar".

### El panel de detalle copia la mecánica de `NuevaEntregaPanel`

`NuevaEntregaPanel.vue` ya resolvió el patrón de panel deslizante en este mismo layout: `fixed`,
`z-[35]`, `transition-transform duration-[400ms] ease-in-out` y alternancia entre `translate-x-0` y
`-translate-x-full`. `DetalleEnvioPanel` usa exactamente lo mismo. La única diferencia deliberada es
la geometría: `NuevaEntregaPanel` ocupa `top-0 h-screen w-[45%]`, mientras que el detalle usa
`top-[4.25rem] h-[calc(100vh-4.25rem)] w-[20%]` para calzar **exacto** sobre la columna de la lista
sin invadir el mapa central.

## Reglas de negocio

- "Viajes en turno" = pedidos del tenant en estado `PENDIENTE`, `PUBLICADO`, `TOMADO`, `ARRIBADO`,
  `EN_CAMINO` o `ARRIBADO_A_ENTREGA`. Se excluyen `ENTREGADO`, `RECHAZADO` y `CANCELADO`.
- No se filtra por despachador: se ve la operación completa del tenant.
- Orden: ítems con `lo_antes_posible: true` primero (en el orden en que los devuelve la API),
  después el resto ordenado por `hora_desde` ascendente.
- En la tarjeta, ambas direcciones se muestran en una sola línea, truncadas con `...` si exceden el
  ancho disponible (`truncate` de Tailwind).
- En el detalle, las direcciones se muestran completas y envuelven en varias líneas — no se truncan.
- La etiqueta de fecha de la tarjeta es `sáb 5 de sept 09:41 a.m.` cuando el pedido tiene horario, y
  el texto **"Lo antes posible"** cuando `lo_antes_posible` es `true` (en ese caso `hora_desde` es
  `null`). Ambas variantes se pintan con el mismo estilo de acento.
- Solo puede haber un viaje seleccionado a la vez. Clickear otra tarjeta reemplaza el contenido del
  panel de detalle sin cerrarlo.
- Nunca están abiertos a la vez el detalle del envío y el panel de "Nueva Entrega": abrir uno cierra
  el otro.
- El detalle se cierra con el botón `×` o con la tecla `Escape`.
- El botón "Cancelar viaje" está disponible en los seis estados del panel, porque la máquina de
  estados permite pasar a `CANCELADO` desde todos ellos.
- Cancelar exige confirmación previa. No se pide motivo: se envía `cancelado_por: 'ADMIN'` y
  `motivo` vacío.
- Tras una cancelación exitosa se cierra el detalle y se recarga la lista; el viaje ya no aparece.
- Si la cancelación falla, el panel de detalle **queda abierto** y muestra un mensaje de error con
  opción de reintentar.

## Backend (Laravel)

Sin cambios. Se reutiliza tal como está:

- `GET /t/{slug}/pedidos` (`PedidoController::index`) para la lista, protegido por
  `rol.tenant:AdminCliente,Despachador` — mismo rol que ya requiere ver el panel. La mecánica de
  paginación y carga vive en `tenant/012`.
- `PATCH /t/{slug}/pedidos/{pedido}/estado` (`PedidoController::cambiarEstado`) para la
  cancelación, con cuerpo `{ "estado": "CANCELADO", "cancelado_por": "ADMIN" }`.
- `PedidoResource`, que ya expone los campos que consume el panel: `id_pedido`, `numero_pedido`,
  `direccion_recogida`, `direccion_entrega`, `nombre_solicitante`, `telefono_solicitante`,
  `importe_envio`, `fecha_servicio`, `hora_desde`, `lo_antes_posible` y `estado`.

## Frontend (Vue 3)

### `frontend/scripts/generate-icon-data.mjs`

Agregar `'calendar'`, `'businessman'`, `'phone'` y `'todo-list'` a `FLAT_COLOR_ICON_NAMES` y correr
`npm run icons:build` para regenerar `src/assets/icon-data.json`. Los cuatro existen en la colección
`flat-color-icons`.

### `frontend/src/components/panel/ServiciosEnTurno.vue`

- El tipo local `ViajeEnTurno` (y su gemelo `PedidoApiItem`) suma los campos que la tarjeta y el
  detalle necesitan: `id_pedido: number`, `direccion_recogida: string`,
  `fecha_servicio: string | null`, `nombre_solicitante: string | null`,
  `telefono_solicitante: string | null`, `importe_envio: string | number | null`. `id_pedido` es
  obligatorio: es la clave con la que se arma la URL del PATCH de cancelación (`numero_pedido` es el
  folio visible, no la clave primaria).
- Se eliminan el import de `UiBadge` y el mapa `estadoColor`. El `Set` `ESTADOS_EN_TURNO` **se
  conserva**: sigue decidiendo qué pedidos entran al panel.
- Se agrega una función `etiquetaFecha(viaje)` que devuelve `'Lo antes posible'` si
  `lo_antes_posible`, y si no compone la fecha con `Intl.DateTimeFormat('es-MX', ...)` a partir de
  `fecha_servicio` + `hora_desde` (normalizando `hora_desde` a `HH:mm` antes de construir la fecha).
  Si falta `fecha_servicio`, cae a mostrar solo la hora.
- `props`: `seleccionadoId?: number | null`, para marcar la tarjeta abierta.
- `emits`: `seleccionar: [viaje: ViajeEnTurno]`.
- `defineExpose({ recargar })` se conserva sin cambios (`tenant/012`).
- Template:
  - El `<aside>` mantiene `fixed left-0 top-[4.25rem] z-30 h-[calc(100vh-4.25rem)] w-[20%] bg-white
    shadow-xl` y el `<header>` blanco con el título.
  - El contenedor scrolleable pasa de `p-4` a `bg-slate-50 p-4`.
  - Cada `<li>` contiene un `<button type="button">` a ancho completo con
    `w-full rounded-lg border-l-4 border-accent bg-white p-3 text-left shadow-md`, más un anillo de
    selección (`ring-2 ring-accent`) cuando `viaje.id_pedido === seleccionadoId`.
  - Dentro del botón: fila de encabezado (`<Icon icon="flat-color-icons:calendar" width="18"
    height="18" aria-hidden="true" />` + `#{{ viaje.numero_pedido }}` en `font-semibold
    text-heading`, y la etiqueta de fecha a la derecha en `text-accent`), seguida de dos filas de
    dirección.
  - En esa fila de encabezado la etiqueta de fecha es `shrink-0` (no se parte nunca) y el folio va
    en un contenedor `min-w-0` con `truncate`. Con el panel al 20% los dos textos juntos ocupan casi
    todo el ancho disponible, así que si algo tiene que ceder, cede el folio cortándose — nunca
    desbordando la tarjeta.
  - Cada fila de dirección es `flex items-center gap-2 min-w-0`, con un punto
    `h-2 w-2 shrink-0 rounded-full` (`bg-blue-500` para recogida, `bg-red-500` para entrega) y el
    texto en `truncate`. El `min-w-0` es obligatorio: sin él `truncate` no corta dentro de un flex y
    la dirección desborda la tarjeta.
- El `:key` del `v-for` pasa de `numero_pedido` a `id_pedido`.

### `frontend/src/components/panel/DetalleEnvioPanel.vue` (nuevo)

- `props`: `viaje: ViajeEnTurno | null`. `emits`: `cerrar: []`, `cancelado: []`.
- Raíz `<aside>` con `fixed left-0 top-[4.25rem] z-[35] flex h-[calc(100vh-4.25rem)] w-[20%]
  flex-col bg-white shadow-xl transition-transform duration-[400ms] ease-in-out` y
  `:class="viaje ? 'translate-x-0' : '-translate-x-full'"`.
- `<header>`: etiqueta "VIAJE SELECCIONADO" (`text-xs font-semibold uppercase tracking-wide
  text-body`), título "Detalle del envío" (`text-lg font-semibold text-heading`) y botón `×` con
  `rounded-lg bg-slate-100 p-2`, que emite `cerrar`.
- Cuerpo `flex-1 overflow-y-auto`, con tres bloques separados por `border-t border-default`:
  1. **Ruta**: contenedor `relative`; un `<span class="absolute left-[5px] top-4 bottom-4 border-l-2
     border-dashed border-accent">` dibuja la línea punteada, y los dos puntos
     (`relative z-10 h-3 w-3 rounded-full`, `bg-green-500` y `bg-red-500`) la tapan en sus extremos.
     Cada uno con su etiqueta ("RECOGIDA" / "ENTREGA") y su dirección completa, sin `truncate`.
  2. **Datos de envío**: título con `<Icon icon="flat-color-icons:todo-list" />`, y dos filas —
     `flat-color-icons:businessman` + "Recibe:" + `nombre_solicitante`, y `flat-color-icons:phone` +
     "Teléfono:" + `telefono_solicitante`. Etiquetas en `text-body`, valores en
     `font-semibold text-heading`.
  3. **Importe**: fila "Envío" a la izquierda e `importe_envio` a la derecha, formateado con
     `Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' })`.
- Botón "Cancelar viaje" a ancho completo, `rounded-lg bg-red-500 py-3 font-semibold text-white`,
  deshabilitado mientras `cancelando` sea `true`.
- Estado local `cancelando` y `errorCancelar`. Al confirmar, `PATCH
  /t/{slug}/pedidos/{id_pedido}/estado` con `{ estado: 'CANCELADO', cancelado_por: 'ADMIN' }` usando
  `http` (`@/lib/http`) y `route.params.slug`, igual que el resto de los componentes del panel. En
  éxito emite `cancelado`; en error deja el panel abierto y muestra el mensaje con un botón
  "Reintentar".
- `UiConfirmDialog` con `:require-password="false"`, `title="Cancelar viaje"`,
  `confirm-label="Sí, cancelar"` y el mensaje: *"El viaje #{folio} saldrá del panel y quedará como
  CANCELADO. Esta acción no se puede deshacer."*
- Listener de `keydown` en `window` registrado en `onMounted` y removido en `onUnmounted`, que emite
  `cerrar` con `Escape`.

### `frontend/src/views/tenant/panel/PanelView.vue`

- Nuevo estado `const viajeSeleccionado = ref<ViajeEnTurno | null>(null)`.
- `<ServiciosEnTurno>` recibe `:seleccionado-id="viajeSeleccionado?.id_pedido ?? null"` y escucha
  `@seleccionar`, que asigna `viajeSeleccionado` y pone `nuevaEntregaAbierta = false`.
- `alternarNuevaEntrega()` limpia `viajeSeleccionado = null` al abrir el panel de Nueva Entrega.
- Se monta `<DetalleEnvioPanel :viaje="viajeSeleccionado" @cerrar="viajeSeleccionado = null"
  @cancelado="onViajeCancelado" />`.
- `onViajeCancelado()` limpia `viajeSeleccionado` y llama `serviciosRef.value?.recargar()` — el
  mismo camino que ya usa `onAgendado()`.

## Fuera de alcance

- Cualquier cambio de backend: rutas, controladores, resources o migraciones.
- Filtros, búsqueda u ordenamiento configurable sobre las tarjetas.
- Edición de campos del pedido desde el detalle.
- Cambios de estado distintos de la cancelación (publicar, asignar conductor, marcar entregado).
- Pedir motivo de cancelación al despachador.
- Paginación visible (la lista completa scrollea dentro del panel).
- Filtrado por despachador.
- Actualización en tiempo real del panel por websockets (`tenant/018`–`022` cubren el protocolo;
  este panel se refresca al montar, al agendar y al cancelar).
- Comportamiento específico para pantallas angostas (mobile) — el panel usa el mismo `w-[20%]` fijo
  en cualquier tamaño de pantalla.
- Actualizar `004-guia-diseno-base.md`, cuyo apartado de iconografía quedó desactualizado (dice
  `@lucide/vue`; el proyecto usa `@iconify/vue`) — se deja constancia aquí, se corrige en su propia
  spec.

## Criterios de aceptación

1. El panel "Viajes en turno" está pegado al borde izquierdo real de la ventana (`left: 0` del
   viewport), empieza justo debajo del navbar y llega hasta el borde inferior.
2. El panel tiene esquinas rectas y fondo blanco en su encabezado; el área scrolleable de la lista
   tiene fondo gris claro.
3. Cada tarjeta de viaje tiene esquinas redondeadas, fondo blanco, sombra y una barra vertical de
   acento en su borde izquierdo.
4. Cada tarjeta muestra, en este orden: ícono de calendario a color, folio con prefijo `#`, y a la
   derecha la fecha/hora en color de acento (o "Lo antes posible"); debajo, la dirección de recogida
   con punto azul y la de entrega con punto rojo, ambas truncadas a una línea.
5. Ninguna tarjeta muestra badge de estado ni nombre de cliente.
6. El ícono de calendario se renderiza realmente (está incluido en `icon-data.json` tras correr
   `npm run icons:build`).
7. Los ítems con `lo_antes_posible: true` aparecen antes que el resto; el resto está ordenado por
   `hora_desde` ascendente.
8. Si no hay pedidos en turno, se muestra "No hay viajes en turno".
9. Al hacer click en una tarjeta se desliza desde la izquierda el panel "Detalle del envío", que
   cubre exactamente la columna de la lista, y la tarjeta clickeada queda marcada como seleccionada.
10. La tarjeta es alcanzable con `Tab` y se abre con `Enter` o `Espacio`.
11. El detalle muestra ruta completa (recogida en verde y entrega en rojo, unidas por línea
    punteada, con las direcciones sin truncar), "Recibe" y "Teléfono", y la fila "Envío" con el
    importe formateado en pesos.
12. El detalle no dispara ninguna petición HTTP al abrirse.
13. Clickear otra tarjeta con el detalle abierto reemplaza su contenido sin cerrarlo.
14. El detalle se cierra con el botón `×` y con la tecla `Escape`.
15. Abrir el detalle cierra "Nueva Entrega" si estaba abierto, y abrir "Nueva Entrega" cierra el
    detalle.
16. "Cancelar viaje" abre un diálogo de confirmación cuyo texto aclara que el viaje quedará como
    CANCELADO y que la acción no se puede deshacer.
17. Al confirmar, se envía `PATCH /t/{slug}/pedidos/{id}/estado` con `estado: 'CANCELADO'` y
    `cancelado_por: 'ADMIN'`; el detalle se cierra, la lista se recarga y el viaje ya no aparece.
18. El pedido cancelado sigue existiendo: aparece con estado `CANCELADO` en el listado de Pedidos.
19. Si la cancelación falla, el detalle permanece abierto con un mensaje de error y un botón para
    reintentar.
20. No se modificó ningún archivo del backend.
21. `npm run build` compila sin errores; ESLint/Prettier corren sin errores.

## Supuestos asumidos (registro completo)

1. "Viajes en turno" = pedidos con estado no final (excluye `ENTREGADO`/`RECHAZADO`/`CANCELADO`),
   sin filtrar por despachador.
2. Orden: lo antes posible primero, luego por `hora_desde` ascendente.
3. Campos mostrados por tarjeta: ícono de calendario, folio con `#`, fecha/hora en acento, dirección
   de recogida (punto azul) y dirección de entrega (punto rojo), ambas truncadas. **Ya no** se
   muestran el badge de estado ni el nombre del cliente.
4. Estado vacío con mensaje si no hay viajes en turno.
5. La tarjeta **es clickeable** y abre el detalle del envío. La única acción disponible sobre un
   viaje es cancelarlo.
6. La lista completa se muestra con scroll propio dentro del panel, sin paginación visible.
7. El tipo `ViajeEnTurno` vive dentro de `ServiciosEnTurno.vue` (ya no hay fixture; ver
   `tenant/012`) y se exporta desde ahí para que `DetalleEnvioPanel` lo reuse.
8. Los componentes viven en `frontend/src/components/panel/`, carpeta separada de `components/ui/`.
9. El mapa de colores de estado (`estadoColor`) se elimina junto con el badge.
10. "Pegado al borde izquierdo" es el borde real de la ventana del navegador (`position: fixed`),
    no el borde del área de contenido con padding de `TenantLayout`.
11. El panel empieza en `top-[4.25rem]` (justo debajo del navbar fijo, que mide esa altura); el
    navbar se ve siempre completo.
12. `z-index`: lista `z-30`, panel de detalle `z-[35]` (igual que `NuevaEntregaPanel`), navbar
    `z-40`.
13. El panel no usa `UiCard` — tiene su propio marcado, porque necesita una posición y una altura
    que `UiCard` no soporta.
14. Esquinas **rectas en el panel contenedor** y **redondeadas (`rounded-lg`) en las tarjetas** de
    viaje. Esto reemplaza la regla anterior de esquinas rectas en ambos.
15. En mobile, el panel se mantiene con el mismo `w-[20%]` fijo y flotando sobre el mapa; el ajuste
    de legibilidad en pantallas angostas queda fuera de esta spec.
16. El área scrolleable de la lista usa `bg-slate-50` para que la sombra de las tarjetas blancas se
    perciba; el `<header>` del panel queda blanco.
17. El morado de la barra de acento y del texto de fecha es el token `accent` (`#6366f1`) ya
    existente. No se agregan tokens de color nuevos a `@theme`; azul y rojo de los puntos salen de
    clases nativas de Tailwind, igual que ya hace `UiBadge`.
18. La abreviatura de mes es la que devuelve `Intl` en español (**`sept`**), no `sep`. No se mapean
    los meses a mano.
19. El estilo se implementa con clases de Tailwind dentro de los componentes del panel; **no** se
    crea un componente `Ui*` nuevo ni se documenta en `/admin/style-guide`.
20. Los íconos se agregan a la lista blanca de `generate-icon-data.mjs` y se regenera
    `icon-data.json`; sin ese paso no aparecen.
21. El panel de detalle **no hace petición extra**: pinta el pedido que ya está en memoria en la
    lista.
22. El detalle tiene `w-[20%]`, igual que la lista, y la cubre exacto sin invadir el mapa.
23. El detalle **no muestra el estado del pedido** ni caja de modalidad de pago; solo ruta, datos de
    envío e importe de envío.
24. La cancelación reusa `PATCH /pedidos/{pedido}/estado`; no se crea endpoint nuevo ni se modifica
    el backend.
25. Cancelar **no borra** el pedido de la base de datos: lo pasa a `CANCELADO`, estado final, por lo
    que sale del panel pero permanece en el listado de Pedidos.
26. No se pide motivo de cancelación; se envía `cancelado_por: 'ADMIN'` y `motivo` vacío.
27. El aviso al conductor de un viaje cancelado lo dispara el backend
    (`PedidoCanceladoParaConductor`); el frontend no hace nada extra.
28. El botón "Cancelar viaje" se muestra en los seis estados del panel.
29. Solo un panel deslizante abierto a la vez: el detalle y "Nueva Entrega" se excluyen mutuamente.
30. La selección del viaje vive en `PanelView.vue`, no dentro de `ServiciosEnTurno.vue`.
