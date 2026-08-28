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
