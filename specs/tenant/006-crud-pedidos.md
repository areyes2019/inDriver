# Spec: Alta de pedidos (panel deslizante del Despachador)

> **Spec unificada**: consolida `tenant/006-crud-pedidos.md` (autoridad principal) y
> `tenant/011-nueva-entrega.md` (panel deslizante de agendamiento rápido, absorbido aquí). Donde
> ambas entraban en conflicto, gana 006. Spec abierta: va a seguir cambiando (por ejemplo, cuando se
> conecte el motor de asignación, o cuando se defina dónde vive el cambio de estado de un pedido ya
> creado). No cerrarla ni renumerarla al agregar contenido nuevo.
>
> **Corrección de alcance (posterior a la unificación original)**: no existe un "formulario completo"
> de pedidos como página propia, ni un menú "Pedidos" en el panel del tenant. La creación de pedidos
> es **exclusiva del rol Despachador**, y ocurre **únicamente** desde el Panel de Despachador
> (`/t/{slug}/panel`), a través del botón "Nueva Entrega" que abre el panel deslizante. Esta spec ya
> no cubre listado ni edición de pedidos vía UI propia — ver "Fuera de alcance".
>
> **Ajustes de UX y cálculo (esta ronda)**: se agrega un select de "Cliente frecuente" que
> autocompleta nombre/teléfono del solicitante y, si aplica, la dirección de recogida; nombre y
> teléfono del solicitante pasan a una sola fila; ambos campos de dirección (recogida y entrega)
> muestran un indicador de "ubicación encontrada"; la fecha de servicio deja de ser obligatoria
> cuando "lo antes posible" está marcado; el campo de importe de cobro se oculta según la modalidad
> de pago; y se agrega un "total del viaje" visible en el formulario, calculado con las tarifas ya
> configurables (spec 015). Sigue sin haber listado ni edición de pedidos vía UI propia.

## Historia de usuario

Como Despachador, quiero un acceso rápido desde el Panel para registrar una nueva entrega —
eligiendo un cliente frecuente cuando aplica, y viendo el total estimado del viaje antes de
confirmar— sin perder de vista el resto de la pantalla, para agendar pedidos con fricción mínima
durante mi turno.

## Objetivo / Alcance

Cubre la tabla 08 (`pedidos`) de `db/02-base-de-datos.md`, la tabla central del sistema operativo,
limitado a la **creación** de un pedido. Depende de `clientes` (06, ya implementada — esta spec sí
expone un select de solo lectura sobre ella, ver más abajo), `despachadores` (02), `conductores`
(03) y `vehiculos` (04), también implementadas, aunque esta spec no expone selects para estas
últimas tres (ver "No incluye").

Deja funcionando:

- En `/t/{slug}/panel`, el navbar (`UiNavbar`, dentro de `TenantLayout`) muestra un botón destacado
  "Nueva Entrega", visible **únicamente** en esa ruta y **únicamente** para el rol `Despachador`.
- Al entrar a `/t/{slug}/panel` como Despachador, el foco del teclado se posiciona automáticamente
  sobre ese botón.
- Al activarlo (clic, o Enter/Espacio con el foco puesto en él), un panel se **desliza** desde fuera
  de la pantalla por la izquierda hasta cubrir el 45% del ancho del viewport — más ancho que
  `ServiciosEnTurno` (que ocupa 30%), así que al abrirse tapa visualmente tanto a Servicios en turno
  como una franja adicional del contenido a su derecha. La animación usa `transform:
  translateX(...)` con una transición de `0.4s ease-in-out` (el panel siempre está en el DOM; lo que
  cambia es su posición, no su visibilidad, para que el deslizamiento se vea).
- El panel ocupa la altura completa del navegador (de `top: 0` a `bottom: 100vh`) — a diferencia de
  `ServiciosEnTurno`, que arranca debajo del navbar. El navbar, con `z-index` más alto, queda
  visualmente por encima de la parte superior del panel.
- El mismo botón actúa como interruptor: si el panel está abierto, volver a activarlo lo cierra
  (se desliza de regreso hacia la izquierda, fuera de la pantalla).
- Con el foco en el botón, `ArrowRight`/`ArrowDown` abre el panel y mueve el foco al primer campo
  del formulario. `Escape` con el foco dentro del formulario lo cierra y devuelve el foco al botón.
