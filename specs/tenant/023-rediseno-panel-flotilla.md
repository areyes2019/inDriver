# Spec: Rediseño del panel de conductores activos ("Flotilla") y mapa a pantalla completa

## Historia de usuario

Como dueño de tenant en mi papel de Despachador, o como Despachador propiamente, quiero que la
columna derecha del Panel se vea como una lista de flotilla compacta —avatar, nombre, saldo de
viajes y vehículo— ocupando menos ancho, y poder esconderla con una flecha para que el mapa quede
completamente despejado, sin perder de vista quién está en línea ni quién va ocupado.

## Objetivo / Alcance

`tenant/014-datos-reales-conductores-activos.md` dejó el panel derecho funcionando: un `<aside>`
fijo al 30% con tarjetas de borde gris (nombre + badge del enum `disponibilidad` + placa), y el mapa
central encajonado entre `ml-[20%]` y `mr-[30%]`. Esta spec lo rediseña y lo vuelve colapsable, y de
paso corrige dos huecos que el rediseño destapa: el endpoint no manda el saldo de viajes, y el
sistema no emite ningún aviso de tiempo real cuando un envío se entrega.

Deja funcionando:

- Panel derecho reducido de `w-[30%]` a `w-[20%]`, con encabezado nuevo ("FLOTILLA" + ícono de
  camioneta + píldora "N en línea") y tarjetas nuevas (avatar de inicial, nombre, saldo de viajes,
  vehículo, badge).
- Mapa a pantalla completa: todo el ancho de la ventana y todo el alto bajo la navbar
  (`calc(100vh - 4.25rem)`), con los dos paneles flotando encima en vez de robarle espacio.
- Flecha en la esquina superior izquierda del panel que lo desliza fuera de la pantalla por la
  derecha, y pestaña de reapertura pegada al borde derecho cuando está escondido.
- Badge calculado: `Ocupado` (naranja) si el conductor trae un pedido activo, `Disponible` (verde)
  si no. Ya no se muestra el texto crudo del enum.
- `saldo_viajes` (viajes prepagados restantes) en `GET /t/{slug}/conductores/activos`.
- Evento nuevo `PedidoEntregado` (`pedido.entregado`) en el canal del tenant, para que el badge y el
  saldo del panel se corrijan solos al cerrarse un envío.

**No** incluye:

- Click, filtros, búsqueda o acciones sobre los ítems (el panel sigue siendo de solo lectura).
- Resaltar en el mapa al conductor seleccionado en la lista, ni viceversa.
- Colapsar también el panel izquierdo ("Viajes en turno"), que se queda tal cual.
- Recordar el estado colapsado entre recargas (ver "Decisión técnica").
- Descontar la comisión del saldo en dinero al entregar (`conductores.saldo`), que hoy no ocurre —
  ver "Hallazgo".
- Cambios a `panda_express`.

## Decisión técnica

### El mapa sale del carril centrado de `TenantLayout`

`TenantLayout.vue:110` envuelve todo su `<slot />` en
`<main class="mx-auto max-w-screen-xl px-4 pb-4 pt-[5.25rem] md:px-8 md:pb-8 md:pt-[6.25rem]">`.
Ese carril de 1280px con padding es lo que hoy impide que el mapa toque los bordes de la ventana,
por más grande que sea el monitor; los paneles nunca lo notaron porque son `fixed` y se salen del
flujo. Se agrega a `TenantLayout` una prop booleana `anchoCompleto` (default `false`): cuando es
`true`, el `<main>` pierde `mx-auto max-w-screen-xl` y todo su padding, y queda como
`<main class="pt-[4.25rem]">` — el alto exacto de la navbar fija (`UiNavbar.vue:25-29`: barra de
gradiente `h-1` + `nav` `h-16` = `4.25rem`), sin margen ni relleno.

Solo `PanelView.vue` la activa; el resto de las pantallas del tenant (Clientes, Usuarios,
Despachadores, Conductores, Configuración) siguen renderizándose exactamente igual. Se descarta un
`<slot name="full-bleed">` alternativo: obligaría a cada vista a saber en qué ranura va, cuando la
diferencia es una sola decisión booleana de la vista.

### El mapa cubre el área bajo la navbar; los paneles flotan encima

