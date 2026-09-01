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

## Historia de usuario

Como Despachador, quiero un acceso rápido desde el Panel para registrar una nueva entrega sin
perder de vista el resto de la pantalla, para agendar pedidos con fricción mínima durante mi turno.

## Objetivo / Alcance

Cubre la tabla 08 (`pedidos`) de `db/02-base-de-datos.md`, la tabla central del sistema operativo,
limitado a la **creación** de un pedido. Depende de `clientes` (06), `despachadores` (02),
`conductores` (03) y `vehiculos` (04), ya implementadas, aunque esta spec no expone selects para
ellas (ver más abajo).

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
- El formulario incluye: nombre y teléfono del solicitante, dirección de recogida, dirección de
  entrega, fecha de servicio, horario ("lo antes posible" u hora desde/hasta, con la misma
  validación de la spec 006 original: si no es "lo antes posible", ambas horas son obligatorias y
  "hasta" debe ser posterior a "desde"), modalidad de pago (ver "Selector de modalidad de pago" en
  Decisión técnica), importe de envío e importe de cobro; y el botón "Agendar".
- Las direcciones se capturan con `UiAddressAutocomplete` y se muestran en un mapa de vista previa
  (`UiVistaPreviaRuta`), pero **las coordenadas nunca se envían ni se guardan** — ver "Por qué se
  elimina latitud/longitud".
- "Agendar" con datos válidos hace `POST /pedidos` contra el backend real: crea el pedido, limpia el
  formulario y cierra el panel. "Cancelar" cierra sin guardar.

**No** incluye (por ahora):

- Ningún menú, listado ni formulario de edición de pedidos — ver "Fuera de alcance".
- Selects de cliente/despachador/conductor/vehículo, ni latitud/longitud — quedan fuera de esta
  primera versión (los tres primeros porque dependerían de catálogos vía API que ya no se exponen;
  latitud/longitud porque no alimentan ningún cálculo de negocio todavía).
- Tratamiento específico para pantallas angostas (mobile) en el layout general del panel deslizante
  — mismo límite conocido que el resto del Panel de Despachador (`ServiciosEnTurno` tampoco lo
  tiene). El selector de modalidad de pago es la única pieza de esta spec con requisito explícito de
  verse bien en móvil (ver esa sección).

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
consumidor y se elimina.

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

### `numero_pedido` autogenerado

Se genera en el backend al crear (`PED-000001`, correlativo basado en `max(id_pedido) + 1`), no lo
captura el despachador. Es un identificador simple para uso operativo, no una regla de negocio.

### Horario: "lo antes posible" contra horario fijo

Si `lo_antes_posible = true`, `hora_desde`/`hora_hasta` pueden ir vacíos. Si es `false`, ambos son
obligatorios y `hora_hasta` debe ser posterior a `hora_desde`. Se valida a mano en el controlador
(no con `required_if`, para evitar el problema de comparar booleanos JSON contra el string `'false'`
que usa esa regla de Laravel).

### Por qué se elimina latitud/longitud

Las columnas `latitud_recogida`, `longitud_recogida`, `latitud_entrega` y `longitud_entrega`
(heredadas de la tabla 08 de `db/02-base-de-datos.md`) dejan de capturarse, validarse, exponerse o
guardarse: hoy no alimentan ningún cálculo de negocio (el motor de asignación por radio que las
usaría está fuera de alcance), y mantenerlas como columnas muertas solo agrega superficie de
validación sin beneficio. Las coordenadas que devuelve `UiAddressAutocomplete` al seleccionar una
dirección se guardan en un `ref` local del componente (no en el objeto que se envía al backend),
solo para alimentar `UiVistaPreviaRuta` (la vista previa de ruta en el mapa); al enviar el
formulario, ese `ref` no viaja en el payload.

Requiere una migración que elimine esas 4 columnas de `pedidos`.

### Selector de modalidad de pago: botones en vez de `<select>`

`modalidad_pago` sigue fijando los mismos tres valores de negocio
(`RECEPTOR_PAGA_ENVIO`, `REMITENTE_PAGA_ENVIO`, `RECEPTOR_PAGA_ENVIO_PRODUCTOS`) — no cambia la
lógica, solo la interfaz de selección, que pasa de un `<select>` HTML a un grupo de 3 botones
visuales.

- **Componente nuevo** `components/ui/UiModalidadPagoSelector.vue`: recibe `modelValue` (uno de los
  tres valores) y emite `update:modelValue`, para usarse con `v-model` igual que un `<select>`.
- Estructura: `role="radiogroup"` con tres `<button type="button" role="radio">`, navegables con
  flechas de teclado igual que un radio group nativo (`ArrowLeft`/`ArrowRight` mueve la selección
  entre los tres).
