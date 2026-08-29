# Spec: Panel de Despachador (landing tras login)

> **Spec abierta**: a diferencia de los demás CRUDs, esta especificación arranca deliberadamente
> mínima (una página en blanco) y se irá ampliando con nuevas secciones en este mismo archivo a
> medida que se agreguen funcionalidades al panel del Despachador. No cerrarla ni renumerarla al
> agregar contenido nuevo.

## Historia de usuario

Como Despachador, quiero aterrizar en una página propia (`/t/{slug}/panel`) inmediatamente después
de iniciar sesión, para tener un punto de partida dedicado a mi rol en vez de caer en el listado de
Clientes.

## Objetivo / Alcance

Primera versión mínima: una ruta y una página nuevas, exclusivas del rol `Despachador`, sin datos
ni funcionalidad real todavía — solo un texto identificador dentro del layout ya existente
(`TenantLayout.vue`). Sienta la base sobre la que se irán agregando widgets/contenido en
actualizaciones futuras de esta misma spec.

Deja funcionando:

- Nueva ruta `/t/:slug/panel`, protegida por sesión y por rol.
- El login de un usuario con rol `Despachador` redirige a `/t/:slug/panel` en vez de a Clientes.
- El login de un usuario con rol `AdminCliente` **no cambia**: sigue yendo a
  `/t/:slug/panel/clientes`.
- Nuevo ítem "Panel" en el menú de `TenantLayout.vue`, visible solo para `Despachador`.
- Si un `AdminCliente` visita `/t/:slug/panel` directamente por URL, se le redirige a Clientes en
  vez de mostrarle la página.

**No** incluye (por ahora):

- Contenido real del panel (métricas, resumen de pedidos, accesos rápidos, etc.) — se agregará en
  iteraciones futuras sobre esta misma spec.
- Cualquier endpoint de backend nuevo — esta primera versión es 100% frontend, no requiere datos
  del servidor.
- Acceso de `Conductor`, que no usa este panel de escritorio (interactúa vía app móvil, fuera de
  alcance igual que en el resto de specs de tenant).

## Decisión técnica

### Por qué es exclusiva de `Despachador` y no de `AdminCliente`

A diferencia del resto del panel (donde `AdminCliente` tiene acceso a todo y `Despachador` solo a
lo operativo), aquí es al revés: este landing es una página *para* el Despachador. El `AdminCliente`
mantiene su flujo actual sin cambios, para no alterar un comportamiento ya validado en specs
anteriores.

### Restricción de rol es solo de frontend, no de backend

Como esta versión no tiene endpoint de API propio (no hay datos que proteger todavía), la
restricción de acceso vive únicamente en el router de Vue (`beforeEach`) y en el menú de
`TenantLayout.vue`. No es una barrera de seguridad fuerte — un `AdminCliente` con acceso a las
herramientas del navegador podría, en teoría, ver el HTML de la página igualmente. Cuando el panel
tenga datos reales, esa protección deberá reforzarse en el backend (nuevo `rol.tenant:Despachador`
en las rutas de API correspondientes), igual que ya existe para otros recursos.

## Reglas de negocio

- Tras un login exitoso, el frontend decide el destino según `usuario.rol` (dato que ya devuelve
  `/login` y `/me`, ver `stores/tenantAuth.ts`):
  - `Despachador` → `/t/:slug/panel`.
  - `AdminCliente` → `/t/:slug/panel/clientes` (sin cambios).
- El guard del router (`router/index.ts`, `beforeEach`) gana una comprobación de rol además de la
  de sesión ya existente (`requiresTenantAuth`): si `to.name === 'tenant-panel'` y el rol del
  usuario autenticado no es `Despachador`, redirige a `tenant-clientes-lista` en vez de dejarlo
  entrar.
- El ítem "Panel" en `TenantLayout.vue` se agrega a la lista de navegación, pero filtrado: solo se
  incluye en el arreglo cuando `auth.usuario?.rol === 'Despachador'`.

## Frontend (Vue 3)

- **Vista nueva** `views/tenant/panel/PanelView.vue`: sigue el mismo patrón que las demás vistas
  (`<script setup lang="ts">`, importa y envuelve el contenido en `TenantLayout`), usando `UiCard`
  con `title="Panel"` como único contenido, sin tabla ni datos.
- **Ruta nueva** (`router/index.ts`): `/t/:slug/panel`, nombre `tenant-panel`,
  `meta: { requiresTenantAuth: true }`.
