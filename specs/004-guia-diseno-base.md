# Spec: Guía de diseño base

## Historia de usuario

Como desarrollador, quiero documentar y completar el sistema de diseño ya usado en el panel de
inDriver (paleta, tipografía, componentes base en `components/ui/`), para que las pantallas nuevas
y existentes se construyan de forma consistente sin inventar estilos sueltos en cada vista.

## Objetivo / Alcance

Documentar el sistema de diseño ya instalado (Tailwind CSS v4, tokens en `@theme`, iconos
`lucide-vue`) y completar el inventario de componentes base con `UiButton`, `UiInput` y `UiAlert`
— los que faltaban de la lista original (Button, Input, Card, Alert, Badge, Modal). Aplicar esos 3
componentes nuevos a las pantallas de acceso (`/admin/login`, `/admin/forgot-password`,
`/admin/reset-password`) reemplazando el markup repetido. No reinstala Tailwind (ya está), no
cambia rutas, no toca `AdminLayout`/`TenantLayout`, ni el backend.

## Frontend (Vue 3)

- **Tailwind CSS v4**, ya instalado vía el plugin oficial de Vite (`@tailwindcss/vite`).
- **Sin librería de componentes externa**: no se usa shadcn-vue ni Reka UI. Todos los componentes
  base son propios, en `components/ui/`, con `defineProps`/`withDefaults` tipados — el mismo patrón
  que ya siguen `UiCard`/`UiBadge`.