- Layout responsivo: `grid grid-cols-1 sm:grid-cols-3 gap-3` — se apilan en una columna en pantallas
  angostas y quedan en fila en escritorio (única pieza de esta spec con requisito explícito de
  mobile, distinto del resto del panel deslizante).
- Cada botón combina ícono + texto, usando la librería de íconos ya integrada en el proyecto
  (`@iconify/vue`, con el set curado en `assets/icon-data.json` vía
  `scripts/generate-icon-data.mjs` — no se agrega ninguna librería nueva, solo se suman 2 nombres de
  ícono ya existentes en la colección `flat-color-icons` que el proyecto ya usa: `paid` y `shop`, al
  lado de `package` que ya está curado):

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
  muestra un mensaje general y cierra el panel.

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

- `id_cliente` es opcional: pedido de cliente frecuente (con `id_cliente`) o solicitante ocasional
  (`id_cliente = null`), según el caso de uso del spec de base de datos. El panel deslizante no lo
  captura (no hay select de cliente en esta spec), así que siempre viaja `null`.
- `modalidad_pago` fija los tres casos de pago documentados
  (`RECEPTOR_PAGA_ENVIO`, `REMITENTE_PAGA_ENVIO`, `RECEPTOR_PAGA_ENVIO_PRODUCTOS`), capturados con
  el selector de botones descrito arriba.
- `importe_envio`/`importe_cobro` son numéricos ≥ 0, con default `0` si no llegan.
- `id_despachador`/`id_conductor`/`id_vehiculo` son opcionales en todo momento — no se capturan en
  el panel deslizante (única superficie de creación de esta spec) y siempre viajan `null`. Asignarlos
  queda fuera de esta spec.
- Crear un pedido (`POST /pedidos`) requiere sesión de `Despachador`; `AdminCliente` y `Conductor`
  reciben `403`.

## Backend (Laravel)

- **Migración nueva**: elimina `latitud_recogida`, `longitud_recogida`, `latitud_entrega` y
  `longitud_entrega` de `pedidos`.
- **Modelo** `App\Models\Tenant\Pedido`: `$table = 'pedidos'`, `$primaryKey = 'id_pedido'`,
  `casts()` para fechas/horas/booleano/decimales (sin casts de latitud/longitud), relaciones
  `belongsTo` a `Cliente`, `Despachador`, `Conductor`, `Vehiculo`.
- **Resource** `App\Http\Resources\Tenant\PedidoResource`: expone las columnas más nombres
  derivados de las relaciones (`cliente_nombre`, `despachador_nombre`, `conductor_nombre`,
  `vehiculo_placa`) — sin latitud/longitud.
- **Controlador** `App\Http\Controllers\Tenant\PedidoController`:
  - `store(Request $request)`: valida (sin reglas de latitud/longitud, sin `recursos()` porque no
    hay selects que poblar), autogenera `numero_pedido`, fuerza `estado = PENDIENTE`. Único método
    consumido por el frontend de esta spec (`NuevaEntregaPanel.vue`).
  - `index(Request $request)`, `show(Pedido $pedido)`, `update(Request $request, Pedido $pedido)`,
    `cambiarEstado(Request $request, Pedido $pedido)`: se conservan como capacidad de API (misma
    lógica que la spec original: búsqueda/paginado, edición bloqueada en estados finales, máquina de
    estados vía `PedidoController::TRANSICIONES` con sellado automático de fechas), pero **sin
    consumidor propio en esta spec** — ninguna vista de esta spec los llama. Quedan disponibles para
    lo que los reemplace (ver "Fuera de alcance").
  - Todas las mutaciones registran `Auditoria` (`ALTA`/`EDICION`/`CAMBIO_ESTADO`) sobre
    `tabla_afectada = 'pedidos'`.
- **Rutas** (`routes/api.php`):

  ```php
  // Creación: exclusiva de Despachador
  Route::middleware('rol.tenant:Despachador')->group(function () {
      Route::post('/pedidos', [PedidoController::class, 'store']);
  });

  // Resto de la API de pedidos: se conserva para consumidores futuros
  Route::middleware('rol.tenant:AdminCliente,Despachador')->group(function () {
      Route::get('/pedidos', [PedidoController::class, 'index']);
      Route::get('/pedidos/{pedido}', [PedidoController::class, 'show']);
      Route::put('/pedidos/{pedido}', [PedidoController::class, 'update']);
      Route::patch('/pedidos/{pedido}/estado', [PedidoController::class, 'cambiarEstado']);
  });
  ```

  `GET /pedidos/recursos` se elimina (sin consumidor).