- El formulario, en este orden, incluye:
  1. **Cliente frecuente** (select, opcional): lista los clientes activos del tenant. Elegir uno
     autocompleta nombre y teléfono del solicitante y, si ese cliente tiene una única dirección
     guardada, también la dirección de recogida (ver "Selección de cliente frecuente" en Decisión
     técnica). Los campos autocompletados siguen siendo editables.
  2. **Nombre del solicitante** y **teléfono del solicitante**, en una misma fila (dos columnas en
     escritorio, una columna en pantallas angostas).
  3. **Dirección de recogida** y **dirección de entrega**, cada una con un indicador visual (ícono
     de check) que aparece cuando la dirección quedó resuelta contra Google Maps (ver "Indicador de
     dirección resuelta").
  4. **Fecha de servicio** y horario: si "lo antes posible" está marcado (default), la fecha no es
     obligatoria y se entiende que es la fecha de hoy; si no, la fecha es obligatoria y, además,
     ambas horas (desde/hasta) son obligatorias, con "hasta" posterior a "desde" (misma validación
     ya existente).
  5. **Modalidad de pago** (selector de 3 botones, sin cambios en esta ronda).
  6. **Importe de envío**, siempre visible; **importe de cobro**, visible solo cuando la modalidad
     de pago es "Receptor paga envío + producto" (ver "Importe de cobro condicionado a la
     modalidad").
  7. **Total del viaje**: bloque informativo, visible cuando ambas direcciones están resueltas,
     calculado a partir de la distancia entre recogida y entrega y las tarifas configuradas por el
     tenant (ver "Cálculo del total del viaje").
  8. Botón "Agendar".
- Las direcciones se capturan con `UiAddressAutocomplete` y se muestran en un mapa de vista previa
  (`UiVistaPreviaRuta`), pero **las coordenadas nunca se envían ni se guardan** — ver "Por qué se
  elimina latitud/longitud".
- "Agendar" con datos válidos hace `POST /pedidos` contra el backend real: crea el pedido, limpia el
  formulario y cierra el panel. "Cancelar" cierra sin guardar.

**No** incluye (por ahora):

- Ningún menú, listado ni formulario de edición de pedidos — ver "Fuera de alcance".
- Selects de despachador/conductor/vehículo, ni latitud/longitud — quedan fuera de esta primera
  versión (los dos primeros porque dependerían de catálogos vía API que ya no se exponen para esta
  spec; latitud/longitud porque no alimentan ningún cálculo de negocio de asignación todavía — el
  cálculo del total del viaje usa la distancia solo en memoria, no las columnas eliminadas).
- Buscador dentro del select de cliente frecuente: carga la lista completa de clientes activos, sin
  campo de búsqueda ni paginado en el frontend.
- Guardar el total del viaje calculado como parte del pedido: es solo informativo en el formulario,
  no viaja en el `POST /pedidos` ni se agrega una columna nueva a `pedidos`.
- Tratamiento específico para pantallas angostas (mobile) en el layout general del panel deslizante
  — mismo límite conocido que el resto del Panel de Despachador (`ServiciosEnTurno` tampoco lo
  tiene). El selector de modalidad de pago y la fila de nombre/teléfono son las únicas piezas de
  esta spec con requisito explícito de verse bien en móvil.

## Decisión técnica

### Por qué ya no hay menú, listado ni formulario de edición de pedidos

La versión anterior de esta spec (fusión de 006 + 011) mantenía dos superficies: un "formulario
completo" en páginas propias (`ListaPedidosView.vue`, `CrearPedidoView.vue`, `EditarPedidoView.vue`,
con un ítem "Pedidos" en el menú lateral, accesible para `AdminCliente` y `Despachador`) y el panel
deslizante del Panel de Despachador. Esa duplicidad queda eliminada: **todos** los pedidos se crean
exclusivamente desde el panel deslizante, y solo el rol `Despachador` puede hacerlo. Las tres vistas
y el ítem de menú se eliminan del alcance de esta spec — no se listan como "fuera de alcance
temporal" sino como decisión de producto: no existen.

Consecuencia directa: el endpoint `GET /pedidos/recursos` (catálogos para poblar los selects de
cliente/despachador/conductor/vehículo que solo usaba el formulario completo) deja de tener
consumidor y se elimina. El select de "Cliente frecuente" que agrega esta ronda **no** revive ese
endpoint: usa `GET /clientes`, que ya existe como parte de la spec de clientes (06) — ver siguiente
sección.

Los endpoints `index`, `show`, `update` y `cambiarEstado` del backend (ver "Backend") se conservan
tal cual —no dejan de ser válidos como capacidad de la tabla central del sistema operativo— pero
esta spec no expone ninguna UI propia para ellos. Cómo y dónde se lista un pedido, se edita, o se
mueve a través de su ciclo de vida después de creado, queda pendiente de una spec futura (candidato
natural: `ServiciosEnTurno`, ya presente en el Panel de Despachador).

### Por qué la creación es exclusiva de `Despachador` (a diferencia del resto de la spec original)

La spec original daba acceso a `AdminCliente` y `Despachador` por igual a todo el CRUD de pedidos.
Ahora que la única superficie de creación es el panel deslizante del Panel de Despachador, crear un
pedido queda limitado a `Despachador`: es quien opera el turno y usa el botón "Nueva Entrega". El
botón no se muestra a `AdminCliente` aunque esté en `/t/{slug}/panel` (evita ofrecer una acción que
el backend rechazaría con `403`), y `POST /pedidos` valida el rol server-side, no solo lo esconde en
el frontend.

### Cliente frecuente: se abre lectura de `/clientes` y `/clientes/{cliente}/direcciones` al Despachador

`GET /clientes` (spec 06) y `GET /clientes/{cliente}/direcciones` (direcciones guardadas de un
cliente) hoy solo son accesibles para `AdminCliente`. Para poblar el select de "Cliente frecuente"
sin duplicar catálogos, se agrega el rol `Despachador` **únicamente** a esos dos `GET`, dejando el
resto de la gestión de clientes (`POST`/`PUT`/cambio de estado, y el CRUD de direcciones) exclusivo
de `AdminCliente` como hasta ahora. El Despachador gana solo lectura, no gestión.

`GET /clientes` se consume una vez al abrir el panel (o al montar `PanelView`), sin `?search=` ni
paginado adicional en el frontend: la spec de clientes ya soporta búsqueda server-side, pero esta
spec no la usa — el select carga la lista completa de clientes con `estado` activo.

### Selección de cliente frecuente: autocompletado condicionado a una única dirección guardada

Al elegir un cliente en el select:

- `nombre_solicitante` y `telefono_solicitante` se autocompletan siempre con `Cliente.nombre` y
  `Cliente.telefono` (ambos campos obligatorios en el modelo `Cliente`, así que siempre existen).
- Se pide `GET /clientes/{cliente}/direcciones`. Si la respuesta trae **exactamente una** dirección,
  se arma un texto de dirección legible a partir de sus columnas (`calle`, `numero`, `colonia`,
  `ciudad`) y se autocompleta `direccion_recogida` con ese texto; si además esa dirección tiene
  `latitud`/`longitud` guardadas (son nullable en `direcciones_clientes`), también se marca como
  "resuelta" (dispara el mismo indicador visual de check que dispara `UiAddressAutocomplete` al
  elegir una sugerencia de Google, y alimenta `UiVistaPreviaRuta`). Si la dirección guardada no
  tiene coordenadas, el texto se autocompleta pero sin check — el despachador tendría que
  reseleccionar la dirección en el autocompletado de Google para resolverla antes de que cuente para
  el cálculo del total del viaje.
- Si el cliente tiene **cero o más de una** dirección guardada, no se autocompleta ninguna dirección
  — el despachador la captura a mano, como hoy.
- La dirección de entrega **nunca** se autocompleta desde el cliente.
- Todos los campos autocompletados (nombre, teléfono, dirección de recogida) siguen siendo
  editables; el despachador puede sobrescribirlos sin que eso desvincule al cliente seleccionado del
  pedido (`id_cliente` viaja igual en el `POST`).
- Cambiar la selección del select (a otro cliente, o de vuelta a "Ninguno / solicitante ocasional")
  no limpia campos que el despachador ya haya editado a mano — solo vuelve a aplicar el
  autocompletado sobre el estado actual del formulario, igual que la primera vez.

### Indicador de dirección resuelta en `UiAddressAutocomplete`

`UiAddressAutocomplete.vue` no tiene hoy ningún estado de "encontrado": solo expone un `<input>` y
la lista de sugerencias. Se le agrega un estado interno booleano que se activa cuando se resuelve
una dirección (mismo momento en que hoy emite `select` con `lat`/`lng` no nulos) y se desactiva si el
usuario vuelve a escribir en el campo después de haber una dirección resuelta. Cuando está activo,
se muestra un ícono de check (mismo set `@iconify/vue` / `flat-color-icons` ya usado en el proyecto)
al costado del input. Aplica a **ambos** campos de dirección de esta spec (recogida y entrega); el
componente es genérico, así que cualquier otro uso futuro del proyecto también puede aprovecharlo,
pero no se modifica ningún otro consumidor existente.

### `numero_pedido` autogenerado

Se genera en el backend al crear (`PED-000001`, correlativo basado en `max(id_pedido) + 1`), no lo
captura el despachador. Es un identificador simple para uso operativo, no una regla de negocio.

### Horario: "lo antes posible" contra horario fijo, y fecha de servicio opcional

Si `lo_antes_posible = true`: `hora_desde`/`hora_hasta` pueden ir vacíos (sin cambios), y ahora
**`fecha_servicio` también es opcional** — se entiende que el servicio es para hoy. El backend, al
guardar, completa `fecha_servicio` con la fecha del servidor si no llegó ninguna. Si
`lo_antes_posible = false`, tanto `fecha_servicio` como ambas horas son obligatorias, y `hora_hasta`
debe ser posterior a `hora_desde`. Toda esta validación se hace a mano en el controlador (no con
`required_if`, para evitar el problema de comparar booleanos JSON contra el string `'false'` que usa
esa regla de Laravel) — mismo patrón que ya existía para las horas, extendido a `fecha_servicio`.

En el frontend, el campo de fecha deja de tener `required` fijo y se oculta cuando "lo antes
posible" está marcado, igual que ya pasa con los campos de hora desde/hasta.

### Por qué se elimina latitud/longitud

Las columnas `latitud_recogida`, `longitud_recogida`, `latitud_entrega` y `longitud_entrega`
(heredadas de la tabla 08 de `db/02-base-de-datos.md`) dejan de capturarse, validarse, exponerse o
guardarse: hoy no alimentan ningún cálculo de negocio persistido (el motor de asignación por radio
que las usaría está fuera de alcance), y mantenerlas como columnas muertas solo agrega superficie de
validación sin beneficio. Las coordenadas que devuelve `UiAddressAutocomplete` al seleccionar una
dirección (o las que trae una dirección guardada de un cliente, ver arriba) se mantienen en un `ref`
local del componente que las usa (no en el objeto que se envía al backend), solo para alimentar
`UiVistaPreviaRuta` y el cálculo en memoria del "total del viaje" (ver más abajo); al enviar el
formulario, ese `ref` no viaja en el payload.

Esta spec ya trae migrada (en una ronda anterior) la eliminación de esas 4 columnas de `pedidos`; no
se agrega ninguna migración nueva en esta ronda — en particular, el "total del viaje" **no** se
guarda como columna nueva (ver "Cálculo del total del viaje").

### Selector de modalidad de pago: botones en vez de `<select>`

`modalidad_pago` sigue fijando los mismos tres valores de negocio
(`RECEPTOR_PAGA_ENVIO`, `REMITENTE_PAGA_ENVIO`, `RECEPTOR_PAGA_ENVIO_PRODUCTOS`) — no cambia la
lógica, solo la interfaz de selección, que pasa de un `<select>` HTML a un grupo de 3 botones
visuales.

- **Componente** `components/ui/UiModalidadPagoSelector.vue`: recibe `modelValue` (uno de los
  tres valores) y emite `update:modelValue`, para usarse con `v-model` igual que un `<select>`.
- Estructura: `role="radiogroup"` con tres `<button type="button" role="radio">`, navegables con
  flechas de teclado igual que un radio group nativo (`ArrowLeft`/`ArrowRight` mueve la selección
  entre los tres).
- Layout responsivo: `grid grid-cols-1 sm:grid-cols-3 gap-3` — se apilan en una columna en pantallas
  angostas y quedan en fila en escritorio.
- Cada botón combina ícono + texto, usando la librería de íconos ya integrada en el proyecto
  (`@iconify/vue`, con el set curado en `assets/icon-data.json` vía
  `scripts/generate-icon-data.mjs`):

  | Valor | Botón | Íconos | Color (tokens ya definidos en el design system) |
  |---|---|---|---|
  | `RECEPTOR_PAGA_ENVIO` | 📦💰 Receptor paga envío | `flat-color-icons:package` + `flat-color-icons:paid` | `bg-info-bg` / `text-info-text` (mismo azul de `UiBadge` `color="blue"`) |
  | `REMITENTE_PAGA_ENVIO` | 💵📦 Remitente paga envío | `flat-color-icons:paid` + `flat-color-icons:package` | `bg-success-bg` / `text-success-text` (mismo verde de `UiBadge` `color="green"`) |
  | `RECEPTOR_PAGA_ENVIO_PRODUCTOS` | 🛍️💰 Receptor paga envío + producto | `flat-color-icons:shop` + `flat-color-icons:paid` | `bg-purple-bg` / `text-purple-text` (mismo morado de `UiBadge` `color="purple"`) |

- Estado de selección: el botón activo usa su color de fondo sólido (de la tabla) más
  `ring-2 ring-offset-1` en su mismo tono y `aria-checked="true"`; los botones no seleccionados se
  muestran en `bg-white` con borde `border-gray-300`, íconos en tono neutro/atenuado y
  `aria-checked="false"`. `:hover` sobre un botón no seleccionado lo resalta (`hover:bg-slate-50`)
  para dejar claro que es interactivo. Transición corta (`transition-colors`) entre estados, en
  línea con el resto del design system.
- Accesible por teclado: `Tab` entra al grupo (foco en el botón seleccionado o el primero),
  `ArrowLeft`/`ArrowRight` cambia la selección sin necesidad de `Enter`/`Espacio` adicional (patrón
  estándar de radiogroup).

### Importe de cobro condicionado a la modalidad de pago

Solo `RECEPTOR_PAGA_ENVIO_PRODUCTOS` involucra un cobro de producto además del envío; las otras dos
modalidades (`RECEPTOR_PAGA_ENVIO`, `REMITENTE_PAGA_ENVIO`) no tienen nada que cobrar aparte del
envío. Por eso:

- En el frontend, el campo "Importe de cobro" solo se muestra (`v-if`) cuando
  `form.modalidad_pago === 'RECEPTOR_PAGA_ENVIO_PRODUCTOS'`. Al cambiar a una modalidad sin
  producto, el campo se oculta y su valor en el formulario se resetea a `'0'`.
- En el backend, `validarDatos` fuerza `importe_cobro = 0` cuando `modalidad_pago` no es
  `RECEPTOR_PAGA_ENVIO_PRODUCTOS`, sin importar qué valor haya llegado en el payload — regla de
  negocio en el servidor, no solo una cuestión de qué campo se muestra en la UI.

### Cálculo del total del viaje

El "total del viaje" se muestra como información visible en el formulario, para que el despachador
lo comunique al solicitante antes de agendar. Fórmula:

```
total_viaje = tarifa_banderazo + (distancia_km × tarifa_km_adicional)
```

- `tarifa_banderazo` y `tarifa_km_adicional` son las dos tarifas globales del tenant, ya
  configurables desde la spec 015 (`GET /configuracion`, campos `tarifa_banderazo` y
  `tarifa_km_adicional`) pero que esa spec explícitamente dejó sin aplicar a ningún cálculo — esta
  es la primera spec que las usa. No existe un umbral de "kilómetros incluidos": la tarifa por
  kilómetro adicional se aplica sobre la distancia completa del trayecto.
- `distancia_km` es la distancia entre la dirección de recogida y la de entrega, calculada por
  Google Maps (Directions API) cuando ambas direcciones están resueltas (tienen coordenadas). Hoy
  `GoogleProvider.drawRoute()` solo devuelve la distancia como texto formateado por Google
  (`leg.distance.text`, p. ej. `"5.2 km"`) — se extiende para exponer también el valor numérico en
  kilómetros (`leg.distance.value`, que Google entrega en metros, dividido entre 1000), sin quitar
  el texto que ya usa `UiVistaPreviaRuta`.
- Igual que `GET /clientes`, `GET /configuracion` hoy solo es accesible para `AdminCliente`: se le
  agrega el rol `Despachador` únicamente al `GET` (lectura), no al `PUT` de edición de tarifas, que
  sigue siendo exclusivo de `AdminCliente`.
- El bloque de total solo aparece cuando hay una distancia numérica disponible (ambas direcciones
  resueltas); mientras falte alguna, no se muestra ni bloquea el formulario — no es un campo
  obligatorio, es información derivada.
- El total **no se envía** en el `POST /pedidos` ni se persiste: es una vista, no una regla de
  negocio guardada. Recalcularlo después (para reportes, por ejemplo) queda fuera de esta spec.

### Comunicación por props/emits, no por estado compartido en un módulo aparte

El botón vive en `TenantLayout.vue` (navbar) y el formulario vive en `NuevaEntregaPanel.vue`,
montado por `PanelView.vue`. En vez de un composable con estado "global", el estado "¿está abierto?"
vive en `PanelView.vue` — el único lugar que realmente lo necesita — y viaja hacia abajo como prop y
hacia arriba como evento:

- `TenantLayout.vue` no sabe si el panel está abierto; solo emite `toggle-nueva-entrega` cuando
  detecta un clic o una flecha (y solo renderiza el botón si `tenantAuth.usuario?.rol ===
  'Despachador'`), y recibe `nueva-entrega-abierta` como prop (para pintar `aria-expanded`
  correctamente). También expone (`defineExpose`) un método para que quien lo contenga pueda
  devolverle el foco a su botón.
- `PanelView.vue` es dueño del estado (`ref<boolean>`), escucha el evento de `TenantLayout`, y pasa
  el valor como prop `abierto` a `NuevaEntregaPanel.vue`.
- `NuevaEntregaPanel.vue` recibe `abierto` por prop. Al confirmar "Agendar", hace `POST /pedidos`
  directamente (mismo patrón de `http`, `fieldErrors`, `loading` que usaría cualquier formulario del
  panel), y solo tras la respuesta exitosa emite `agendado` (para que `PanelView` cierre el panel y
  devuelva el foco al botón del navbar) y `cerrar` (Cancelar/Escape, sin guardar). Los errores `422`
  del backend se muestran por campo (`fieldErrors`); un `403` (rol distinto de `Despachador`, caso
  ya cubierto por no mostrar el botón, pero posible si la sesión cambia de rol a mitad de uso)
  muestra un mensaje general y cierra el panel. La carga del catálogo de clientes (`GET /clientes`)
  y de la configuración de tarifas (`GET /configuracion`) ocurre dentro de `NuevaEntregaPanel.vue`
  al montarse, sin estado compartido adicional.

### El deslizamiento se hace con `transform`, no con `v-if`

Si el panel se montara/desmontara con `v-if` según el estado, aparecería y desaparecería de golpe,
sin animación. Por eso el panel está siempre en el DOM (dentro de `PanelView.vue`) y lo único que
cambia es su posición horizontal: `translate-x-0` cuando está abierto, `-translate-x-full` cuando
está cerrado (equivalente a moverlo exactamente su propio ancho hacia la izquierda, fuera de la
pantalla), con `transition: transform 0.4s ease-in-out`.

### Por qué tapa a `ServiciosEnTurno` en vez de convivir a su lado

El panel usa `left: 0` igual que `ServiciosEnTurno` (spec 008), pero un ancho mayor (`45%` contra el
`30%` de `ServiciosEnTurno`): al abrirse no solo coincide con su lugar, sino que se extiende más
allá. Su `z-index` va entre el de `ServiciosEnTurno` (30) y el del navbar (40): por encima de
Servicios en turno y de lo que haya a su derecha dentro de ese 45% (los tapa mientras está abierto)
pero por debajo del navbar (que siempre queda visible y utilizable).

### Por qué ocupa toda la altura del navegador y no solo debajo del navbar

A diferencia de `ServiciosEnTurno`, que empieza en `top-[4.25rem]` para no meterse debajo del
navbar, este panel usa `top: 0` y `height: 100vh`. Como su `z-index` es menor que el del navbar, el
navbar lo sigue tapando visualmente en esa franja superior — el contenido visible del formulario
arranca con suficiente espacio (`padding-top`) para no quedar oculto detrás del navbar.

## Reglas de negocio

- `id_cliente` es opcional: pedido de cliente frecuente (con `id_cliente`, seleccionado en el select
  del panel) o solicitante ocasional (`id_cliente = null`, opción "Ninguno" del select), según el
  caso de uso del spec de base de datos.
- `modalidad_pago` fija los tres casos de pago documentados
  (`RECEPTOR_PAGA_ENVIO`, `REMITENTE_PAGA_ENVIO`, `RECEPTOR_PAGA_ENVIO_PRODUCTOS`), capturados con
  el selector de botones descrito arriba.
- `importe_envio` es numérico ≥ 0, con default `0` si no llega. `importe_cobro` también es numérico
  ≥ 0 con default `0`, pero además el backend lo fuerza a `0` cuando `modalidad_pago` no es
  `RECEPTOR_PAGA_ENVIO_PRODUCTOS` (ver "Importe de cobro condicionado a la modalidad").
- `fecha_servicio` es obligatoria solo si `lo_antes_posible = false`; si es `true`, el backend la
  completa con la fecha del día si no llega ninguna.
- `id_despachador`/`id_conductor`/`id_vehiculo` son opcionales en todo momento — no se capturan en
  el panel deslizante (única superficie de creación de esta spec) y siempre viajan `null`. Asignarlos
  queda fuera de esta spec.
- Crear un pedido (`POST /pedidos`) requiere sesión de `Despachador`; `AdminCliente` y `Conductor`
  reciben `403`.

## Backend (Laravel)

- **Sin migraciones nuevas en esta ronda**: la eliminación de `latitud_recogida`,
  `longitud_recogida`, `latitud_entrega` y `longitud_entrega` de `pedidos` ya está migrada; el total
  del viaje no agrega columnas.
- **Modelo** `App\Models\Tenant\Pedido`: sin cambios en esta ronda (`$table = 'pedidos'`,
  `$primaryKey = 'id_pedido'`, `casts()` para fechas/horas/booleano/decimales, relaciones
  `belongsTo` a `Cliente`, `Despachador`, `Conductor`, `Vehiculo`).
- **Resource** `App\Http\Resources\Tenant\PedidoResource`: sin cambios (expone las columnas más
  nombres derivados de las relaciones).
- **Controlador** `App\Http\Controllers\Tenant\PedidoController`:
  - `store(Request $request)` / `validarDatos()`: `fecha_servicio` pasa de `required` fijo a
    validación manual (mismo patrón que las horas): obligatoria solo si `lo_antes_posible` es
    `false`; si es `true` y no llega, se completa con `now()->toDateString()` antes de guardar.
    `importe_cobro` se fuerza a `0` cuando `modalidad_pago` no es `RECEPTOR_PAGA_ENVIO_PRODUCTOS`,
    sin importar qué llegue en el payload. `id_cliente` sigue validándose igual
    (`nullable|integer|exists:clientes,id_cliente`). Sigue siendo el único método consumido por el
    frontend de esta spec (`NuevaEntregaPanel.vue`).
  - `index(Request $request)`, `show(Pedido $pedido)`, `update(Request $request, Pedido $pedido)`,
    `cambiarEstado(Request $request, Pedido $pedido)`: sin cambios, se conservan como capacidad de
    API sin consumidor propio en esta spec.
  - Todas las mutaciones registran `Auditoria` (`ALTA`/`EDICION`/`CAMBIO_ESTADO`) sobre
    `tabla_afectada = 'pedidos'`.
- **Rutas** (`routes/api.php`): se agrega el rol `Despachador` a las rutas de solo lectura de
  clientes y configuración; el resto de esos módulos sigue exclusivo de `AdminCliente`.

  ```php
  // Bloque exclusivo de AdminCliente: gestión de clientes, direcciones y configuración
  Route::middleware(['throttle:tenant-usuarios', 'rol.tenant:AdminCliente'])->group(function () {
      Route::post('/clientes', [ClienteController::class, 'store']);
      Route::put('/clientes/{cliente}', [ClienteController::class, 'update']);
      Route::patch('/clientes/{cliente}/estado', [ClienteController::class, 'cambiarEstado']);

      Route::post('/clientes/{cliente}/direcciones', [DireccionClienteController::class, 'store']);
      Route::put('/clientes/{cliente}/direcciones/{direccion}', [DireccionClienteController::class, 'update']);
      Route::delete('/clientes/{cliente}/direcciones/{direccion}', [DireccionClienteController::class, 'destroy']);

      Route::put('/configuracion', [ConfiguracionController::class, 'update']);

      // ... resto de rutas exclusivas de AdminCliente sin cambios (usuarios, despachadores,
      // conductores, vehículos, conductor-vehiculo, zonas-cobertura, show/index de clientes y
      // direcciones si ya vivían aquí antes de esta ronda)
  });

  // Lectura de clientes/configuración: ahora también accesible al Despachador (para el select de
  // "Cliente frecuente" y el cálculo del total del viaje)
  Route::middleware(['throttle:tenant-usuarios', 'rol.tenant:AdminCliente,Despachador'])->group(function () {
      Route::get('/clientes', [ClienteController::class, 'index']);
      Route::get('/clientes/{cliente}', [ClienteController::class, 'show']);
      Route::get('/clientes/{cliente}/direcciones', [DireccionClienteController::class, 'index']);
      Route::get('/clientes/{cliente}/direcciones/{direccion}', [DireccionClienteController::class, 'show']);

      Route::get('/configuracion', [ConfiguracionController::class, 'show']);

      Route::get('/pedidos', [PedidoController::class, 'index']);
      Route::get('/pedidos/{pedido}', [PedidoController::class, 'show']);
      Route::put('/pedidos/{pedido}', [PedidoController::class, 'update']);
      Route::patch('/pedidos/{pedido}/estado', [PedidoController::class, 'cambiarEstado']);
  });

  // Creación: exclusiva de Despachador
  Route::middleware(['throttle:tenant-usuarios', 'rol.tenant:Despachador'])->group(function () {
      Route::post('/pedidos', [PedidoController::class, 'store']);
  });
  ```

  `GET /pedidos/recursos` sigue sin existir (eliminado en la ronda anterior, sin consumidor).

## Frontend (Vue 3)

- **`components/ui/UiAddressAutocomplete.vue`**: agrega un estado interno de "dirección resuelta"
  que se activa al emitir `select` con coordenadas no nulas y se desactiva si el usuario vuelve a
  escribir; cuando está activo, muestra un ícono de check junto al input. Se usa en ambos campos de
  dirección del panel (recogida y entrega).
- **`components/ui/UiModalidadPagoSelector.vue`**: sin cambios en esta ronda.
- **`services/maps/types.ts`**: `RouteResult` gana un campo numérico `distanceKm: number` junto al
  `distance`/`duration` de texto ya existentes.
- **`services/maps/BaseProvider.ts`** / **`GoogleProvider.ts`**: `drawRoute()` calcula `distanceKm`
  a partir de `leg.distance.value` (metros, devuelto por Directions API) dividido entre 1000, sin
  quitar los campos de texto que ya usa `UiVistaPreviaRuta`.
- **`layouts/TenantLayout.vue`**: sin cambios en esta ronda (prop `nuevaEntregaAbierta`, evento
  `toggle-nueva-entrega`, visibilidad condicionada a ruta y rol, foco automático,
  `focusNuevaEntrega()` expuesto).
- **`views/tenant/panel/PanelView.vue`**: sin cambios en esta ronda.
- **`components/panel/NuevaEntregaPanel.vue`**:
  - Nuevo campo `id_cliente` en el formulario (select "Cliente frecuente", opcional, primera opción
    "Ninguno / solicitante ocasional"), poblado con `GET /clientes` (clientes activos) al montar el
    componente.
  - Al cambiar la selección del cliente: autocompleta `nombre_solicitante`/`telefono_solicitante`
    siempre; pide `GET /clientes/{id}/direcciones` y, si hay exactamente una, autocompleta
    `direccion_recogida` (y sus coordenadas si la dirección las tiene) — ver "Selección de cliente
    frecuente" en Decisión técnica.
  - Nombre y teléfono del solicitante pasan de `grid grid-cols-1 gap-4` a
    `grid grid-cols-1 sm:grid-cols-2 gap-4`.
  - `fecha_servicio`: el `<input type="date">` deja el atributo `required` fijo; se envuelve en
    `v-if="!form.lo_antes_posible"`, igual que ya pasa con las horas.
  - `importe_cobro`: se envuelve en
    `v-if="form.modalidad_pago === 'RECEPTOR_PAGA_ENVIO_PRODUCTOS'"`; al ocultarse, su valor vuelve
    a `'0'`.
  - Nuevo bloque "Total del viaje" después de importe de envío/cobro: se calcula en el propio
    componente (sin store ni composable con estado compartido) a partir de `distanceKm` (obtenido de
    `UiVistaPreviaRuta`/`drawRoute` cuando ambas direcciones están resueltas) y las tarifas leídas de
    `GET /configuracion` al montar. Solo se muestra cuando hay `distanceKm` disponible.
  - Sigue recibiendo `abierto` por prop, haciendo `POST /pedidos` al agendar (el payload sigue sin
    incluir el total calculado ni coordenadas) y emitiendo `agendado`/`cerrar`.
- **No existen** `ListaPedidosView.vue`, `CrearPedidoView.vue` ni `EditarPedidoView.vue`, ni las
  rutas `/t/:slug/panel/pedidos`, `/t/:slug/panel/pedidos/crear`, `/t/:slug/panel/pedidos/:id/editar`,
  ni un ítem "Pedidos" en el menú lateral de `TenantLayout.vue`.

## Fuera de alcance

- Listado, edición y cambio de estado de un pedido ya creado, vía cualquier UI — el backend los
  soporta (`index`/`show`/`update`/`cambiarEstado`), pero ninguna vista de esta spec los expone.
  Dónde vive esa funcionalidad (¿`ServiciosEnTurno`? ¿una spec nueva?) queda pendiente de definir.
- Tablas `pedido_asignaciones` y `pedido_estados` (bitácora e historial de intentos de asignación).
- Motor de asignación automática (radio de búsqueda, expiración, oferta al siguiente conductor).
- Validación de disponibilidad/conflicto de horario al asignar conductor o vehículo.
- Interacción del conductor con el pedido (app móvil).
- Selects de despachador/conductor/vehículo, así como latitud/longitud, en la creación — las
  coordenadas son solo vista previa local y entrada del cálculo del total del viaje, nunca se
  envían ni se guardan.
- Buscador o paginado en el select de "Cliente frecuente" — carga la lista completa de clientes
  activos.
- Elegir manualmente entre varias direcciones guardadas de un cliente cuando tiene más de una — solo
  se autocompleta cuando hay exactamente una.
- Persistir el "total del viaje" calculado, o cualquier campo de distancia, en el pedido — es
  información derivada, solo visible en el formulario.
- Umbral de kilómetros incluidos en la tarifa de banderazo — la tarifa por kilómetro adicional se
  aplica sobre toda la distancia del trayecto.
- Gestión de clientes o de sus direcciones desde el panel de Despachador — el nuevo acceso es de
  solo lectura (`GET`), para poblar el select; crear/editar clientes o direcciones sigue siendo
  exclusivo de `AdminCliente` en su propia pantalla.
- Mostrar el botón "Nueva Entrega" en otras páginas del tenant fuera de `/panel`, o para roles
  distintos de `Despachador`.
- Cualquier estado compartido en un módulo aparte o store de Pinia dedicada — el esquema de
  props/emits alcanza.
- Layout específico para mobile en el resto del panel deslizante (fuera del selector de modalidad
  de pago y de la fila de nombre/teléfono, que sí son responsivos por requisito explícito de esta
  spec).

## Criterios de aceptación

1. `POST /api/v1/t/{slug}/pedidos` sin sesión responde `401`; con sesión de `Conductor` o
   `AdminCliente` responde `403`; con `Despachador` responde `201`.
2. `POST` sin campos requeridos responde `422`.
3. `POST` con `lo_antes_posible: false` y sin `hora_desde`/`hora_hasta` responde `422`; también
   responde `422` si `lo_antes_posible: false` y no llega `fecha_servicio`.
4. `POST` con `lo_antes_posible: true` y sin `fecha_servicio` responde `201` y el pedido creado
   queda con `fecha_servicio` igual a la fecha del día.
5. `POST` con `modalidad_pago` distinta de `RECEPTOR_PAGA_ENVIO_PRODUCTOS` y con `importe_cobro`
   distinto de cero en el payload responde `201`, pero el pedido creado queda con
   `importe_cobro = 0`.
6. `POST` con datos válidos crea el pedido con `numero_pedido` autogenerado (`PED-######`),
   `estado: PENDIENTE`, sin columnas de latitud/longitud, `id_despachador`/`id_conductor`/
   `id_vehiculo` en `null`, y registra `Auditoria` con `accion = ALTA`.
7. `GET /pedidos/recursos` no existe (`404`).
8. `GET /clientes`, `GET /clientes/{cliente}` y `GET /clientes/{cliente}/direcciones` responden
   `200` con sesión de `Despachador` (antes solo `AdminCliente`); `POST`/`PUT`/`PATCH` sobre
   clientes o direcciones siguen respondiendo `403` con sesión de `Despachador`.
9. `GET /configuracion` responde `200` con sesión de `Despachador`; `PUT /configuracion` sigue
   respondiendo `403` con sesión de `Despachador`.
10. En `/t/{slug}/panel`, el navbar muestra el botón "Nueva Entrega" solo cuando la sesión activa es
    de `Despachador`; con sesión de `AdminCliente` en la misma ruta, el botón no aparece.
11. Ninguna ruta del frontend expone `/t/:slug/panel/pedidos` (ni `/crear` ni `/:id/editar`); el
    menú lateral no incluye un ítem "Pedidos".
12. Al activar el botón, el panel se desliza desde la izquierda hasta cubrir el 45% del ancho del
    viewport, tapando a `ServiciosEnTurno` (30%) y la franja adicional a su derecha, con una
    transición de 0.4s; ocupa toda la altura del navegador, con el navbar visible por encima.
13. Activar el botón de nuevo, o "Cancelar", o `Escape` con el foco dentro del formulario, desliza el
    panel de regreso fuera de la pantalla y devuelve el foco al botón del navbar.
14. Con el foco en el botón, `ArrowRight`/`ArrowDown` abre el panel y mueve el foco al primer campo.
15. El formulario muestra un select "Cliente frecuente" poblado con los clientes activos del tenant.
    Elegir uno autocompleta nombre y teléfono del solicitante; si ese cliente tiene exactamente una
    dirección guardada, también autocompleta la dirección de recogida; si tiene cero o varias, la
    dirección de recogida no se toca. Los campos autocompletados siguen siendo editables.
16. Nombre del solicitante y teléfono del solicitante se muestran en una misma fila en escritorio.
17. Al resolver una dirección (recogida o entrega) contra Google Maps, aparece un ícono de check
    junto al campo correspondiente; si el usuario vuelve a editar el texto, el ícono desaparece.
18. Con "lo antes posible" marcado (default), el campo de fecha de servicio no se muestra y no es
    obligatorio; al desmarcarlo, el campo de fecha aparece y vuelve a ser obligatorio, junto con
    ambas horas.
19. En el formulario, `modalidad_pago` se captura con `UiModalidadPagoSelector`: 3 botones con
    ícono, texto y color diferenciado por opción; el botón activo muestra un estado de selección
    claro (`aria-checked="true"`, fondo sólido y anillo); `ArrowLeft`/`ArrowRight` mueve la
    selección entre los tres; el grupo se ve y usa bien tanto en escritorio (fila) como en móvil
    (columna).
20. El campo "Importe de cobro" solo se muestra cuando la modalidad de pago es "Receptor paga envío
    + producto"; al cambiar a cualquiera de las otras dos modalidades, el campo se oculta.
21. Cuando ambas direcciones están resueltas, se muestra un bloque "Total del viaje" con el importe
    calculado (`tarifa_banderazo + distancia_km × tarifa_km_adicional`, usando las tarifas
    configuradas del tenant); mientras falte alguna dirección, el bloque no se muestra y el
    formulario no se bloquea por eso.
22. El formulario no muestra ni envía campos de latitud/longitud ni el total del viaje calculado;
    `UiVistaPreviaRuta` sigue funcionando con coordenadas obtenidas solo del autocompletado o de una
    dirección guardada del cliente, sin persistirlas.
23. "Agendar" con horario fijo y sin ambas horas, o sin fecha, o con "hasta" antes de "desde",
    muestra un error y no cierra el panel; con datos válidos, crea el pedido vía `POST /pedidos`,
    limpia el formulario y cierra el panel.
24. Pint y ESLint/Prettier corren sin errores; `php artisan test` pasa.

## Supuestos asumidos (registro completo)

1. Alcance limitado a la creación (tabla 08) — listado, edición, cambio de estado, tablas 09/10 y el
   motor de asignación automática quedan para specs futuras.
2. No existe menú "Pedidos", ni `ListaPedidosView.vue`, `CrearPedidoView.vue` ni
   `EditarPedidoView.vue` como páginas propias — decisión de producto, no una omisión temporal.
3. La creación de pedidos es exclusiva del rol `Despachador`; `AdminCliente` pierde el acceso que
   tenía en la versión anterior de esta spec (ya no puede crear pedidos por ningún medio dentro de
   este alcance).
4. `GET /pedidos/recursos` se elimina por no tener consumidor. El select de "Cliente frecuente" de
   esta ronda usa `GET /clientes` (spec 06), no revive `recursos()`.
5. Los endpoints `index`/`show`/`update`/`cambiarEstado` se conservan en el backend tal como estaban
   en la spec original, aunque hoy no los consume ninguna vista — se asume que son la base para una
   funcionalidad futura, no código muerto a eliminar.
6. `numero_pedido` autogenerado por el backend, formato `PED-######`.
7. Sin `DELETE` — un pedido se cancela, no se borra (aunque el cambio de estado en sí queda fuera de
   la UI de esta spec).
8. El botón "Nueva Entrega" vive en el navbar (`UiNavbar`, slot de acciones), no dentro de la
   página del Panel, y solo se muestra cuando la ruta activa es `/panel` **y** el rol de la sesión
   es `Despachador`.
9. La comunicación entre el botón (navbar) y el formulario se hace con props/emits a través del
   árbol de componentes (`TenantLayout` ⇄ `PanelView` ⇄ `NuevaEntregaPanel`), sin ningún estado
   compartido en un módulo aparte.
10. El panel deslizante (`transform: translateX`, transición `0.4s ease-in-out`) cubre el 45% del
    viewport al abrirse, superponiéndose a `ServiciosEnTurno` y a la franja adicional a su derecha,
    ocupando toda la altura del navegador (el navbar se mantiene visualmente por encima).
11. El foco inicial al entrar a `/panel` como Despachador se posiciona sobre el botón del navbar; no
    hay tratamiento especial para pantallas angostas en el resto del panel deslizante.
12. Latitud/longitud se elimina de formulario, validación, modelo, resource, API y tabla; las
    coordenadas que devuelve el autocompletado de direcciones (o una dirección guardada de cliente
    con coordenadas) se mantienen solo como estado local del componente, para alimentar la vista
    previa de ruta y el cálculo en memoria del total del viaje — nunca se envían al backend.
13. `UiModalidadPagoSelector.vue` es un componente reutilizable, usa íconos ya soportados por el
    proyecto (`@iconify/vue` + colección `flat-color-icons`) y los mismos tokens de color que ya usa
    `UiBadge` (`info`/`success`/`purple`), sin introducir una paleta nueva.
14. El requisito de verse bien "en escritorio y en móvil" aplica al selector de modalidad de pago y
    a la fila de nombre/teléfono del solicitante; no implica revisar ni corregir el resto del layout
    mobile del panel deslizante.
15. El select de "Cliente frecuente" solo lista clientes con `estado` activo, carga la lista
    completa (sin buscador ni paginado en el frontend), y es opcional — "Ninguno / solicitante
    ocasional" mantiene `id_cliente = null`.
16. El autocompletado de dirección de recogida a partir de un cliente frecuente solo ocurre cuando
    ese cliente tiene **exactamente una** dirección guardada; con cero o varias, no se autocompleta
    ninguna dirección. La dirección de entrega nunca se autocompleta desde el cliente. Todos los
    campos autocompletados (nombre, teléfono, dirección) siguen siendo editables.
17. El Despachador gana acceso de **solo lectura** a `GET /clientes`, `GET /clientes/{cliente}`,
    `GET /clientes/{cliente}/direcciones(/{direccion})` y `GET /configuracion` — la gestión
    (crear/editar/cambiar estado) de clientes, direcciones y tarifas sigue siendo exclusiva de
    `AdminCliente`.
18. El indicador visual de "dirección resuelta" (ícono de check) aplica tanto a dirección de
    recogida como de entrega, se agrega como estado interno de `UiAddressAutocomplete.vue`
    (reutilizable por otros consumidores futuros), y se desactiva si el usuario vuelve a editar el
    texto del campo.
19. `fecha_servicio` es obligatoria solo si `lo_antes_posible = false`; si es `true` y no llega, el
    backend la completa con la fecha del día del servidor al guardar. El campo se oculta en el
    frontend cuando "lo antes posible" está marcado.
20. `importe_cobro` solo se captura (frontend) y se respeta (backend) cuando `modalidad_pago` es
    `RECEPTOR_PAGA_ENVIO_PRODUCTOS`; en cualquier otro caso queda forzado a `0` tanto en la UI como
    en el servidor, sin importar qué llegue en el payload.
21. El "total del viaje" se calcula como `tarifa_banderazo + distancia_km × tarifa_km_adicional`,
    sin umbral de kilómetros incluidos, usando las tarifas configurables de la spec 015 (que esa
    spec dejó explícitamente sin aplicar) y la distancia numérica entre recogida y entrega obtenida
    de Google Directions API (se extiende `RouteResult`/`GoogleProvider` para exponer `distanceKm`
    además del texto ya existente). Es puramente informativo: no se envía en el `POST /pedidos` ni
    se persiste, y no requiere ninguna migración nueva.
</content>