- **Paleta**: se usan los tokens ya definidos en `@theme` (`frontend/src/assets/main.css`):
  `--color-heading` (#0f172a), `--color-body` (#64748b), `--color-default` (#e2e8f0),
  `--color-accent` (#4f46e5), `--color-neutral-primary` (#ffffff). No se agregan tokens semánticos
  de éxito/error/advertencia; los componentes que necesitan variantes de color (`UiBadge`,
  `UiAlert`) usan clases nativas de Tailwind (red/green/orange/blue/gray) directamente, igual que ya
  hace `UiBadge`.
- **Tipografía**: pila sans-serif nativa del sistema operativo del usuario, sin fuente externa
  (Google Fonts) cargada.
- **Iconografía**: `@lucide/vue`.
- **Componentes base en `components/ui/`**:
  - Ya existentes: `UiCard`, `UiBadge`, `UiToggle`, `UiNavbar`, `UiConfirmDialog` (cumple el rol de
    "Modal"), `UiBarChart`, `UiStatusBar`, `UiPersonListItem`.
  - Nuevos en esta historia:
    - `UiButton`: variantes `primary`/`secondary`, estado `disabled`, tamaño único.
    - `UiInput`: junta etiqueta (label) + campo de texto + mensaje de error opcional.
    - `UiAlert`: banner de aviso con variantes `success`/`error`/`warning`/`info`, con colores
      nativos de Tailwind (sin tokens nuevos).
- **Página de documentación viva**: `/admin/style-guide` (`StyleGuideView.vue`), ya existente,
  gateada a `import.meta.env.DEV` — se le agrega una sección por cada componente nuevo con sus
  variantes.
- **Pantallas de acceso** (`LoginView.vue`, `ForgotPasswordView.vue`, `ResetPasswordView.vue`)
  migran su `<input>`/`<button>`/mensaje de error sueltos a `UiInput`/`UiButton`/`UiAlert`. Sin
  cambios de lógica (`onSubmit`, store, rutas) ni de layout visual — mismo alcance/decisiones que la
  spec [006](006-rediseno-login-admin.md) (layout de dos columnas, sin `AuthLayout` compartido).
- Responsive mobile-first con breakpoints default de Tailwind (ya así en las pantallas existentes).
- Modo oscuro: fuera de alcance.

## Reglas de arquitectura para componentes con `v-model`

Un ref de `defineModel` no se puede leer de vuelta en el mismo tick en que se escribió si el
componente padre lo usa con `v-model`: la lectura devuelve el valor anterior, porque `useModel` de
Vue solo sincroniza el valor local cuando el padre devuelve la prop nueva en el siguiente ciclo de
render. La regla: cualquier manejador que escriba el modelo y en la misma pasada necesite el valor
nuevo (emitir otro evento, derivar un dato, decidir una navegación) debe usar el valor que ya tiene
a mano (el argumento del evento o una variable local), no leer el ref recién escrito. Sin regla de
ESLint posible (no se distingue estáticamente de un uso legítimo); queda como regla escrita para
cualquier componente futuro de `components/ui/` que use `defineModel` (p. ej. `UiToggle`).

## Fuera de alcance

- shadcn-vue, Reka UI o cualquier kit de componentes externo.
- Tokens de color semánticos nuevos (éxito/error/advertencia) en `@theme`.
- Fuentes externas (Google Fonts).
- Rediseño de `AdminLayout`, `TenantLayout`, `DashboardView` u otras pantallas fuera de las 3 de
  acceso.
- Sistema de Toast/notificaciones flotantes (solo `Alert` como banner estático).
- Componente `Select` propio y su regla de valor centinela para "ninguno" (no existe ese primitivo
  en inDriver todavía; se documentará cuando se necesite).
- Auditoría formal de accesibilidad (solo se busca contraste razonable).
- Cualquier cambio de backend.

## Criterios de aceptación

1. Existen `UiButton.vue`, `UiInput.vue` y `UiAlert.vue` en `components/ui/`, tipados con
   `defineProps`/`withDefaults`, estilizados con los tokens ya definidos en `@theme`.
2. `LoginView.vue`, `ForgotPasswordView.vue` y `ResetPasswordView.vue` usan `UiInput`/`UiButton`/
   `UiAlert` en vez de `<input>`/`<button>`/`<p>` sueltos, sin cambiar su lógica de envío ni las
   rutas.
3. `/admin/style-guide` documenta `UiButton`, `UiInput` y `UiAlert` con sus variantes, igual que ya
   hace con los componentes existentes.
4. Ningún componente nuevo agrega tokens de color a `@theme`; las variantes de `UiAlert` usan
   clases nativas de Tailwind.
5. No se instala shadcn-vue, Reka UI, ni ninguna fuente externa.
6. `npm run build` compila sin errores; ESLint/Prettier corren sin errores sobre el código nuevo.
7. Las 3 pantallas de acceso se ven visualmente igual que antes (mismo layout de dos columnas de la
   spec 006); el cambio es solo de composición interna (componentes en vez de markup repetido).

## Supuestos asumidos (registro completo)

1. Alcance: solo `frontend/` de inDriver; sin cambios de backend.
2. Tailwind v4 ya estaba instalado — esta historia solo lo documenta, no lo reinstala.
3. Se descarta shadcn-vue/Reka UI por completo: se documenta y completa el set de componentes ya
   propio (`Ui*`), no se reemplaza por un kit externo.
4. Paleta: se conserva la ya existente en `@theme` (`heading`/`body`/`default`/`accent`/
   `neutral-primary`); no se agregan tokens semánticos de éxito/error/advertencia nuevos. Los
   componentes con variantes de color usan clases nativas de Tailwind sueltas, como ya hace
   `UiBadge`.
5. Tipografía: se conserva "sin fuente externa"; no se agrega Google Fonts ni ninguna familia
   tipográfica nueva.
6. Iconografía: se conserva `@lucide/vue`; no se agrega Heroicons ni otra librería de íconos.
7. Componentes base a completar: `UiButton`, `UiInput`, `UiAlert` — los únicos que faltaban del
   inventario original (Button, Input, Card, Alert, Badge, Modal); el resto ya existía.
8. Spacing/tokens: ya centralizados en `@theme`, sin cambios de fondo.
9. Responsive mobile-first con breakpoints default de Tailwind: ya cumplido, sin cambios.
10. Modo oscuro: fuera de alcance.
11. Identidad visual: `logo.svg` + "inDriver" ya son la marca real (no un placeholder de texto
    genérico).
12. No se rehacen wireframes en gris de pantallas ya implementadas — el layout final de las 3
    pantallas de acceso y el dashboard ya existe.
13. La "página de design system" ya existe con el nombre `/admin/style-guide` (no `/design-system`)
    — se conserva esa ruta.
14. Se descarta la sección de reglas `NavigationMenu`/`DropdownMenu`/`Popover` de Reka UI: `UiNavbar`
    es un componente propio sin esos primitivos.
15. Se descarta la regla del valor centinela en `Select`: no existe ese componente en inDriver.
16. La regla de "escribir y leer `defineModel` en el mismo manejador" se conserva como regla general
    (no depende de shadcn-vue), aplicable a cualquier componente propio con `v-model`.
17. Las reglas de `Dialog` con contenido dinámico y de ancho de tabla/`AppLayout` de la spec
    original no se trasladan tal cual (son de Reka UI / de otro layout); se documentarán en esta
    spec si el problema concreto aparece en `UiConfirmDialog`/`AdminLayout`/`TenantLayout`.
18. `UiButton` cubre dos variantes (`primary`/`secondary`); no se agregan variantes de tamaño ni
    variante `danger` hasta que una pantalla concreta lo necesite.
19. `UiInput` solo cubre `type="text"`/`"email"`/`"password"` con label + error, sin soporte para
    otros tipos de control (select, textarea, file) en esta historia.
20. El checkbox "Recordarme" de `LoginView` (spec 006, puramente visual) no se convierte en
    componente propio en esta historia — se deja como `<input type="checkbox">` suelto.
