# Spec: Guía de diseño base (design system) del panel de inDriver

## Historia de usuario

Como usuario del panel administrativo de inDriver, quiero que el panel de control tenga el estilo
visual de una referencia dada, 100% responsivo y construido con Tailwind CSS, con una paleta de
colores y tipografía definidas, para que todas las pantallas de frontend que se construyan en
specs futuras compartan un mismo lenguaje visual en vez de inventar el estilo cada vez.

## Objetivo / Alcance

Establecer el sistema de diseño base — paleta de colores, tipografía, layout de sidebar +
contenido, y un set mínimo de componentes de UI reutilizables — que sirve de referencia obligatoria
para toda spec de frontend futura de inDriver. Es la primera vez que se instala Tailwind CSS en
`frontend`. **No** construye ninguna pantalla de negocio real: "Devices", "Schedule", "Split
system" y "AI power analytics" de la referencia visual son solo ejemplos de estilo (card, spacing,
tipografía), no funcionalidades a implementar.

## Decisión técnica

- Se instala y configura Tailwind CSS desde cero en `frontend` (Vue 3 + Vite), primera vez en el
  proyecto.
- Paleta y tipografía se definen como tokens con nombre en `tailwind.config`, nunca como hex
  sueltos en los componentes:
  - `brand.dark` → `#000814`
  - `brand.yellow` → `#ffc300`
  - `brand.blue` → `#003566`
  - `fontFamily.sans` → `Montserrat` primero, con la pila por defecto de Tailwind como respaldo.
- Montserrat se carga vía Google Fonts (pesos 400/500/600/700).
- Íconos: `lucide-vue-next` (estilo de línea simple, igual al de la referencia).
- La gráfica de barras del ejemplo se construye solo con HTML/Tailwind (`<div>`s con alto
  proporcional al valor), sin instalar ninguna librería de charting.
- Los componentes de UI reutilizables viven en `frontend/src/components/ui/`, separados de
  cualquier vista concreta, para que specs futuras los reutilicen en vez de repetir el diseño.
- El layout del panel (sidebar + contenido) mide exactamente el alto de la pantalla (viewport) y
  nunca genera scroll en la página completa: el sidebar queda siempre fijo e inmóvil, y solo el
  área de contenido (a la derecha) tiene scroll interno propio cuando su contenido no cabe en la
  pantalla. Mismo patrón ya usado en las vistas de auth (spec 006).
- En cualquier bloque de contenido (formulario de campos apilados, o lista de datos en solo
  lectura) seguido de un botón o enlace de acción, ese botón lleva más espacio por encima del que
  ya separa a los elementos internos del bloque entre sí, y se distingue del contenido con una
  línea divisoria sutil (borde superior), para que se perciba como la acción final y no como un
  campo o dato más.

## Frontend (Vue 3)

- **Dependencias nuevas**: `tailwindcss` y lo necesario para integrarlo con Vite, más
  `lucide-vue-next`.
- **`tailwind.config`**: tema extendido con `colors.brand.{dark,yellow,blue}` y
  `fontFamily.sans = ['Montserrat', ...]`.
- **Componentes nuevos en `frontend/src/components/ui/`**:
  - `UiCard.vue` — tarjeta contenedora (fondo blanco, esquinas redondeadas, sombra suave), con
    slots para encabezado y contenido.
  - `UiToggle.vue` — interruptor on/off (`modelValue`, `disabled`), estilo del interruptor de la
    referencia.
  - `UiBadge.vue` — insignia pequeña (`text`, `color`), como el badge verde "2" del Schedule de la
    referencia.
  - `UiSidebar.vue` — menú lateral: logo, lista de ítems (ícono + texto + ruta), estado
    expandido/colapsado; en mobile se oculta detrás de un botón/overlay en vez de mostrarse fijo.
  - `UiBarChart.vue` — gráfica de barras simple (`data: {label, value}[]`), barras hechas con
    `<div>`, con un tooltip simple al pasar el mouse/tocar una barra.
- **`frontend/src/layouts/AdminLayout.vue`**: envuelve el contenido con `UiSidebar.vue` (ítems de
  ejemplo: Dashboard, Style guide) + área de contenido responsiva. Se aplica al `DashboardView.vue`
  placeholder ya existente (spec 004) para tener un lugar real donde verlo funcionando, sin agregar
  lógica de negocio nueva ni tocar el guard de sesión existente.
- **`frontend/src/views/admin/StyleGuideView.vue`**: muestra en una sola página la paleta de
  colores, la tipografía en distintos tamaños/pesos, y cada componente de `ui/` con datos de
  ejemplo (incluye `UiBarChart` con datos ficticios tipo la referencia).
- **Ruta `/admin/style-guide`** (`router/index.ts`): se registra solo cuando
  `import.meta.env.DEV` es verdadero — no existe en el build de producción. No requiere sesión
  iniciada: es una herramienta interna de desarrollo, no una pantalla del producto.

## Fuera de alcance

- Pantallas de negocio reales inspiradas en la referencia (Devices, Schedule, Power analytics,
  Split system, Notification, Documentation, Settings) — son ejemplos de estilo, no
  funcionalidades a construir.
- Modo oscuro/claro alternable — el estilo visual es fijo.
- Cualquier gráfica conectada a datos reales o librería de charting de terceros.
- Reorganización de las rutas o el guard de sesión de ADMIN_CENTRAL ya definidos en
  `004-auth-admin-central.md`.