- **Guard de rol** (`router/index.ts`, `beforeEach`): nueva condición para la ruta `tenant-panel`
  que redirige a `tenant-clientes-lista` si `auth.usuario?.rol !== 'Despachador'`.
- **Login** (`views/tenant/LoginView.vue`): el `router.push` tras `auth.login(...)` deja de ser fijo
  a `tenant-clientes-lista`; se decide según `auth.usuario?.rol` (`tenant-panel` si es
  `Despachador`, `tenant-clientes-lista` en cualquier otro caso).
- **Menú** (`layouts/TenantLayout.vue`): nuevo ítem `{ label: 'Panel', to: '/t/${slug}/panel' }`
  agregado condicionalmente al arreglo `items`, visible solo si `auth.usuario?.rol === 'Despachador'`.

## Fuera de alcance

- Contenido real del panel — queda para futuras adiciones a esta misma spec.
- Endpoints de backend y su protección por rol a nivel de API (se agregará cuando haya datos reales
  que proteger).
- Acceso de `Conductor` a este panel de escritorio.

## Criterios de aceptación

1. Login como `Despachador` redirige a `/t/{slug}/panel`.
2. Login como `AdminCliente` sigue redirigiendo a `/t/{slug}/panel/clientes` (sin cambios respecto
   al comportamiento actual).
3. Visitar `/t/{slug}/panel` sin sesión iniciada redirige al login del tenant.
4. Visitar `/t/{slug}/panel` autenticado como `AdminCliente` redirige a Clientes, sin mostrar la
   página del panel.
5. Visitar `/t/{slug}/panel` autenticado como `Despachador` muestra la página dentro de
   `TenantLayout`, con el texto identificador "Panel" y sin datos ni tablas.
6. El menú de `TenantLayout` muestra el ítem "Panel" solo cuando el usuario autenticado es
   `Despachador`; no aparece para `AdminCliente`.
7. ESLint/Prettier corren sin errores.

## Supuestos asumidos (registro completo)

1. El landing tras login solo cambia para el rol `Despachador`; `AdminCliente` mantiene su
   comportamiento actual (va a Clientes).
2. `/t/{slug}/panel` es exclusiva de `Despachador`: un `AdminCliente` que intente entrar por URL es
   redirigido a Clientes, no ve la página ni un error.
3. Esta primera versión no tiene datos ni funcionalidad real, solo un texto identificador, y esta
   misma spec se irá ampliando en el futuro en vez de crear specs nuevas por cada adición.
4. No requiere endpoint de backend nuevo; la restricción de acceso es solo de frontend por ahora.
5. Se agrega un nuevo ítem "Panel" al menú de `TenantLayout.vue`, visible únicamente para
   `Despachador`.
6. La página es accesible en cualquier momento navegando directamente a la URL (no solo
   inmediatamente después del login), igual que el resto de páginas del panel.

## Ampliación: mapa de fondo con paneles flotantes y datos ficticios

> Primera iteración de contenido real sobre la página en blanco de la versión anterior. Sigue sin
> depender de ningún endpoint de backend: los tres componentes usan datos ficticios (fixtures)
> hasta que existan las integraciones reales. El detalle de cada componente vive en su propia spec:
> `tenant/008-servicios.md` (panel flotante izquierdo), `tenant/009-mapa.md` (mapa de fondo) y
> `tenant/010-drivers.md` (panel derecho, layout aún por definir).
>
> Esta sección reemplaza el layout de "3 columnas en CSS Grid" de la versión anterior: en vez de
> columnas del mismo alto repartiéndose el ancho, el mapa (009) ocupa todo el espacio disponible
> como capa de fondo, y `ServiciosEnTurno` (008) flota por encima, pegado al borde izquierdo real
> de la ventana. El panel derecho (010) todavía no está implementado — su forma final (flotante
> igual que el izquierdo, u otro criterio) se define al construirlo.

### Objetivo / Alcance de esta ampliación

Deja funcionando:

- `PanelView.vue` deja de envolver un solo `UiCard`; monta los componentes del panel directo dentro
  de `TenantLayout`, sin grid ni contenedor intermedio — cada componente resuelve su propia posición
  (ver spec 008 para el detalle del panel izquierdo).
- Mapa de fondo: componente `MapaConductores.vue` (spec 009), ocupa el 100% del área bajo el navbar.
- Panel flotante izquierdo: componente `ServiciosEnTurno.vue` (spec 008), `position: fixed` pegado
  al borde izquierdo real de la ventana, empezando debajo del navbar fijo de `TenantLayout`
  (`top-[4.25rem]`) y llegando hasta el borde inferior de la pantalla (`h-[calc(100vh-4.25rem)]`),
  con `z-index` por debajo del navbar pero por encima del mapa.