`PanelView.vue` pierde el `<div class="ml-[20%] mr-[30%] min-h-[calc(100vh-4.25rem-2rem)]">` que
envolvía a `<MapaConductores />`. En su lugar, el contenedor del mapa pasa a
`class="h-[calc(100vh-4.25rem)] w-full"`, que dentro del `<main>` sin padding equivale a todo el
ancho de la ventana y todo el alto bajo la navbar.

Los dos `<aside>` fijos ya viven en esa misma franja (`top-[4.25rem]`,
`h-[calc(100vh-4.25rem)]`, `z-30`), así que quedan encima del mapa sin ningún cambio de
posicionamiento. Se conserva el estilo de "losa": pegados al borde, de arriba a abajo, sin margen ni
esquinas redondeadas, separados del mapa por `shadow-xl`. Se descartan las variantes de tarjeta
flotante con margen y de panel semitransparente: la primera desperdicia ancho en un panel que
justamente se está angostando al 20%, y la segunda baja el contraste de nombres y saldos sobre un
mapa lleno de color.

Consecuencia asumida: el mapa sigue existiendo bajo los paneles, pero esa franja no es visible ni
clickeable. Es el precio de que los paneles floten, y es el comportamiento que se pidió.

### `MapaConductores` necesita un aviso de "cambiaste de tamaño"

La API de Google Maps calcula el viewport una sola vez, al montarse: si su contenedor cambia de
tamaño después, la superficie nueva queda en gris hasta que el usuario arrastra el mapa. Esto se
dispara dos veces en esta spec — al pasar el contenedor a pantalla completa y, sobre todo, cada vez
que el panel derecho se colapsa o se expande (el mapa gana o pierde un 20% de ancho visible).

`MapaConductores.vue` expone un método `redimensionar()` vía `defineExpose`, que dispara el `resize`
de Google Maps y reencuadra. `PanelView.vue` lo invoca al terminar la animación de colapso (evento
`transitionend` del `<aside>`, no un `setTimeout` con el número mágico de la duración, que se
desincroniza si la animación cambia).

### El badge se calcula en el frontend a partir del pedido asignado

`conductores.disponibilidad` no sirve para esto: la app del repartidor solo escribe `DISPONIBLE` al
conectarse y `FUERA_DE_SERVICIO` al desconectarse (`EstadoController@actualizar`, spec
`tenant/013`), nunca `OCUPADO`. Un panel que muestre ese enum diría "Disponible" para un conductor
que va manejando con el paquete encima.

`ConductorActivoResource` ya expone `pedido_asignado` (el pedido en estado no final del conductor;
`ConductorController::activos()` filtra con
`whereNotIn('estado', PedidoEstadoService::ESTADOS_FINALES)` y spec `tenant/020` RN-06 garantiza que
hay como mucho uno). El badge sale de ahí:

| Condición | Texto | Color `UiBadge` |
| --- | --- | --- |
| `pedido_asignado !== null` | `Ocupado` | `orange` |
| `pedido_asignado === null` | `Disponible` | `green` |

Se descarta desglosar la etapa del viaje (`Recogiendo`/`Entregando`): son etiquetas más largas en un
panel que se está reduciendo al 20%. Se descarta también anteponer `DESCANSO`/`FUERA_DE_SERVICIO`
del enum: esta lista solo contiene conductores `ONLINE`, y esos valores hoy no se producen.

`disponibilidad` se conserva en la respuesta del endpoint (lo consume `MapaConductores.vue`); lo que
cambia es que este panel deja de pintarlo.

### `saldo_viajes` se calcula en el índice, no por fila

El panel muestra los **viajes prepagados restantes**, no el saldo en dinero. Se reusa exactamente la
fórmula y el patrón que ya tiene `ConductorController::index()` (`ConductorController.php:43-44`,
spec `tenant/015`): `withSum('ventasViajes as viajes_vendidos', 'cantidad_viajes')` +
`withCount(['pedidos as viajes_consumidos' => fn ($q) => $q->where('prepago_descontado', true)])`, y
la resta en el resource. Ambos son subconsultas independientes del `with('pedidos')` acotado que ya
tiene `activos()`, así que conviven sin pisarse y sin N+1 (una consulta para toda la lista, no una
por conductor como haría `GET /conductores/{id}/saldo-viajes`).

