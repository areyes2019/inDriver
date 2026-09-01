# Spec: Actualización de la guía de diseño (paleta, tipografía e Iconify)

## Historia de usuario

Como desarrollador, quiero actualizar el sistema de diseño documentado en
[004-guia-diseno-base.md](004-guia-diseno-base.md) — paleta, tipografía, radios/sombras e
iconografía — tomando como referencia visual la guía de estilo "Sello Pronto" (paleta índigo,
tipografía Outfit/Inter, iconos a color), para que el panel de inDriver tenga una identidad visual
más moderna y consistente, sin rehacer la arquitectura de componentes `Ui*` ya existente.

## Objetivo / Alcance

Actualizar los tokens de `@theme` (`frontend/src/assets/main.css`), la tipografía cargada en
`index.html`, y los componentes `components/ui/` que dependen de esos tokens (`UiBadge`,
`UiNavbar`), agregando dos componentes nuevos (`UiChip`, `UiStat`). En paralelo, reemplazar
`@lucide/vue` por `@iconify/vue` como única librería de iconos, usando colecciones a color
(`flat-color-icons` / `fluent-color`) en vez de iconos monocromáticos o emojis. Solo `frontend/`;
sin cambios de backend ni de rutas. No rediseña `AdminLayout`/`TenantLayout` (se conserva el navbar
superior, no se adopta un sidebar fijo).

## Frontend (Vue 3)

### Paleta (`@theme` en `frontend/src/assets/main.css`)

Se actualizan/agregan estos tokens (reemplazando los valores actuales donde aplica):

```css
@theme {
  --color-neutral-primary: #ffffff;
  --color-heading: #0f172a;
  --color-body: #64748b;
  --color-default: #e2e8f0;

  --color-accent: #6366f1;        /* antes #4f46e5 */
  --color-accent-hover: #4f46e5;  /* nuevo */
  --color-accent-soft: #eef0ff;   /* nuevo */

  --color-warning-bg: #fef3c7;    /* nuevo — pendientes / cola */
  --color-warning-text: #92400e;
  --color-info-bg: #dbeafe;       /* nuevo — informativo / flotilla */
  --color-info-text: #1e40af;
  --color-success-bg: #d1fae5;    /* nuevo — éxito / saldo */
  --color-success-text: #065f46;
  --color-purple-bg: #f3e8ff;     /* nuevo — programado / zonas */
  --color-purple-text: #6d28d9;

  --radius-sm: 6px;   /* nuevo */
  --radius-md: 10px;  /* nuevo */
  --radius-lg: 16px;  /* nuevo */

  --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);                                   /* nuevo */
  --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -1px rgb(0 0 0 / 0.03); /* nuevo */
  --shadow-hover: 0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -2px rgb(0 0 0 / 0.04); /* nuevo */

  --font-sans: 'Inter', ui-sans-serif, system-ui, sans-serif;   /* nuevo, reemplaza el default de Tailwind */
  --font-heading: 'Outfit', sans-serif;                          /* nuevo */
}
```

Como `--radius-*` y `--shadow-*` están en el namespace de tema de Tailwind v4, las utilidades
`rounded-sm/md/lg` y `shadow-sm/md/hover` quedan disponibles automáticamente en toda la app — no
hace falta tocar cada componente que ya usa `rounded-lg`/`rounded-2xl`, solo revisar que seguirán
viéndose bien con la nueva escala (6/10/16px en vez del default de Tailwind).

### Tipografía

- `index.html` reemplaza el `<link>` de Montserrat por:
  `Outfit:wght@400;600;700;800` + `Inter:wght@400;500;600;700` (Google Fonts), sin JetBrains Mono
  (no hay bloques de código en el panel).
- `base.css` agrega la regla `h1, h2, h3, h4 { font-family: var(--font-heading); }` — el resto del
  texto usa `--font-sans` (Inter) vía el `body` que ya existe.
- No se toca la escala de tamaños (`text-sm`, `text-2xl`, etc.), solo la familia tipográfica.

### `UiNavbar.vue`

- La franja de gradiente (línea 26) pasa de `bg-gradient-to-r from-pink-500 to-purple-600` a
  `bg-gradient-to-r from-accent via-[#8b5cf6] to-[#ec4899]` (índigo → violeta → rosa, 3 colores).