- Panel derecho: componente `ConductoresActivos.vue` (spec 010) — su posición final queda pendiente
  de definir en esa spec.

**No** incluye (por ahora):

- Ningún endpoint de backend nuevo — los componentes leen de fixtures ficticios en
  `frontend/src/fixtures/panelDespachador.ts`.
- Interacción entre componentes (seleccionar un conductor no resalta su marcador en el mapa, etc.).
- Actualización en tiempo real (polling/websockets).
- Un tratamiento específico para pantallas angostas (mobile) — el panel izquierdo usa el mismo
  ancho fijo (`w-[30%]`) en cualquier tamaño de pantalla; no hay un layout mobile distinto todavía.

### Frontend (Vue 3)

- **`views/tenant/panel/PanelView.vue`**: monta `ServiciosEnTurno` (y, cuando existan,
  `MapaConductores`/`ConductoresActivos`) directo dentro de `TenantLayout`, sin ningún `<div>`
  contenedor especial — cada componente flotante usa `position: fixed`, que se posiciona contra el
  viewport sin importar el padding/centrado de `TenantLayout` (ningún ancestro entre `body` y estos
  componentes usa `transform`/`filter`/`contain`, así que `fixed` funciona como se espera).
- **Fixture nuevo** `frontend/src/fixtures/panelDespachador.ts`: datos ficticios —
  `viajesEnTurnoFixture` (usado solo por 008) y `conductoresActivosFixture` (compartido por 009 y
  010, para no duplicar el mismo conductor inventado en dos archivos distintos).
- **Componentes nuevos** en `frontend/src/components/panel/`: carpeta propia para estos
  componentes específicos del Panel de Despachador, separada de `components/ui/` (piezas genéricas
  reutilizables en toda la app). Los paneles flotantes (008, y el criterio que defina 010) no usan
  `UiCard` — tienen su propio marcado porque necesitan una posición y un alto que `UiCard` no
  soporta.

### Fuera de alcance (de esta ampliación)

- Conexión a datos reales (`/pedidos`, estado en vivo de conductores, etc.) — queda para una futura
  ampliación de esta misma spec o de las specs 008/009/010.
- Cualquier interacción entre los componentes.
- Layout específico para mobile.

### Criterios de aceptación (de esta ampliación)

1. `/t/{slug}/panel` muestra el mapa (009, cuando esté implementado) ocupando todo el ancho
   disponible bajo el navbar, con el panel de Servicios (008) flotando pegado al borde izquierdo
   real de la ventana por encima de él.
2. El panel flotante empieza justo debajo del navbar (no lo tapa) y llega hasta el borde inferior
   de la pantalla.
3. Ninguno de los componentes hace ninguna petición HTTP — los datos que muestran vienen del
   fixture `panelDespachador.ts`.
4. ESLint/Prettier corren sin errores.

### Supuestos asumidos (continúa el registro de arriba)

7. Los componentes nuevos son 100% frontend, con datos ficticios hardcodeados en fixtures — sin
   endpoints de backend nuevos.
8. No hay interacción entre componentes ni actualización en tiempo real en esta primera versión.
9. ~~En mobile, las columnas se apilan en orden Servicios → Mapa → Conductores.~~ Reemplazado por el
   supuesto 11: el panel izquierdo flota con el mismo ancho fijo en cualquier tamaño de pantalla, no
   se apila.
10. El detalle de cada componente (campos mostrados, filtros de datos ficticios, límites de ítems)
    vive en su propia spec: `tenant/008-servicios.md`, `tenant/009-mapa.md`,
    `tenant/010-drivers.md`.
11. El layout deja de ser un grid de columnas del mismo alto: el mapa (009) es la capa de fondo a
    todo el ancho, y el panel de Servicios (008) flota por encima con `position: fixed`, pegado al
    borde izquierdo real del navegador — no al borde del área de contenido con padding de
    `TenantLayout`.
12. El panel flotante empieza en `top-[4.25rem]` (justo debajo del navbar fijo, que mide esa altura)
    y usa un `z-index` por debajo del navbar (`z-40`) pero por encima del mapa.
13. El layout final del panel derecho (010: flotante igual que el izquierdo, o un criterio distinto)
    no se define en esta ampliación — queda pendiente para cuando se implemente esa spec.