Se manda **siempre**, sin condicionar a la modalidad del tenant, por la misma razón que en spec
`tenant/015`: evitarle al panel una petición previa a `/configuracion` solo para saber si pedir el
campo. En un tenant configurado en modalidad `Comision` el número será 0 y poco informativo; se
asume aceptable porque la operación actual es Prepago (el default de
`ConfiguracionTenant::MODALIDAD`).

### Falta el aviso de tiempo real al entregar

`PedidoEstadoService::notificarConductores()` (`PedidoEstadoService.php:115-142`) emite
`PedidoYaTomado` (`pedido.tomado`) al pasar a `TOMADO` y `PedidoCanceladoParaConductor`
(`pedido.cancelado`) al cancelar, pero al pasar a `ENTREGADO` cae en el `default => null` del `match`
y no emite nada al canal del tenant.

Con el badge calculado por pedido asignado, ese hueco deja de ser cosmético: un conductor que
entrega su paquete quedaría en naranja "Ocupado" indefinidamente, y su saldo de viajes seguiría
mostrando el valor previo al descuento — información falsa, peor que no mostrarla. Se agrega
`PedidoEntregado` (`pedido.entregado`), calcado de `PedidoYaTomado`: mismo canal privado
`tenant.{slug}.conductores`, misma carga `['id_pedido', 'event_id']`, mismo carácter no crítico de
spec `tenant/018` RN-05 (solo socket, sin respaldo de push — si se pierde, la lista se corrige en la
siguiente recarga).

### Qué eventos recarga el panel

Hoy `ConductoresActivos.vue` solo escucha `conductor.disponibilidad-cambiada`. Con el saldo y el
badge nuevos, la lista tiene tres fuentes más de cambio, todas ya presentes en el canal:

| Evento | Por qué afecta al panel |
| --- | --- |
| `conductor.disponibilidad-cambiada` | entra o sale un conductor de la lista (ya se escuchaba) |
| `pedido.tomado` | un conductor pasa a `Ocupado` |
| `pedido.cancelado` | un conductor vuelve a `Disponible` sin entregar |
| `pedido.entregado` (nuevo) | vuelve a `Disponible` y baja su saldo de viajes |
| `saldo.acreditado` | el dueño le vendió viajes (`VentaViajeConductorController.php:68`) |

Todos disparan la misma `cargarConductores()` ya existente, igual que hace `MapaConductores.vue`
(`MapaConductores.vue:109-111`). Se descarta actualizar el ítem en memoria a partir de la carga del
evento: los payloads traen `id_pedido`, no el conductor ni su saldo recalculado, y la lista es
corta.

El toast "X está en línea" se sigue mostrando **solo** desde `conductor.disponibilidad-cambiada` con
`DISPONIBLE`, como hoy; los eventos nuevos recargan en silencio.

### El colapso vive en `ConductoresActivos.vue`, y no se recuerda entre recargas

El estado `colapsado` es un `ref` local del propio componente, no de `PanelView`: ningún otro
componente necesita saberlo ahora que el mapa ya no calcula márgenes en función del ancho del panel
(esa era la única razón por la que `PanelView` habría tenido que enterarse). `PanelView` solo
escucha el evento `@colapso-terminado` para llamar a `redimensionar()` del mapa.

Se descarta persistirlo en `localStorage`: un panel que sigue escondido al día siguiente se lee como
"la flotilla desapareció", no como "yo la escondí". Abierto es el estado normal; esconderlo es una
acción momentánea y cada carga de `/panel` vuelve a abrirlo.

La animación es `transition-transform duration-[400ms] ease-in-out` con `translate-x-0` /
`translate-x-full`, el mismo patrón y la misma duración que ya usan `NuevaEntregaPanel.vue:267` y
`DetalleEnvioPanel.vue:74`. Un corte seco haría imposible entender a dónde se fue el panel; por eso
hay animación, y por eso se respeta `prefers-reduced-motion` (quien tenga activada la reducción de
movimiento en su sistema operativo lo ve aparecer y desaparecer sin deslizamiento).

### Formato del saldo y de la línea de vehículo

