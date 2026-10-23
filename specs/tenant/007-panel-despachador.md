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
2. ~~`/t/{slug}/panel` es exclusiva de `Despachador`: un `AdminCliente` que intente entrar por URL
   es redirigido a Clientes, no ve la página ni un error.~~ Reemplazado por el supuesto 18: también
   entra `AdminCliente`.
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
  ancho fijo (`w-[20%]`, spec `tenant/008`) en cualquier tamaño de pantalla; no hay un layout mobile
  distinto todavía.

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

## Corrección: `AdminCliente` también accede a `/panel` (mapa centrado por ciudades)

> `011-asignacion-ciudades-admin-cliente.md` (ADMIN_CENTRAL asigna ciudades a cada AdminCliente
> para que "el mapa central aparezca cuando abran su panel de control") reveló, ya en pruebas de
> extremo a extremo, que `/panel` era exclusiva de `Despachador` (ver "Por qué es exclusiva de
> `Despachador` y no de `AdminCliente`" arriba) — es decir, `AdminCliente` nunca había tenido forma
> de llegar al mapa que esa historia le prometía. En vez de mover el mapa a otra pantalla, se le
> preguntó al usuario y se decidió abrir `/panel` también a `AdminCliente`.

### Objetivo / Alcance de esta corrección

Deja funcionando:

- El guard de `tenant-panel` en `router/index.ts` deja de redirigir a `AdminCliente`: ahora solo
  redirige a `tenant-clientes-lista` si el rol autenticado no es `Despachador` **ni** `AdminCliente`.
- El menú de `TenantLayout.vue` agrega el ítem "Panel" también para `AdminCliente`, como primer
  ítem (mismo lugar que ya ocupaba para `Despachador`), sin quitar ningún ítem existente de
  `AdminCliente`.
- `AdminCliente` ve exactamente el mismo contenido de `/panel` que `Despachador` (Servicios en
  turno, mapa, botón "Nueva Entrega") — no hay una versión reducida ni filtrada para este rol.
- El encuadre del mapa (`011-asignacion-ciudades-admin-cliente.md`) es el mismo para ambos roles:
  se calcula con la unión de ciudades de todos los `AdminCliente` del tenant (`ciudades_tenant`),
  no con las ciudades propias de quien inició sesión — así ambos ven siempre la misma vista, en vez
  de una personalizada por usuario.

**No** incluye:

- Ocultar o filtrar contenido de `/panel` según el rol — a diferencia de la corrección anterior
  (que sí reduce el menú de `Despachador`), aquí `AdminCliente` gana acceso sin restricciones
  adicionales dentro de la página.
- Ningún cambio al guard de `tenant-configuracion`, que sigue siendo exclusivo de `AdminCliente`.
- Ningún endpoint de backend nuevo — la asignación de ciudades y el campo `ciudades_tenant` se
  documentan en `011-asignacion-ciudades-admin-cliente.md`.

### Frontend (Vue 3)

- **`router/index.ts`** (`beforeEach`): la condición de `tenant-panel` cambia de
  `auth.usuario?.rol !== 'Despachador'` a
  `!['Despachador', 'AdminCliente'].includes(auth.usuario?.rol ?? '')`.
- **`layouts/TenantLayout.vue`**: el arreglo de ítems para roles distintos a `Despachador` agrega
  `{ label: 'Panel', to: '/t/${slug}/panel' }` al inicio, antes de "Pedidos".

### Fuera de alcance (de esta corrección)

- Cambios de contenido dentro de `/panel` según el rol.
- Cambios al guard de `tenant-configuracion`.

### Criterios de aceptación (de esta corrección)

1. Con sesión de `AdminCliente`, el menú de `TenantLayout` muestra "Panel" como primer ítem, antes
   de "Pedidos".
2. Visitar `/t/{slug}/panel` autenticado como `AdminCliente` muestra la página del panel (ya no
   redirige a Clientes).
3. El contenido de `/panel` es idéntico para `AdminCliente` y `Despachador`.
4. ESLint/Prettier corren sin errores.

### Supuestos asumidos (continúa el registro de arriba)

18. `/t/{slug}/panel` deja de ser exclusiva de `Despachador`: también entra `AdminCliente`, sin
    redirección ni error.
19. El encuadre del mapa dentro de `/panel` no se personaliza por usuario — es el mismo para
    cualquier tenant user que lo abra, calculado a partir de las ciudades de todos los
    `AdminCliente` del tenant (`ciudades_tenant`, spec 011), no de las ciudades propias de quien
    inició sesión.
20. `AdminCliente` no obtiene una versión reducida ni distinta de `/panel` — ve exactamente el
    mismo contenido que ya veía `Despachador`.

## Ampliación: el Panel es la vista principal, con el saldo de viajes del tenant sobre el mapa

> Historia del dueño del tenant: *"quiero ver el panel (donde está el mapa) como vista principal
> al abrir mi sistema, y dentro de la vista del mapa ver el número de viajes que tengo acreditados;
> también quiero ver cómo se van descontando del saldo sin recargar la página."*
>
> Esta ampliación cubre las dos primeras partes (aterrizar en el Panel y ver el número sobre el
> mapa). La tercera —que el número baje solo— vive en `027-panel-reactivo-sin-recargas.md`
> (versión 1.2), y la regla de negocio de *cuándo* se descuenta un viaje del tenant se reescribe en
> `015-configuracion-comisiones.md` (§"Corrección: el saldo del tenant se consume por envío
> entregado").

### Objetivo / Alcance de esta ampliación

Deja funcionando:

- **Aterrizaje en el Panel para quien opera.** Tras un login exitoso, el destino es `tenant-panel`
  si el usuario es el *operativo* del tenant según `011-gestion-despachadores-tenant.md`:
  `AdminCliente` con `usar_despachadores = No`, o `Despachador` con `usar_despachadores = Sí`. En
  cualquier otro caso el destino sigue siendo `tenant-clientes-lista`, como hoy. Lo que cambia en
  la práctica es el `AdminCliente` que opera él mismo su flotilla: hoy cae en Clientes y tiene que
  hacer clic en "Panel"; ahora cae directo en el mapa.
- **La raíz del tenant lleva al Panel.** Se agrega la ruta `/t/:slug` como redirección a
  `tenant-panel`. El guard ya existente hace el resto: sin sesión manda al login, y con sesión de un
  usuario no operativo manda a Clientes. Así "abrir mi sistema" (escribir la URL del tenant a secas,
  o un marcador viejo) termina en el mapa sin pasos intermedios.
  > **Enmendado** (tenant/001, "el slug va inmediatamente después del dominio"): la raíz del tenant
  > es `/:slug` y ya no redirige: es la propia ruta `tenant-login`. Sin sesión pinta el login ahí
  > mismo; con sesión, el guard la manda a `tenant-panel`. El resultado para quien tiene sesión es
  > el mismo (termina en el mapa o en Clientes); lo que cambia es que sin sesión la dirección no
  > se mueve.
- **Indicador de saldo de viajes del tenant sobre el mapa.** Componente nuevo
  `components/panel/SaldoViajesTenant.vue`, flotante sobre `MapaConductores`, siempre visible
  mientras el Panel esté abierto, con el saldo disponible del tenant como un solo número entero
  ("Saldo: 128 viajes"). Lo ven los dos roles que entran al Panel; no se oculta ni se reduce por rol
  ni por modalidad (Prepago/Comisión).
- **Aviso visual en cero o negativo, sin bloqueo.** Cuando el saldo es `<= 0` el indicador cambia a
  su variante de alerta (`bg-red-100 text-red-700`, mismo tono que ya usan los errores de
  formulario y el badge rojo) y el texto pasa a "Sin viajes disponibles" (en `0`) o "Saldo: -3 viajes"
  (en negativo). **No** se bloquea nada: los envíos se siguen creando, publicando y entregando. El
  bloqueo por falta de viajes sigue siendo una historia futura (spec `010-paquetes-viajes.md`).

**No** incluye:

- Cambios a quién es "operativo" ni a la exclusividad de crear envíos. El `AdminCliente` de un tenant
  con despachadores sigue **sin** entrar al Panel (spec 011, supuesto 13); su única lectura del saldo
  sigue siendo la pestaña Comisión/Prepago de Configuración. Se preguntó y se decidió mantener 011
  tal cual.
- Un historial de movimientos del saldo del tenant (qué envío consumió, cuándo). El indicador es
  solo el número actual; la bitácora que lo respalda (`consumos_viajes_tenant`, spec 015) queda
  lista para una pantalla futura.
- El cálculo del número y su actualización en vivo: el componente **no hace ninguna petición**; lee
  `saldoViajesTenant` del store del Panel (`stores/panel.ts`, spec 027 §12).

### Decisión técnica

#### El destino del login usa la misma regla que el guard, escrita una sola vez

Hoy la condición de "operativo" está escrita dos veces con el mismo texto —en el `beforeEach` de
`router/index.ts` y en el `computed` `esOperativo` de `TenantLayout.vue`— y `LoginView.vue` no la
usa: decide solo por rol. Meter una tercera copia en el login es pedir que un día una de las tres
diga otra cosa. La regla se extrae a un getter `esOperativo` en `stores/tenantAuth.ts` (ya tiene
`usuario.rol` y `usuario.usar_despachadores`, que `/login` y `/me` devuelven por
`AuthController::respuestaUsuario`), y los tres lugares lo consumen. Sin cambios de backend.

#### El indicador flota sobre el mapa, no va en la cabecera

`TenantLayout` ya carga la cabecera con el menú, el switch de ambiente (025), la campana y el
engranaje. El saldo es información **del Panel** —solo tiene sentido junto al mapa y a la flotilla—
y el dueño lo pidió literalmente "dentro de la vista mapa". Se coloca centrado en la franja superior
del mapa (`fixed top-[4.75rem] left-1/2 -translate-x-1/2 z-30`), entre los dos paneles laterales del
20 %, con el mismo `z-index` que la columna derecha y por debajo de la navbar (`z-50`) y de los
paneles deslizantes (`NuevaEntregaPanel`, `DetalleEnvioPanel`), que al abrirse pueden taparlo sin
problema: el dato vuelve a verse al cerrarlos.

No se reusa `UiCard`: igual que los paneles flotantes de 008/023, necesita `position: fixed` y un
tamaño que `UiCard` no ofrece. Es una píldora de una línea: `rounded-full bg-white/95 shadow-md
px-4 py-1.5 text-sm font-semibold`, con un ícono pequeño de ticket a la izquierda.

#### Se resalta cuando cambia

Para que "ver cómo se van descontando" sea visible y no solo cierto, el indicador reutiliza el
mecanismo de resaltado que ya tienen las filas del Panel (spec 027, RN-22: `resaltar(clave)` con
`estaResaltado`): al cambiar el número, la píldora hace el mismo destello breve que una fila
actualizada. Clave: `'saldo-tenant'`.

### Reglas de negocio

- **RN-A1:** El destino tras el login es `tenant-panel` si y solo si `auth.esOperativo`; en
  cualquier otro caso, `tenant-clientes-lista`.
- **RN-A2:** ~~`/t/:slug` redirige a `tenant-panel`; el guard existente decide después si esa persona
  puede estar ahí.~~ **Enmendado:** `/:slug` es la ruta `tenant-login`; con sesión, el guard la
  redirige a `tenant-panel` y ahí decide si esa persona puede estar (RN-S2 de tenant/001).
- **RN-A3:** El indicador muestra el saldo disponible del tenant (definición en spec 015
  §Corrección) como entero, en singular/plural correcto ("1 viaje", "2 viajes").
- **RN-A4:** Con saldo `<= 0` el indicador toma la variante de alerta; nunca bloquea ni deshabilita
  ninguna acción del Panel.
- **RN-A5:** El indicador no consulta al servidor: pinta lo que haya en el store. Mientras el store
  no tenga el valor (`saldoViajesTenant === null`, antes de la primera carga) muestra el mismo
  skeleton de una línea que usan los paneles (RN-16 de 027).

### Frontend (Vue 3)

- **`stores/tenantAuth.ts`**: getter nuevo `esOperativo` con la regla de 011.
- **`views/tenant/LoginView.vue`**: `const destino = auth.esOperativo ? 'tenant-panel' :
  'tenant-clientes-lista'`.
- **`router/index.ts`**: ~~ruta nueva `{ path: '/t/:slug', redirect: { name: 'tenant-panel' } }`
  (antes de las rutas hijas)~~ **Enmendado:** esa redirección desaparece; `/:slug` es
  `tenant-login` y la rama de `beforeEach` para esa ruta hace la redirección a `tenant-panel` cuando
  hay sesión (tenant/001, enmienda). El `beforeEach` sustituye su cálculo local por
  `auth.esOperativo`.
- **`layouts/TenantLayout.vue`**: `esOperativo` deja de calcularse ahí y pasa a leer el getter.
- **`components/panel/SaldoViajesTenant.vue`** (nuevo): lee `panel.saldoViajesTenant` y
  `panel.estaResaltado('saldo-tenant')`; sin props ni eventos.
- **`views/tenant/panel/PanelView.vue`**: monta `<SaldoViajesTenant />` junto a los demás
  componentes flotantes, con su comentario de origen como los otros.

### Fuera de alcance (de esta ampliación)

- Abrir el Panel al `AdminCliente` cuando usa despachadores.
- Historial de consumos del tenant.
- Cualquier bloqueo por saldo en cero.

### Criterios de aceptación (de esta ampliación)

1. Login como `AdminCliente` en un tenant con `usar_despachadores = No` aterriza en
   `/t/{slug}/panel`.
2. Login como `AdminCliente` en un tenant con `usar_despachadores = Sí` sigue aterrizando en
   Clientes; login como `Despachador` en ese mismo tenant aterriza en el Panel.
3. Visitar `/{slug}` (antes `/t/{slug}`) sin sesión muestra el login en esa misma dirección; con
   sesión operativa, al Panel; con sesión no operativa, a Clientes.
4. En el Panel se ve una píldora centrada sobre el mapa con "Saldo: N viajes", visible para
   `AdminCliente` y `Despachador`, en Prepago y en Comisión.
5. Con saldo `0` la píldora muestra "Sin viajes disponibles" en variante de alerta; con saldo
   negativo muestra el número negativo en la misma variante; "Nueva Entrega" sigue funcionando.
6. La píldora no genera ninguna petición HTTP propia (pestaña Red).
7. ESLint/Prettier corren sin errores.

### Supuestos asumidos (continúa el registro de arriba)

21. "Vista principal al abrir mi sistema" = destino del login **y** de la raíz del tenant
    (`/t/{slug}`, enmendado a `/{slug}` en tenant/001). No se recuerda la última página visitada.
22. ~~Todos los roles entran al Panel al iniciar sesión.~~ **Ajustado al preguntar**: solo el
    operativo de 011; el `AdminCliente` de un tenant con despachadores no cambia.
23. El saldo mostrado es un solo número (disponible), no el desglose por paquete comprado.
24. El indicador vive dentro del mapa como píldora flotante fija, no en la cabecera ni en un menú.
25. Lo ven ambos roles del Panel, sin distinción por modalidad de cobro.
26. En cero o negativo solo cambia de color y de texto; no bloquea nada.
27. Sin historial de movimientos del tenant en esta ampliación.
28. El componente es de solo lectura sobre el store del Panel: el dato, su carga inicial y su
    actualización en vivo se especifican en 027 §12.