- Los 4 iconos Lucide (`Bell`, `Menu`, `Settings`, `X`) se reemplazan por `<Icon>` de Iconify (ver
  sección de iconografía).

### `UiBadge.vue`

Se conserva el prop `color` con los mismos 5 valores que ya usan las 14 vistas existentes
(`orange`/`blue`/`green`/`red`/`gray`) — **no se renombra el prop ni se tocan los call sites** que
ya lo usan (`ListaPedidosView`, `ListaTenantsView`, `ListaConductoresView`, etc.), para no expandir
el alcance a esas 14 vistas. Internamente, cada valor deja de usar clases sueltas de Tailwind
(`bg-orange-100 text-orange-700`) y pasa a usar los tokens nuevos:

| `color` (prop, sin cambios) | Antes (Tailwind suelto)          | Ahora (tokens)                                  |
| ---------------------------- | --------------------------------- | ------------------------------------------------ |
| `orange`                     | `bg-orange-100 text-orange-700`   | `bg-warning-bg text-warning-text`                 |
| `blue`                       | `bg-blue-100 text-blue-700`       | `bg-info-bg text-info-text`                       |
| `green`                      | `bg-green-100 text-green-700`     | `bg-success-bg text-success-text`                 |
| `red`                        | `bg-red-100 text-red-700`         | sin cambio (no hay token "danger" en el artifact) |
| `gray`                       | `bg-slate-100 text-slate-700`     | sin cambio                                        |

Se agrega un 6° valor **`purple`** (`bg-purple-bg text-purple-text`) para casos tipo
"programado"/"zonas" que hoy no tienen equivalente — opcional, ningún call site existente está
obligado a usarlo.

### Componentes nuevos

- **`UiChip.vue`**: chip con punto pulsante (equivalente a `.c-chip` del artifact). Props:
  `text: string`. Usa `--color-warning-bg`/`--color-warning-text` y un punto animado
  (`animate-pulse` de Tailwind) en `#f59e0b`. Ejemplo de uso: `"12 en cola"`.
- **`UiStat.vue`**: tarjeta KPI (equivalente a `.c-stat`). Props: `icon: string` (nombre de ícono
  Iconify), `iconBg?: string` (color de fondo del círculo del ícono, default
  `--color-accent-soft`), `label: string`, `value: string | number`. Usa `--radius-lg` y
  `--shadow-sm`.

### Iconografía (Iconify)

- Se instala `@iconify/vue`. Se instalan además `@iconify-json/flat-color-icons` y
  `@iconify-json/fluent-color` como **datos locales** (no se llama a la API pública de Iconify en
  producción), configurados para que el build (Vite) solo incluya los íconos realmente usados en el
  código — no el paquete de datos completo — evitando el sobrepeso de bundle que tendría un
  `addCollection` con el JSON entero de `fluent-color` (varios miles de íconos).
- Familia principal: `flat-color-icons`. Familia de respaldo: `fluent-color`, solo para íconos de
  negocio (conductor, paquete, ruta) que no existan en la primera.
- Se elimina `@lucide/vue` de `package.json`. Usos reales a migrar:
  - `UiNavbar.vue`: `Bell`, `Menu`, `Settings`, `X` (funcionales).
  - `StyleGuideView.vue`: `Rocket`, `LayoutDashboard`, `Car`, `Package`, `TrendingUp`, `Settings`,
    `Bell`, `Menu`, `X`, `Search`, `Zap` — son la grilla de "Iconos disponibles" de la página de
    documentación; se reemplaza esa grilla por una muestra de los íconos de Iconify elegidos, no es
    una migración 1:1 ícono por ícono.
  - Tabla de equivalencias (nombre exacto del ícono de Iconify a confirmar durante la
    implementación, buscando en el catálogo de `flat-color-icons`/`fluent-color`; si un nombre no
    existe en ninguna de las dos colecciones se documenta la excepción aquí):

    | Uso (Lucide actual) | Contexto                        | Colección propuesta |
    | -------------------- | -------------------------------- | -------------------- |
    | `Bell`                | Botón de notificaciones (navbar) | `flat-color-icons`   |
    | `Menu` / `X`           | Botón de menú móvil (navbar)     | `flat-color-icons`   |
    | `Settings`             | Botón de configuración (navbar)  | `flat-color-icons`   |
    | `Zap`                  | Ícono decorativo de tarjeta demo | `flat-color-icons`   |