`saldo_viajes` es un entero de viajes, no dinero: se pinta como `Saldo: 12 viajes` (`1 viaje` en
singular, `0 viajes` cuando no le quedan). No se usa `Intl.NumberFormat` con `currency`, que
aplicaría al saldo en dinero que esta spec no muestra.

La línea de vehículo se arma con lo que hoy existe en la tabla `vehiculos`: **solo `placa` y
`marca`**. Las columnas `modelo`, `anio` y `color` fueron eliminadas por
`2026_09_04_160000_drop_modelo_anio_color_from_vehiculos_table.php`, así que no es posible
reproducir literalmente "Italica, Blanca HJT5636" ni "Honda Civic" del mockup. Se muestra
`"{marca} {placa}"`, o solo la placa si no hay marca, o `"Sin vehículo"` si el conductor no tiene
uno.

### Se replica el marcado del avatar en vez de reusar `UiPersonListItem`

`UiPersonListItem` existe en `components/ui/` y hoy solo aparece en `StyleGuideView.vue`, nunca en
una pantalla real. No encaja aquí sin cambios: le falta la línea del saldo, su avatar es `h-10 w-10`
(demasiado para un panel al 20%) y su badge va en línea con el nombre, no alineado a la derecha del
ítem. Ampliarlo con props opcionales para un único consumidor produciría una pieza compartida con
más opciones que usos. `ConductoresActivos.vue` escribe su propio `<li>`, igual que ya hacía.

### Conductores de prueba

`ConductorActivoResource` expone `es_prueba`, pero en esta etapa **toda** la operación es ficticia y
todos los conductores lo son, así que distinguirlos no aportaría nada. La píldora "N en línea"
cuenta todos los conductores de la lista y ningún ítem lleva marca de prueba. Queda como decisión
explícita a revisar cuando haya conductores reales conviviendo con los de prueba.

## Hallazgo (fuera de alcance, documentado)

En modalidad `Comision`, `PedidoEstadoService::liquidarConductor()`
(`PedidoEstadoService.php:191-214`) calcula `comision_calculada` sobre el pedido pero **no descuenta
nada de `conductores.saldo`**; ese saldo en dinero solo se mueve desde `SaldoService` cuando un
`AdminCliente` acredita o ajusta a mano. No se corrige aquí —esta spec muestra viajes prepagados, no
dinero— pero queda registrado porque contradice lo que sugiere `tenant/022`.

## Reglas de negocio

1. El panel derecho mide el 20% del ancho de la ventana (antes 30%), sigue fijo al borde derecho,
   bajo la navbar y hasta el borde inferior.
2. El mapa ocupa todo el ancho de la ventana y todo el alto bajo la navbar; los dos paneles se
   dibujan encima de él.
3. El encabezado del panel muestra un ícono de camioneta, el texto "FLOTILLA" en mayúsculas, y a la
   derecha una píldora verde suave con "N en línea", donde N es la cantidad de conductores de la
   lista.
4. Sin conductores en línea, la píldora dice "0 en línea" y el cuerpo muestra "No hay conductores
   activos".
5. Cada ítem muestra, en este orden: avatar circular con la inicial del nombre, nombre completo,
   `Saldo: N viajes`, línea de vehículo, y un badge alineado a la derecha.
6. El badge dice `Ocupado` (naranja) si el conductor tiene un pedido activo asignado, y `Disponible`
   (verde) si no.
7. La línea de vehículo es `"{marca} {placa}"`; solo `placa` si no hay marca; `"Sin vehículo"` si el
   conductor no tiene vehículo.
8. `Saldo: N viajes` son los viajes prepagados restantes: viajes vendidos al conductor menos pedidos
   suyos con `prepago_descontado = true`. Se concuerda el singular ("1 viaje").
9. Los ítems no tienen borde propio: se separan entre sí por una línea divisoria y espaciado.
10. Los ítems son de solo lectura: no responden al click ni ofrecen acciones.
11. La lista se recarga sola con `conductor.disponibilidad-cambiada`, `pedido.tomado`,
    `pedido.cancelado`, `pedido.entregado` y `saldo.acreditado`.
12. El toast "X está en línea" se sigue disparando solo desde `conductor.disponibilidad-cambiada`
    con `disponibilidad = DISPONIBLE`.
13. La flecha de colapso está en la esquina superior izquierda del panel, apunta a la derecha y al
    activarla desliza el panel completo fuera de la pantalla por el borde derecho.
