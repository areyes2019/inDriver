# Spec: Botón "Nueva Entrega" (panel deslizante de agendamiento rápido)

> **Spec abierta**: va a seguir cambiando (por ejemplo, cuando se conecte a un endpoint real de
> creación de pedido). No cerrarla ni renumerarla al agregar contenido nuevo — misma convención que
> `tenant/007-panel-despachador.md`.

## Historia de usuario

Como Despachador, quiero un acceso rápido desde el Panel para registrar una nueva entrega sin
perder de vista el resto de la pantalla, para agendar pedidos con fricción mínima durante mi turno.

## Objetivo / Alcance

Agrega un botón "Nueva Entrega" en el navbar de `/t/{slug}/panel` que abre un panel deslizante con
un formulario de agendamiento. El formulario reutiliza el subconjunto de campos de creación de
pedido ya definido para el Panel de Despachador (spec 006); por ahora no persiste nada — solo
captura los datos y los emite.

Deja funcionando:

- En `/t/{slug}/panel`, el navbar (`UiNavbar`, dentro de `TenantLayout`) muestra un botón destacado
  "Nueva Entrega", visible **únicamente** en esa ruta.
- Al entrar a `/t/{slug}/panel`, el foco del teclado se posiciona automáticamente sobre ese botón.
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
  validación de la spec 006: si no es "lo antes posible", ambas horas son obligatorias y "hasta"
  debe ser posterior a "desde"), modalidad de pago, importe de envío e importe de cobro; y el botón
  "Agendar".
- "Agendar" no llama a ningún endpoint del backend todavía: emite los datos capturados hacia el
  componente que lo contiene, limpia el formulario y cierra el panel. "Cancelar" cierra sin
  guardar.

**No** incluye (por ahora):

- Ningún endpoint de backend — no persiste el pedido.
- Mostrar el botón en otras páginas del tenant fuera de `/panel`.
- Selects de cliente/despachador/conductor/vehículo, ni latitud/longitud — dependen de catálogos vía
  API (`/pedidos/recursos`) y quedan fuera de esta primera versión.
- Tratamiento específico para pantallas angostas (mobile) — mismo límite conocido que el resto del
  Panel de Despachador (`ServiciosEnTurno` tampoco lo tiene).

## Decisión técnica

### Comunicación por props/emits, no por estado compartido en un módulo aparte

El botón vive en `TenantLayout.vue` (navbar) y el formulario vive en `NuevaEntregaPanel.vue`,
montado por `PanelView.vue`. En vez de un composable con estado "global" (que cualquier componente
podría leer o modificar sin pasar por el árbol de la aplicación), el estado "¿está abierto?" vive
en `PanelView.vue` — el único lugar que realmente lo necesita — y viaja hacia abajo como prop y
hacia arriba como evento, de forma explícita:

- `TenantLayout.vue` no sabe si el panel está abierto; solo emite `toggle-nueva-entrega` cuando
  detecta un clic o una flecha, y recibe `nueva-entrega-abierta` como prop (para pintar
  `aria-expanded` correctamente). También expone (`defineExpose`) un método para que quien lo
  contenga pueda devolverle el foco a su botón.
- `PanelView.vue` es dueño del estado (`ref<boolean>`), escucha el evento de `TenantLayout`, y pasa
  el valor como prop `abierto` a `NuevaEntregaPanel.vue`.
- `NuevaEntregaPanel.vue` recibe `abierto` por prop y emite `cerrar` (Cancelar/Escape) y `agendar`
  (con los datos del formulario) — no decide nada por sí mismo, solo avisa.

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

## Frontend (Vue 3)

- **`layouts/TenantLayout.vue`**: agrega prop `nuevaEntregaAbierta?: boolean` (default `false`) y
  evento `toggle-nueva-entrega`. Dentro del slot `actions` de `UiNavbar`, el botón "Nueva Entrega"
  se muestra solo cuando `route.name === 'tenant-panel'`; al hacer clic emite el evento, y
  `ArrowRight`/`ArrowDown` con el foco en el botón también lo emite (si estaba cerrado). Hace foco
  automático sobre su propio botón al montar, si la ruta activa es `tenant-panel`. Expone
  `focusNuevaEntrega()` vía `defineExpose` para que el padre pueda devolver el foco al botón.