- Tamaño: se usa el prop `width`/`height` del componente `<Icon>` (ej. `width="20" height="20"` en
  navbar, `width="32" height="32"` en `UiStat`), no clases `h-*`/`w-*` de Tailwind.
- Accesibilidad: íconos decorativos (acompañados de texto que ya explica su función) llevan
  `aria-hidden="true"`; los botones que solo tienen ícono conservan el `aria-label` que ya usa
  `UiNavbar` (sin cambios en ese patrón).
- No se usan emojis como ícono en ningún componente nuevo (`UiStat`, `UiChip`) ni en la migración.

### Página `/admin/style-guide` (`StyleGuideView.vue`)

Se actualiza para reflejar los cambios:

- Sección "Colores": agrega los swatches nuevos (`accent-hover`, `accent-soft`,
  `warning`/`info`/`success`/`purple` bg+text).
- Sección "Tipografía": actualiza el texto ("Sin fuente externa") y agrega la muestra con Outfit
  (títulos) e Inter (cuerpo).
- Sección "Iconos": renombra "Iconos (lucide-vue)" a "Iconos (Iconify)", reemplaza la grilla de
  `<component :is="...">` de Lucide por `<Icon icon="...">` de Iconify con los nombres elegidos.
- Sección "Badges": agrega el ejemplo `<UiBadge text="5 programados" color="purple" />`.
- Nuevas secciones "Chip (UiChip)" y "Stat (UiStat)" con ejemplos de uso.

## Fuera de alcance

- Rediseño de `AdminLayout`/`TenantLayout` (sidebar fijo, `--sidebar-width`/`--topbar-height` del
  artifact) — se mantiene el navbar superior actual.
- Modo oscuro (los tokens `dark` del artifact no se replican), igual que la spec 004.
- Cambiar el prop `color` de `UiBadge` a nombres semánticos (`pending`/`scheduled`/etc.) en los 14
  call sites existentes — se mantiene el prop y sus 5 valores actuales, solo cambia el color
  renderizado por dentro.
- shadcn-vue, Reka UI, JetBrains Mono, bloques de código en el panel.
- Cualquier cambio de backend, rutas o lógica de negocio.
- Auditoría formal de accesibilidad (solo `aria-hidden`/`aria-label` en los íconos, igual alcance
  que la spec 004).

## Criterios de aceptación

1. `@theme` en `main.css` tiene los tokens nuevos/actualizados de paleta, radios y sombras listados
   arriba; `--color-accent` cambia de `#4f46e5` a `#6366f1`.
2. `index.html` carga Outfit + Inter (no Montserrat); los `h1`–`h4` de la app usan
   `--font-heading` (Outfit) y el resto del texto usa `--font-sans` (Inter).
3. `UiNavbar.vue` usa el gradiente de 3 colores y renderiza sus 4 íconos con `<Icon>` de Iconify.
4. `UiBadge.vue` conserva el prop `color` con los mismos 5 valores + el nuevo `purple`; internamente
   usa los tokens de `@theme` en vez de clases Tailwind sueltas. Ninguno de los 14 call sites
   existentes cambia su código.
5. Existen `UiChip.vue` y `UiStat.vue` en `components/ui/`, tipados con `defineProps`/
   `withDefaults`, documentados en `/admin/style-guide`.
6. `package.json` ya no depende de `@lucide/vue`; depende de `@iconify/vue`,
   `@iconify-json/flat-color-icons` y `@iconify-json/fluent-color`. Ningún componente usa emojis
   como ícono.
7. Los íconos de `<Icon>` funcionan sin conexión a internet (datos locales, no llamada a la API
   pública de Iconify) y el build no incluye el JSON completo de las colecciones (solo los íconos
   usados).
8. `/admin/style-guide` documenta todos los tokens y componentes nuevos/cambiados de esta spec.
9. `npm run build` compila sin errores de tipos; `npm run lint` corre sin errores sobre el código
   nuevo/modificado.
10. Ninguna pantalla fuera de `UiNavbar`, `UiBadge`, `UiChip`, `UiStat` y `StyleGuideView` cambia de
    código en esta historia (backend, rutas y demás vistas quedan intactas).