## Frontend (Vue 3)

- **`assets/icon-data.json`** / **`scripts/generate-icon-data.mjs`**: se agregan `paid` y `shop` a
  `FLAT_COLOR_ICON_NAMES` y se regenera el archivo (`npm run icons:build`). Ninguna librería nueva.
- **`components/ui/UiModalidadPagoSelector.vue`** (nuevo): grupo de 3 botones descrito en
  "Selector de modalidad de pago" arriba, con `v-model`.
- **`layouts/TenantLayout.vue`**: prop `nuevaEntregaAbierta?: boolean` (default `false`) y evento
  `toggle-nueva-entrega`. Dentro del slot `actions` de `UiNavbar`, el botón "Nueva Entrega" se
  muestra solo cuando `route.name === 'tenant-panel'` **y** el usuario autenticado tiene rol
  `Despachador`; al hacer clic emite el evento, y `ArrowRight`/`ArrowDown` con el foco en el botón
  también lo emite (si estaba cerrado). Hace foco automático sobre su propio botón al montar, si la
  ruta activa es `tenant-panel` y el rol es `Despachador`. Expone `focusNuevaEntrega()` vía
  `defineExpose`.
- **`views/tenant/panel/PanelView.vue`**: `ref<boolean>` local (`nuevaEntregaAbierta`), referencia
  al propio `TenantLayout` para llamar a `focusNuevaEntrega()`, y conecta: pasa
  `:nueva-entrega-abierta` y escucha `@toggle-nueva-entrega` hacia `TenantLayout`; pasa `:abierto` y
  escucha `@cerrar`/`@agendado` hacia `NuevaEntregaPanel` (ambos eventos cierran el panel y
  devuelven el foco al botón del navbar).
- **`components/panel/NuevaEntregaPanel.vue`**: `<aside>` de posición fija
  (`fixed left-0 top-0 h-screen w-[45%]`, `z-[35]`, `transition-transform duration-[400ms]
  ease-in-out`, clase `translate-x-0`/`-translate-x-full` según la prop `abierto`), con el formulario
  descrito en "Objetivo/Alcance" (sin latitud/longitud, con `UiModalidadPagoSelector`, sin selects de
  cliente/despachador/conductor/vehículo). Recibe `abierto` por prop, hace `POST /pedidos` al
  agendar y emite `agendado`/`cerrar`.
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
- Selects de cliente/despachador/conductor/vehículo, así como latitud/longitud, en la creación —
  las coordenadas son solo vista previa local, nunca se envían.
- Mostrar el botón "Nueva Entrega" en otras páginas del tenant fuera de `/panel`, o para roles
  distintos de `Despachador`.
- Cualquier estado compartido en un módulo aparte o store de Pinia dedicada — el esquema de
  props/emits alcanza.
- Layout específico para mobile en el resto del panel deslizante (fuera del selector de modalidad
  de pago, que sí es responsivo por requisito explícito de esta spec).

## Criterios de aceptación

1. `POST /api/v1/t/{slug}/pedidos` sin sesión responde `401`; con sesión de `Conductor` o
   `AdminCliente` responde `403`; con `Despachador` responde `201`.
2. `POST` sin campos requeridos responde `422`.
3. `POST` con `lo_antes_posible: false` y sin `hora_desde`/`hora_hasta` responde `422`.
4. `POST` con datos válidos crea el pedido con `numero_pedido` autogenerado (`PED-######`),
   `estado: PENDIENTE`, sin columnas de latitud/longitud, `id_despachador`/`id_conductor`/
   `id_vehiculo`/`id_cliente` en `null`, y registra `Auditoria` con `accion = ALTA`.
5. `GET /pedidos/recursos` no existe (`404`).
6. En `/t/{slug}/panel`, el navbar muestra el botón "Nueva Entrega" solo cuando la sesión activa es
   de `Despachador`; con sesión de `AdminCliente` en la misma ruta, el botón no aparece.
7. Ninguna ruta del frontend expone `/t/:slug/panel/pedidos` (ni `/crear` ni `/:id/editar`); el
   menú lateral no incluye un ítem "Pedidos".
8. Al activar el botón, el panel se desliza desde la izquierda hasta cubrir el 45% del ancho del
   viewport, tapando a `ServiciosEnTurno` (30%) y la franja adicional a su derecha, con una
   transición de 0.4s; ocupa toda la altura del navegador, con el navbar visible por encima.
9. Activar el botón de nuevo, o "Cancelar", o `Escape` con el foco dentro del formulario, desliza el
   panel de regreso fuera de la pantalla y devuelve el foco al botón del navbar.