## Corrección: el menú de Despachador se reduce a "Panel" y "Pedidos"

> Hasta ahora `TenantLayout.vue` solo filtraba por rol el ítem "Panel" (visible solo para
> `Despachador`); el resto de ítems del menú (`Clientes`, `Usuarios`, `Despachadores`,
> `Conductores`, `Vehículos`, `Asignaciones`) se mostraban igual para todos los roles, aunque el
> backend ya bloqueaba a `Despachador` con `403` en la mayoría de esos endpoints (ver
> `tenant/002` a `tenant/005`). Esta corrección alinea el menú con esos permisos.

### Objetivo / Alcance de esta corrección

Deja funcionando:

- Cuando el usuario autenticado es `Despachador`, el menú de `TenantLayout.vue` muestra únicamente
  dos ítems, en este orden: "Panel" y "Pedidos".
- Cuando el usuario autenticado es `AdminCliente`, el menú no cambia: sigue mostrando todos los
  ítems actuales (`Pedidos`, `Clientes`, `Usuarios`, `Despachadores`, `Conductores`, `Vehículos`,
  `Asignaciones`), sin "Panel".
- El ítem "Pedidos" para `Despachador` apunta a la misma ruta que ya usa `AdminCliente`
  (`/t/:slug/panel/pedidos`), sin ninguna vista ni filtro distinto (mismo listado completo del
  tenant que documenta `tenant/006-crud-pedidos.md`).

**No** incluye:

- Ningún guard nuevo en el router — si un `Despachador` navega por URL directa a una ruta oculta
  del menú (Clientes, Usuarios, etc.), el comportamiento no cambia respecto a hoy: entra al shell
  de la vista sin protección de frontend, y solo al llamar a la API recibe el `403` que ya
  documentan esas specs.
- Cualquier cambio en los permisos de backend — ya bloqueaban a `Despachador` antes de esta
  corrección; aquí solo se oculta el enlace del menú a esas pantallas.

### Decisión técnica

El `computed` de `items` en `TenantLayout.vue` deja de armar un solo arreglo con ítems que se
agregan/quitan condicionalmente uno por uno (patrón que hoy solo cubre "Panel"). En su lugar, se
arman dos arreglos completos y se elige cuál devolver según el rol: uno corto
(`Panel` + `Pedidos`) para `Despachador`, y el arreglo completo de siempre (sin `Panel`) para el
resto de roles. Esto evita que un ítem nuevo que se agregue más adelante aparezca por accidente
para `Despachador` sin pasar por esta misma decisión explícita.

### Frontend (Vue 3)

- **`layouts/TenantLayout.vue`**: el `computed` `items` retorna, si
  `auth.usuario?.rol === 'Despachador'`, el arreglo `[Panel, Pedidos]`; en cualquier otro caso,
  retorna el arreglo completo existente (`Pedidos`, `Clientes`, `Usuarios`, `Despachadores`,
  `Conductores`, `Vehículos`, `Asignaciones`), sin `Panel`.

### Fuera de alcance (de esta corrección)

- Guard de router para las rutas ocultas del menú.
- Cambios en los permisos de backend de cualquier endpoint.

### Criterios de aceptación (de esta corrección)

1. Con sesión de `Despachador`, el menú de `TenantLayout` muestra solo "Panel" y "Pedidos" (en
   escritorio y en el menú móvil, que usa el mismo arreglo).
2. Con sesión de `AdminCliente`, el menú muestra los mismos ítems que antes de esta corrección,
   sin "Panel".
3. El enlace "Pedidos" del menú de `Despachador` navega a `/t/:slug/panel/pedidos`, la misma ruta
   que ya usa `AdminCliente`.
4. ESLint/Prettier corren sin errores.

### Supuestos asumidos (continúa el registro de arriba)

14. El menú de `Despachador` se reduce a exactamente "Panel" y "Pedidos", en ese orden; se ocultan
    "Clientes", "Usuarios", "Despachadores", "Conductores", "Vehículos" y "Asignaciones".
15. `AdminCliente` no cambia: sigue viendo todos los ítems actuales, sin "Panel".
16. Es un cambio puramente de menú (frontend); no se agrega ningún guard de router nuevo para las
    rutas ocultas — el backend ya las protegía con `403` antes de esta corrección.
17. El ítem "Pedidos" de `Despachador` usa la misma ruta y vista que `AdminCliente`, sin filtro ni
    vista distinta.
