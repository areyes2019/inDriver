# Spec: Viajes en turno (panel flotante izquierdo del Panel de Despachador)

## Historia de usuario

Como Despachador, quiero ver un resumen de los viajes en turno (pedidos que aún no llegaron a un
estado final) al entrar a mi Panel, para tener contexto inmediato de la operación sin ir al listado
completo de Pedidos.

## Objetivo / Alcance

Primera versión del componente `ServiciosEnTurno.vue`: un panel flotante que se sobrepone al mapa
central (`tenant/009-mapa.md`) descrito en la ampliación de `tenant/007-panel-despachador.md`. Usa
datos ficticios (fixture), sin conectarse todavía al endpoint real de pedidos (`GET /pedidos`, spec
`tenant/006-crud-pedidos.md`).

Deja funcionando:

- Panel con título "Viajes en turno", pegado al borde izquierdo real de la ventana del navegador,
  justo debajo del navbar fijo de `TenantLayout`, con el resto del alto de la pantalla.
- Lista scrolleable de tarjetas de viaje ficticias tomadas del fixture `viajesEnTurnoFixture`
  (`frontend/src/fixtures/panelDespachador.ts`).
- Cada tarjeta muestra: folio (número de pedido), estado (badge), cliente (o "Solicitante
  ocasional"), dirección de entrega (una línea, truncada si es larga), y hora ("Lo antes posible" o
  la `hora_desde`).
- Orden: primero los marcados "lo antes posible", luego el resto por `hora_desde` ascendente.
- Estado vacío ("No hay viajes en turno") si el fixture está vacío.

**No** incluye:

- Conexión al endpoint real de pedidos.
- Click, filtros, búsqueda, ni acciones de cambio de estado sobre las tarjetas.
- Paginación — el fixture trae un número fijo de ítems (6-8) y se muestran todos dentro de la lista
  con scroll propio.

## Decisión técnica

### Por qué "viajes en turno" excluye estados finales

Igual que la máquina de estados definida en `tenant/006-crud-pedidos.md`, un pedido en `ENTREGADO`,
`RECHAZADO` o `CANCELADO` ya salió de operación. El fixture ficticio solo incluye pedidos en
`PENDIENTE`, `PUBLICADO`, `TOMADO`, `ARRIBADO`, `EN_CAMINO` o `ARRIBADO_A_ENTREGA`.

### Por qué no se filtra por despachador

El panel muestra la operación completa del tenant (todos los pedidos en turno), no solo los que
gestionó el despachador que inició sesión — mismo criterio que ya usa `tenant/006`: "un despachador
ve y puede operar sobre todos los pedidos... por igual".

### Reuso del mapa de colores de estado

`estadoColor` se copia igual que en `ListaPedidosView.vue` (`PENDIENTE: gray`, `PUBLICADO: blue`,
`TOMADO: orange`, `ARRIBADO: orange`, `EN_CAMINO: blue`, `ARRIBADO_A_ENTREGA: blue`) para que el
badge se vea igual en ambos lugares del panel.

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

### Ya no se reusa `UiCard`

`UiCard` está pensada para tarjetas normales dentro del flujo del contenido (sombra suave, sin
posición propia). Este panel necesita comportarse distinto — posición fija, alto fijo, sombra más
marcada, esquinas rectas — así que se construye con marcado propio (`<aside>` con `<header>` +
lista), reusando solo las clases de texto (`text-heading`, `text-body`) para verse consistente con
el resto de la app.

### Esquinas rectas, sin `border-radius`

A diferencia del resto de tarjetas del panel (`UiCard`, con `rounded-2xl`), este panel flotante y
las tarjetas de viaje dentro de él usan esquinas rectas — sin ninguna clase `rounded-*` — por
requerimiento explícito de este diseño.

## Reglas de negocio

- El fixture `viajesEnTurnoFixture` trae entre 6 y 8 pedidos ficticios con estados variados (no
  finales).
- Orden: ítems con `lo_antes_posible: true` primero (en el orden en que vienen en el fixture),
  después el resto ordenado por `hora_desde` ascendente.
- Dirección de entrega mostrada en una sola línea, truncada con `...` si excede el ancho disponible
  (`truncate` de Tailwind).
- Si `cliente_nombre` es `null` en el fixture, se muestra "Solicitante ocasional" (mismo caso que
  contempla `tenant/006` para `id_cliente = null`).

## Datos ficticios (fixture)

`frontend/src/fixtures/panelDespachador.ts` exporta:

```ts
export interface ViajeEnTurno {
  numero_pedido: string
  cliente_nombre: string | null
  direccion_entrega: string
  estado: 'PENDIENTE' | 'PUBLICADO' | 'TOMADO' | 'ARRIBADO' | 'EN_CAMINO' | 'ARRIBADO_A_ENTREGA'
  lo_antes_posible: boolean
  hora_desde: string | null // 'HH:mm', null si lo_antes_posible es true
}

export const viajesEnTurnoFixture: ViajeEnTurno[]
```