10. Con el foco en el botón, `ArrowRight`/`ArrowDown` abre el panel y mueve el foco al primer campo.
11. En el formulario, `modalidad_pago` se captura con `UiModalidadPagoSelector`: 3 botones con
    ícono, texto y color diferenciado por opción; el botón activo muestra un estado de selección
    claro (`aria-checked="true"`, fondo sólido y anillo); `ArrowLeft`/`ArrowRight` mueve la
    selección entre los tres; el grupo se ve y usa bien tanto en escritorio (fila) como en móvil
    (columna).
12. El formulario no muestra ni envía campos de latitud/longitud; `UiVistaPreviaRuta` sigue
    funcionando con coordenadas obtenidas solo del autocompletado, sin persistirlas.
13. "Agendar" con horario fijo y sin ambas horas, o con "hasta" antes de "desde", muestra un error y
    no cierra el panel; con datos válidos, crea el pedido vía `POST /pedidos`, limpia el formulario
    y cierra el panel.
14. Pint y ESLint/Prettier corren sin errores; `php artisan test` pasa.

## Supuestos asumidos (registro completo)

1. Alcance limitado a la creación (tabla 08) — listado, edición, cambio de estado, tablas 09/10 y el
   motor de asignación automática quedan para specs futuras.
2. No existe menú "Pedidos", ni `ListaPedidosView.vue`, `CrearPedidoView.vue` ni
   `EditarPedidoView.vue` como páginas propias — decisión de producto, no una omisión temporal.
3. La creación de pedidos es exclusiva del rol `Despachador`; `AdminCliente` pierde el acceso que
   tenía en la versión anterior de esta spec (ya no puede crear pedidos por ningún medio dentro de
   este alcance).
4. `GET /pedidos/recursos` se elimina por no tener consumidor (no hay selects de
   cliente/despachador/conductor/vehículo en el panel deslizante).
5. Los endpoints `index`/`show`/`update`/`cambiarEstado` se conservan en el backend tal como estaban
   en la spec original (mismas reglas: búsqueda/paginado, edición bloqueada en estados finales,
   máquina de estados con sellado de fechas), aunque hoy no los consume ninguna vista — se asume que
   son la base para una funcionalidad futura (p.ej. gestión de pedidos desde `ServiciosEnTurno`),
   no código muerto a eliminar.
6. Se agrega una migración nueva para eliminar `latitud_recogida`, `longitud_recogida`,
   `latitud_entrega` y `longitud_entrega` de `pedidos`.
7. `numero_pedido` autogenerado por el backend, formato `PED-######`.
8. Sin `DELETE` — un pedido se cancela, no se borra (aunque el cambio de estado en sí queda fuera de
   la UI de esta spec).
9. Horario obligatorio (`hora_desde` < `hora_hasta`) solo cuando `lo_antes_posible = false`.
10. El botón "Nueva Entrega" vive en el navbar (`UiNavbar`, slot de acciones), no dentro de la
    página del Panel, y solo se muestra cuando la ruta activa es `/panel` **y** el rol de la sesión
    es `Despachador`.
11. La comunicación entre el botón (navbar) y el formulario se hace con props/emits a través del
    árbol de componentes (`TenantLayout` ⇄ `PanelView` ⇄ `NuevaEntregaPanel`), sin ningún estado
    compartido en un módulo aparte.
12. El panel deslizante (`transform: translateX`, transición `0.4s ease-in-out`) cubre el 45% del
    viewport al abrirse, superponiéndose a `ServiciosEnTurno` y a la franja adicional a su derecha,
    ocupando toda la altura del navegador (el navbar se mantiene visualmente por encima).
13. El foco inicial al entrar a `/panel` como Despachador se posiciona sobre el botón del navbar; no
    hay tratamiento especial para pantallas angostas en el resto del panel deslizante.
14. Latitud/longitud se elimina de formulario, validación, modelo, resource, API y migración; las
    coordenadas que devuelve el autocompletado de direcciones se mantienen solo como estado local
    del componente, exclusivamente para alimentar la vista previa de ruta en el mapa.
15. `UiModalidadPagoSelector.vue` es un componente nuevo y reutilizable, usa íconos ya soportados
    por el proyecto (`@iconify/vue` + colección `flat-color-icons`, sumando `paid` y `shop` al set
    curado existente) y los mismos tokens de color que ya usa `UiBadge` (`info`/`success`/`purple`),
    sin introducir una paleta nueva.
16. El requisito de verse bien "en escritorio y en móvil" aplica específicamente al selector de
    modalidad de pago; no implica revisar ni corregir el resto del layout mobile del panel
    deslizante.