- **`views/tenant/panel/PanelView.vue`**: agrega un `ref<boolean>` local (`nuevaEntregaAbierta`),
  una referencia (`ref`) al propio `TenantLayout` para llamar a `focusNuevaEntrega()`, y conecta:
  pasa `:nueva-entrega-abierta` y escucha `@toggle-nueva-entrega` hacia `TenantLayout`; pasa
  `:abierto` y escucha `@cerrar`/`@agendar` hacia `NuevaEntregaPanel` (ambos eventos cierran el
  panel y devuelven el foco al botón del navbar).
- **`components/panel/NuevaEntregaPanel.vue`**: deja de tener botón propio y deja de usar `UiCard`.
  Pasa a ser un `<aside>` de posición fija (`fixed left-0 top-0 h-screen w-[45%]`, `z-[35]`,
  `transition-transform duration-[400ms] ease-in-out`, clase `translate-x-0`/`-translate-x-full`
  según la prop `abierto`), con el mismo formulario ya definido (campos de spec 006). Recibe
  `abierto` por prop y emite `cerrar` y `agendar`; ya no importa ningún composable.
- **Se elimina** `composables/useNuevaEntregaPanel.ts` (queda reemplazado por el esquema de
  props/emits de arriba).

## Fuera de alcance

- Conexión a un endpoint real de creación de pedido.
- Mostrar el botón "Nueva Entrega" fuera de `/panel`.
- Cualquier estado compartido en un módulo aparte o store de Pinia dedicada — el esquema de
  props/emits alcanza.
- Layout específico para mobile.

## Criterios de aceptación

1. En `/t/{slug}/panel`, el navbar muestra el botón "Nueva Entrega"; en cualquier otra ruta del
   tenant, el navbar no lo muestra.
2. Al entrar a `/t/{slug}/panel`, el foco del teclado queda posicionado sobre el botón "Nueva
   Entrega" del navbar.
3. Al activar el botón, el panel se desliza desde la izquierda hasta cubrir el 45% del ancho del
   viewport, tapando a `ServiciosEnTurno` (30%) y la franja adicional a su derecha, con una
   transición de 0.4s.
4. El panel ocupa toda la altura del navegador (de arriba abajo); el navbar se mantiene visible y
   utilizable por encima de él.
5. Activar el botón de nuevo, o "Cancelar", o Escape con el foco dentro del formulario, desliza el
   panel de regreso fuera de la pantalla y devuelve el foco al botón del navbar.
6. Con el foco en el botón, `ArrowRight`/`ArrowDown` abre el panel y mueve el foco al primer campo.
7. "Agendar" con horario fijo y sin ambas horas, o con "hasta" antes de "desde", muestra un error y
   no cierra el panel; con datos válidos, emite el payload, limpia el formulario y cierra el panel.
8. Ningún componente hace peticiones HTTP.
9. ESLint/Prettier corren sin errores.

## Supuestos asumidos (registro completo)

1. El botón "Nueva Entrega" vive en el navbar (`UiNavbar`, slot de acciones), no dentro de la
   página del Panel, y solo se muestra cuando la ruta activa es `/panel`.
2. La comunicación entre el botón (navbar) y el formulario se hace con props/emits a través del
   árbol de componentes (`TenantLayout` ⇄ `PanelView` ⇄ `NuevaEntregaPanel`), sin ningún estado
   compartido en un módulo aparte.
3. El formulario deja de ser una tarjeta a la derecha de Servicios en turno; pasa a ser un panel
   deslizante (`transform: translateX`, transición `0.4s ease-in-out`) que cubre el 45% del
   viewport al abrirse — más ancho que `ServiciosEnTurno` (30%) — superponiéndose a él y a la
   franja adicional a su derecha.
4. El panel deslizante ocupa toda la altura del navegador (`top: 0` a `100vh`), a diferencia de
   `ServiciosEnTurno`; el navbar (z-index más alto) se mantiene visualmente por encima de él.
5. El foco inicial al entrar a `/panel` se posiciona sobre el botón del navbar.
6. Los campos del formulario son el mismo subconjunto de la spec 006 ya usado antes: solicitante,
   direcciones, fecha de servicio, horario, modalidad de pago e importes — sin selects de
   cliente/despachador/conductor/vehículo ni latitud/longitud.
7. "Agendar" no llama a ningún endpoint todavía; solo emite los datos capturados.
8. No hay tratamiento especial para pantallas angostas — mismo límite conocido que
   `ServiciosEnTurno`.