14. Con el panel escondido queda visible una pestaña pegada al borde derecho, con la flecha
    invertida, que lo devuelve.
15. El mapa se remide al terminar cada animación de colapso o expansión.
16. El estado colapsado no se recuerda: cada carga de `/panel` muestra el panel abierto.
17. Si el sistema operativo del usuario pide movimiento reducido, el panel cambia de estado sin
    animación de deslizamiento.
18. Los conductores de prueba se listan y se cuentan igual que cualquier otro, sin distintivo.
19. Al pasar un pedido a `ENTREGADO` se emite `pedido.entregado` en el canal privado del tenant.

## Backend (Laravel)

- **Nuevo evento** `app/Events/Tenant/PedidoEntregado.php`: calcado de `PedidoYaTomado`
  (`ShouldBroadcast`, `PrivateChannel("tenant.{$tenantSlug}.conductores")`,
  `broadcastAs(): 'pedido.entregado'`, `broadcastWith(): ['id_pedido', 'event_id']`, `eventId` con
  `Str::uuid()`).
- **`app/Services/PedidoEstadoService.php`**: en `notificarConductores()`, el `match (true)` gana un
  brazo `$nuevoEstado === 'ENTREGADO' => PedidoEntregado::dispatch($pedido->id_pedido, $slug)`,
  junto al de `TOMADO`.
- **`app/Http/Controllers/Tenant/ConductorController.php`**, método `activos()`: se agregan al query
  las dos subconsultas del saldo, con los mismos alias que usa `index()`:
  ```php
  ->withSum('ventasViajes as viajes_vendidos', 'cantidad_viajes')
  ->withCount(['pedidos as viajes_consumidos' => fn ($q) => $q->where('prepago_descontado', true)])
  ```
  Las dos subconsultas van **después** del `->select('conductores.*')` que ya tenía el método:
  `withSum`/`withCount` agregan sus columnas con `addSelect`, y un `select()` posterior las borra —
  el saldo llegaría siempre en 0.
- **`app/Http/Resources/Tenant/ConductorActivoResource.php`**: campos nuevos
  `'saldo_viajes' => (int) $this->viajes_vendidos - (int) $this->viajes_consumidos` y
  `'marca' => $this->vehiculo?->marca` (la línea de vehículo del panel es `"{marca} {placa}"`, y
  hasta ahora el recurso solo mandaba la placa). Los demás campos no cambian.
- Sin migraciones, sin rutas nuevas, sin cambios de permisos.

## Frontend (Vue 3)

- **`frontend/src/layouts/TenantLayout.vue`**: prop nueva `anchoCompleto?: boolean` (default
  `false`). El `<main>` se vuelve `:class`: con `false`, las clases actuales; con `true`,
  `pt-[4.25rem]` a secas.
- **`frontend/src/views/tenant/panel/PanelView.vue`**:
  - Pasa `ancho-completo` a `TenantLayout`.
  - El `<div>` del mapa cambia de `class="ml-[20%] mr-[30%] min-h-[calc(100vh-4.25rem-2rem)]"` a
    `class="h-[calc(100vh-4.25rem)] w-full"`.
  - `mapaRef` nuevo; escucha `@colapso-terminado` de `<ConductoresActivos>` y llama a
    `mapaRef.value?.redimensionar()`.
- **`frontend/src/components/panel/MapaConductores.vue`**: pierde el envoltorio `<UiCard title="Mapa">`
  (su encabezado, esquinas redondeadas y `p-5` impedían que el mapa llegara a los bordes) y queda
  como un `<div class="h-full w-full">`. Gana `defineExpose({ redimensionar })` y se suscribe también
  a `pedido.entregado`, por la misma razón que ya escuchaba `pedido.tomado`: la ruta dibujada deja de
  aplicar.
- **`frontend/src/services/maps/`**: método `resize(containerId)` nuevo en `MapService` (fachada),
  `GoogleProvider` (dispara `google.maps.event.trigger(map, 'resize')` y restaura el centro, que
  `fitBounds` no conserva) y `BaseProvider` (stub, como el resto de la interfaz).