- Diseño para el futuro panel de AdminCliente/Despachador/Conductor (usuarios de tenant) — esta
  guía cubre el frontend de admin actual; si esos paneles terminan en otro proyecto, se evalúa
  aparte.

## Criterios de aceptación

1. Tailwind CSS está instalado y funcionando en `frontend`; `npm run build` compila sin errores
   usando clases de Tailwind.
2. `tailwind.config` define `brand.dark` (#000814), `brand.yellow` (#ffc300), `brand.blue`
   (#003566) y `fontFamily.sans` con Montserrat como primera opción.
3. Montserrat se aplica por defecto en todo el texto bajo `/admin/*`.
4. Existen `UiCard`, `UiToggle`, `UiBadge`, `UiSidebar` y `UiBarChart` en
   `frontend/src/components/ui/`, cada uno usable de forma independiente con props tipadas.
5. `UiSidebar` se ve completo en desktop (expandido) y colapsa/oculta en pantallas angostas
   (mobile), sin generar scroll horizontal.
6. El sidebar ocupa siempre el 100% del alto de la pantalla y permanece inmóvil (no se desplaza,
   no muestra su propia barra de scroll) al hacer scroll en el contenido; solo el área de
   contenido a la derecha del sidebar tiene scroll interno propio cuando su contenido excede el
   alto de la pantalla. Aplica tanto en desktop como en mobile (drawer/overlay).
7. En `StyleGuideView`, las cards se reacomodan en una sola columna en mobile y en grid en
   desktop, sin overflow horizontal en ningún ancho de pantalla.
8. `/admin/style-guide` solo existe cuando `import.meta.env.DEV` es verdadero — no aparece en el
   build de producción (`npm run build` y revisar `dist`, o revisar el guard de la ruta).
9. `AdminLayout.vue` envuelve el `DashboardView.vue` existente con el sidebar nuevo, sin romper el
   guard de sesión que ya existe (sigue redirigiendo a `/admin/login` sin sesión activa).
10. `lucide-vue-next` está instalado; los íconos usados en el sidebar y en `StyleGuideView` se
    renderizan correctamente.
11. `UiBarChart` no depende de ninguna librería de charting — solo Vue y Tailwind.
12. ESLint/Prettier corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. Esta spec no construye pantallas de negocio reales — los ejemplos de la referencia (Devices,
   Schedule, Split system, Power analytics) son solo estilo a copiar, no features de inDriver.
2. Lo que se construye es un layout base reutilizable: sidebar + área de contenido con cards
   genéricas, sin datos ni lógica de negocio.
3. La paleta es exactamente `#000814` (oscuro), `#ffc300` (amarillo/acento), `#003566` (azul) —
   guardada como tokens con nombre en Tailwind, no como hex sueltos en los componentes.
4. Montserrat reemplaza la fuente actual de todo el proyecto, cargada vía Google Fonts.
5. "100% responsivo" implica sidebar colapsable/oculto en mobile y cards en una sola columna,
   mobile-first con Tailwind.
6. Esta es la primera instalación de Tailwind CSS en `frontend`; se configura desde cero.
7. Esta spec se documenta como guía de estilo (paleta, tipografía, componentes base) en vez de
   como feature de negocio — las specs futuras la referencian en lugar de repetir estas decisiones.
8. Los ítems del sidebar de la referencia son placeholders de navegación de ejemplo; las rutas
   reales ya existentes (`/admin/login`, etc. de la spec 004) no se reorganizan, solo se les agrega
   el shell visual mediante `AdminLayout.vue`.
9. No se requiere modo oscuro/claro alternable; el estilo es fijo (sidebar oscuro, cards claras).
10. Los componentes visuales complejos de la referencia (gráfica de barras, dial circular) se
    agregan como ejemplos de la guía de estilo, sin conectarse a datos reales.
11. Colores y tipografía se definen como tokens con nombre en `tailwind.config`
    (`brand.dark/yellow/blue`, `fontFamily.sans`), no como códigos sueltos repetidos en cada
    componente.
12. Los componentes de UI (`UiCard`, `UiToggle`, `UiBadge`, `UiSidebar`, `UiBarChart`) se guardan
    en `frontend/src/components/ui/`, separados de las vistas, para reutilizarse en specs futuras.
13. Los íconos se resuelven con la librería `lucide-vue-next`.
14. La gráfica de barras de ejemplo se hace solo con HTML/Tailwind (`<div>`s de alto variable), sin
    instalar ninguna librería de charting.
15. Se agrega una pantalla `/admin/style-guide` que junta todas las piezas (colores, tipografía,
    componentes) en una sola página, registrada solo cuando `import.meta.env.DEV` es verdadero
    (no existe en producción), sin requerir sesión iniciada.
16. (Corrección) El layout de `AdminLayout.vue` mide exactamente el alto de la pantalla y nunca
    genera scroll en la página completa. El sidebar (`UiSidebar`) queda siempre fijo e inmóvil, sin
    scroll propio; solo el área de contenido a la derecha tiene scroll interno cuando su contenido
    no cabe en la pantalla. Mismo patrón de layout ya usado en las vistas de auth (spec 006:
    `h-screen` + `overflow-y-auto` en el contenido).
17. En cualquier bloque de contenido (formulario de campos apilados, o lista de datos en solo
    lectura) seguido de un botón o enlace de acción, ese botón lleva más espacio por encima que el
    que ya separa a los elementos internos del bloque, y se distingue del contenido con una línea
    divisoria sutil (borde superior), para que se perciba como la acción final y no como un campo o
    dato más.