## Frontend (Vue 3)

- **Componente nuevo** `frontend/src/components/panel/ServiciosEnTurno.vue`:
  `<script setup lang="ts">`, importa `viajesEnTurnoFixture` y ordena la lista con un `computed`;
  raíz `<aside>` con `fixed left-0 top-[4.25rem] z-30 h-[calc(100vh-4.25rem)] w-[30%] bg-white
  shadow-xl` (sin `rounded-*`), un `<header>` con el título "Viajes en turno" y un contenedor interno
  `flex-1 overflow-y-auto` con la lista; cada tarjeta de viaje es un `<li>` con `border
  border-default` (sin `rounded-*`) que usa `UiBadge` para el estado; estado vacío con mensaje simple
  si el arreglo resultante está vacío.
- Se monta desde `PanelView.vue` directo dentro de `TenantLayout` (sin grid ni contenedor
  intermedio) — ver ampliación de `tenant/007-panel-despachador.md`.

## Fuera de alcance

- Conexión al endpoint real de pedidos.
- Cualquier interacción (click, filtros, cambio de estado).
- Paginación (la lista completa scrollea dentro del panel, sin cargar por páginas).
- Filtrado por despachador.
- Comportamiento específico para pantallas angostas (mobile) — el panel usa el mismo `w-[30%]`
  fijo en cualquier tamaño de pantalla (ver supuesto 5).

## Criterios de aceptación

1. El panel "Viajes en turno" está pegado al borde izquierdo real de la ventana (`left: 0` del
   viewport), no al borde del contenido con padding de `TenantLayout`.
2. El panel empieza justo debajo del navbar (no lo tapa) y llega hasta el borde inferior de la
   ventana.
3. El panel y las tarjetas de viaje dentro de él tienen esquinas rectas (sin `border-radius`), fondo
   blanco y una sombra marcada.
4. Se listan los pedidos ficticios del fixture como tarjetas dentro de una lista con scroll propio,
   cada una con folio, cliente, dirección, badge de estado y hora.
5. Los ítems con `lo_antes_posible: true` aparecen antes que el resto; el resto está ordenado por
   `hora_desde` ascendente.
6. Si el fixture estuviera vacío, se muestra el mensaje "No hay viajes en turno" en vez de una lista
   vacía.
7. El componente no realiza ninguna petición HTTP.
8. ESLint/Prettier corren sin errores.

## Supuestos asumidos (registro completo)

1. "Viajes en turno" = pedidos ficticios con estado no final (excluye ENTREGADO/RECHAZADO/
   CANCELADO), sin filtrar por despachador.
2. Orden: lo antes posible primero, luego por `hora_desde` ascendente.
3. Campos mostrados por tarjeta: folio (número de pedido), estado (badge), cliente, dirección de
   entrega (truncada), hora.
4. Estado vacío con mensaje si no hay viajes en turno.
5. Lista de solo lectura, sin click ni acciones.
6. Fixture con 6-8 ítems fijos; la lista completa se muestra con scroll propio dentro del panel, sin
   paginación.
7. El fixture y su tipo `ViajeEnTurno` viven en `frontend/src/fixtures/panelDespachador.ts` — es
   exclusivo de esta spec (a diferencia de `conductoresActivosFixture`, que comparten 009 y 010).
8. El componente vive en `frontend/src/components/panel/ServiciosEnTurno.vue`, carpeta separada de
   `components/ui/`.
9. El mapa de colores de estado (`estadoColor`) se copia igual que en `ListaPedidosView.vue`, para
   consistencia visual.
10. "Pegado al borde izquierdo" es el borde real de la ventana del navegador (`position: fixed`),
    no el borde del área de contenido con padding de `TenantLayout` — el panel rompe visualmente el
    padding/centrado que usa el resto de vistas del panel.
11. El panel empieza en `top-[4.25rem]` (justo debajo del navbar fijo, que mide esa altura) y no en
    `top: 0` — el navbar se ve siempre completo, sin que el panel lo tape ni quede tapado por él.
12. `z-index` del panel (`z-30`) queda por debajo del navbar (`z-40`), suficiente para ir por encima
    del futuro mapa central y sus controles.
13. El panel ya no usa `UiCard` — tiene su propio marcado, porque necesita una posición y una altura
    que `UiCard` no soporta.
14. Esquinas rectas (sin `rounded-*`) tanto en el panel como en las tarjetas de viaje dentro de él,
    a diferencia del resto de tarjetas de la app (`UiCard`, con esquinas redondeadas).
15. En mobile, el panel se mantiene con el mismo `w-[30%]` fijo y flotando sobre el mapa — no se
    apila debajo como planteaba la versión anterior de esta ampliación (ver también supuesto 11 de
    la ampliación de `tenant/007-panel-despachador.md`); el ajuste de legibilidad en pantallas
    angostas queda fuera de esta corrección.