## Supuestos asumidos (registro completo)

1. Spec numerada como `003` (hueco libre en `specs/`), documentada como actualización de
   [004-guia-diseno-base.md](004-guia-diseno-base.md).
2. Alcance solo `frontend/`; sin cambios de backend ni de rutas.
3. Tipografía: Outfit (títulos, vía `--font-heading`) + Inter (cuerpo, vía `--font-sans`),
   reemplaza la decisión previa de "sin fuente externa" y la carga actual de Montserrat (que no
   tenía ningún token `--font-sans` explícito apuntándole).
4. Acento actualizado a `#6366f1`, con `--color-accent-hover` (`#4f46e5`) y `--color-accent-soft`
   (`#eef0ff`) como tokens nuevos.
5. Se agregan tokens semánticos nuevos (`warning`/`info`/`success`/`purple`, bg+text) en `@theme`,
   revirtiendo la decisión previa de "sin tokens semánticos nuevos, solo clases nativas de
   Tailwind" — pero sin renombrar el prop `color` de `UiBadge` ni tocar sus 14 call sites (decisión
   de implementación tomada al redactar esta spec, para no expandir el alcance).
6. Radios y sombras formalizados como tokens `--radius-sm/md/lg` (6/10/16px) y
   `--shadow-sm/md/hover`, aprovechando que Tailwind v4 genera las utilidades `rounded-*`/`shadow-*`
   automáticamente desde el namespace de tema.
7. Layout: se conserva el navbar superior (`UiNavbar`); no se adopta el sidebar fijo del artifact.
8. Franja de gradiente de `UiNavbar` actualizada a 3 colores (`accent → #8b5cf6 → #ec4899`).
9. Componente nuevo `UiChip` (punto pulsante).
10. Componente nuevo `UiStat` (ícono + label + valor).
11. `/admin/style-guide` documenta todo lo nuevo/cambiado.
12. Modo oscuro fuera de alcance.
13. Sin reinstalar el build ni agregar shadcn-vue/Reka UI.
14. Se instala `@iconify/vue`; todo ícono nuevo usa `<Icon icon="...">`.
15. Se elimina `@lucide/vue` por completo. Los usos reales (`UiNavbar`: 4 íconos) y los
    demostrativos (`StyleGuideView`: 11 íconos, incluido `Zap`) se migran dentro de esta spec.
16. Se prohíben emojis como ícono en los componentes nuevos/migrados de esta spec.
17. Familia principal `flat-color-icons`, con `fluent-color` como respaldo para íconos de negocio no
    cubiertos por la primera.
18. Tamaño de ícono vía props `width`/`height` de `<Icon>`, no clases `h-*`/`w-*`.
19. Los 4 usos de Lucide en `UiNavbar` (no eran solo 4 en toda la app: también hay 11 en la grilla
    demostrativa de `StyleGuideView`) se migran dentro del alcance de esta spec; la grilla de
    `StyleGuideView` se reemplaza por una muestra de Iconify, no por una migración ícono-por-ícono.
20. `/admin/style-guide` agrega sección de iconografía con la colección elegida y tamaños estándar.
21. Se instalan los paquetes de datos locales de Iconify (`@iconify-json/flat-color-icons`,
    `@iconify-json/fluent-color`) configurados con tree-shaking (solo los íconos usados en el
    código quedan en el bundle final), para evitar tanto la dependencia de la API pública como el
    sobrepeso de incluir la colección completa.
22. Íconos decorativos llevan `aria-hidden="true"`; los botones solo-ícono conservan el
    `aria-label` que ya usa `UiNavbar` — sin cambios a ese patrón existente.
23. Se arma una tabla de equivalencias Lucide → Iconify antes de tocar código; los nombres exactos
    de los íconos elegidos se confirman durante la implementación (búsqueda en el catálogo real de
    Iconify), no se inventan en esta spec.
24. Verificación final: `npm run build` y `npm run lint` sin errores, igual que exigió la spec 004.
25. `UiBadge` conserva su prop `color` y sus 5 valores actuales (compatibilidad con los 14 call
    sites existentes); se agrega un 6° valor opcional `purple`. Ningún call site existente cambia
    de código en esta historia.