- **`frontend/src/components/panel/ConductoresActivos.vue`** (el grueso del cambio):
  - `ConductorActivo` gana `saldo_viajes: number` y `pedido_asignado: { id_pedido: number } | null`.
  - `colapsado = ref(false)`; emit `colapso-terminado`.
  - `enLinea = computed(() => conductores.value.length)`.
  - Helpers: `inicial(nombre)`, `textoVehiculo(conductor)`, `textoSaldo(n)`, `badge(conductor)`
    (texto + color).
  - `<aside>`: `w-[20%]` (era `w-[30%]`), más `transition-transform duration-[400ms] ease-in-out` y
    `:class="colapsado ? 'translate-x-full' : 'translate-x-0'"`; conserva
    `fixed right-0 top-[4.25rem] z-30 h-[calc(100vh-4.25rem)] bg-white shadow-xl`.
  - `<header>`: ícono de camioneta (`@iconify/vue`, ya instalado y ya usado en
    `ServiciosEnTurno.vue:4`), "FLOTILLA" en
    `text-xs font-semibold uppercase tracking-wide text-body`, y píldora "N en línea" a la derecha.
  - Botón de colapso: `absolute left-0 top-0 -translate-x-full`, `aria-expanded`, `aria-label`
    ("Ocultar panel de flotilla" / "Mostrar panel de flotilla"), anillo de foco visible; el mismo
    botón, con la flecha invertida, hace de pestaña de reapertura cuando `colapsado`.
  - `@transitionend` en el `<aside>` emite `colapso-terminado`.
  - Ítems: `<li class="flex items-start gap-3 border-b border-default py-3 last:border-b-0">` con
    avatar `h-9 w-9 rounded-full bg-accent-soft text-accent`, nombre `text-sm font-semibold`, saldo
    `text-sm font-semibold text-success-text`, vehículo `text-xs text-body/70 truncate`, badge a la
    derecha. Sin `border` alrededor del ítem.
  - `onMounted`/`onUnmounted`: `bind`/`unbind` de los 5 eventos de la tabla de arriba.
  - Toasts: su contenedor pasa de `right-[calc(30%+1rem)]` a `right-[calc(20%+1rem)]`, y a `right-4`
    cuando el panel está colapsado. Además sale de dentro del `<aside>` y pasa a ser su hermano en
    el template: un ancestro con `transform` se vuelve el bloque contenedor de sus descendientes
    `position: fixed`, así que dentro del panel los toasts viajarían con él al colapsarlo en vez de
    quedarse anclados a la ventana.
- **`frontend/src/assets/main.css`**: bloque `@media (prefers-reduced-motion: reduce)` que anula la
  transición del panel.

## Fuera de alcance

- Persistir el estado colapsado entre recargas.
- Colapsar el panel izquierdo de "Viajes en turno".
- Click, filtros, búsqueda o acciones sobre los ítems.
- Sincronizar selección entre la lista y el mapa.
- Distinguir visualmente conductores de prueba.
- Mostrar el saldo en dinero (`conductores.saldo`) o corregir que la comisión no lo descuente.
- Desglosar la etapa del viaje en el badge (`Recogiendo`/`Entregando`).
- Reponer `modelo`, `anio` o `color` en `vehiculos`.
- Cambios a `panda_express`.

## Criterios de aceptación

1. En `/t/{slug}/panel`, el mapa llega a los cuatro bordes del área bajo la navbar, sin franjas
   grises ni carril centrado, en pantallas anchas.
2. Las demás pantallas del tenant conservan su carril centrado de 1280px, sin cambios visuales.
3. El panel derecho mide 20% del ancho y flota sobre el mapa, pegado al borde, de arriba a abajo.
4. El encabezado muestra ícono + "FLOTILLA" + píldora "N en línea", con N igual a la cantidad de
   ítems listados.
5. Cada ítem muestra avatar con inicial, nombre, `Saldo: N viajes`, vehículo y badge.
6. Un conductor con pedido activo muestra `Ocupado` en naranja; uno sin pedido, `Disponible` en
   verde.
7. Al entregar un envío, el panel pasa ese conductor a `Disponible` y baja su saldo de viajes sin
   recargar la página.
8. Al vender viajes a un conductor conectado, su saldo sube en el panel sin recargar la página.
9. La flecha superior izquierda esconde el panel deslizándolo a la derecha; la pestaña del borde lo
   devuelve; el mapa se remide en ambos casos y no queda gris.
10. El botón de colapso es alcanzable con Tab, muestra foco visible y anuncia su estado
    (`aria-expanded`, `aria-label`).
11. Con movimiento reducido activado en el sistema operativo, el panel cambia de estado sin
    deslizamiento.
12. Al recargar `/panel` con el panel previamente escondido, vuelve a aparecer abierto.
13. `GET /t/{slug}/conductores/activos` incluye `saldo_viajes` para `AdminCliente` y `Despachador`,
    en una sola consulta (sin N+1).
14. `ENTREGADO` emite `pedido.entregado` en `tenant.{slug}.conductores`.
15. Un conductor sin vehículo muestra "Sin vehículo"; uno con vehículo sin marca, solo la placa.
16. Los toasts de "en línea" siguen apareciendo y no quedan tapados por el panel, ni abierto ni
    colapsado.
17. ESLint/Prettier corren sin errores; Pint corre sin errores; `php artisan test` pasa, con
    `ConductoresActivosTest` actualizado (`saldo_viajes` en la respuesta) y prueba nueva de que
    `ENTREGADO` emite `PedidoEntregado`.

## Supuestos asumidos (registro completo)

1. "Reducir al 20%" se refiere al ancho del panel, no al tamaño de la tipografía; el panel sigue
   fijo al borde derecho.
2. El mapa ocupa todo el ancho de la ventana y todo el alto bajo la navbar; la navbar sigue siendo
   una franja sólida y el mapa no pasa por detrás de ella. Los dos paneles flotan encima del mapa en
   vez de reducirle el área.
3. Al angostar el panel, los textos largos se truncan con "…"; no se reduce el tamaño de fuente.
4. El título pasa a "FLOTILLA" en mayúsculas, pequeño y en gris, con ícono de camioneta a la
   izquierda.
5. El contador "N en línea" es la cantidad de conductores listados en ese momento.
6. Sin conductores, la píldora dice "0 en línea" y se conserva el mensaje "No hay conductores
   activos".
7. Cada ítem gana un avatar circular con la inicial del nombre, en el morado del acento.
8. Los ítems pierden el borde completo y se separan por líneas divisorias y espaciado.
9. Se agrega la línea `Saldo: N viajes` — viajes prepagados restantes, no dinero (decisión del
   usuario). En un tenant en modalidad `Comision` ese número será 0; se acepta.
10. La línea de vehículo es `"{marca} {placa}"`, y no puede reproducir el color ni el modelo del
    mockup porque esas columnas ya no existen en la base de datos.
11. El badge dice `Disponible` (verde) u `Ocupado` (naranja), calculado por la presencia de un
    pedido activo asignado, no por la columna `disponibilidad`.
12. Los ítems siguen siendo de solo lectura.
13. La flecha de colapso vive en la esquina superior izquierda del panel y lo saca por la derecha.
14. El movimiento es una animación de deslizamiento de 400ms, la misma que ya usan los otros paneles
    deslizantes del proyecto.
15. Con el panel escondido queda una pestaña de reapertura pegada al borde derecho, para no dejar al
    usuario sin salida.
16. Con el panel escondido, el mapa queda visible en todo ese ancho (ya lo ocupaba: solo deja de
    estar tapado).
17. El estado colapsado no se persiste entre recargas.
18. Los toasts de "en línea" se siguen mostrando con el panel colapsado, reacomodados al borde.
19. El contador y la lista incluyen a los conductores de prueba sin distintivo, porque en esta etapa
    toda la operación es ficticia.
20. El endpoint necesita mandar `saldo_viajes`, que hoy no manda.
21. Hace falta un evento de tiempo real al entregar un pedido, que hoy no existe, para que el badge
    calculado y el saldo no queden desactualizados.
22. El panel recarga la lista completa ante cada evento, en vez de actualizar el ítem afectado en
    memoria.
23. Se replica el marcado del avatar en el propio componente en vez de ampliar `UiPersonListItem`
    para un único consumidor.
24. `TenantLayout` gana una prop `anchoCompleto` que solo activa `PanelView`; las demás pantallas no
    cambian.
25. Se respeta la preferencia de movimiento reducido del sistema operativo.
