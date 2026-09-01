# Spec: Rediseño visual del login, recuperación y restablecimiento de contraseña de ADMIN_CENTRAL

## Historia de usuario

Como ADMIN_CENTRAL, quiero que las pantallas de iniciar sesión, recuperar contraseña y
restablecer contraseña tengan un diseño de dos columnas (imagen + formulario) coherente con la
guía de diseño del panel (`005-guia-diseno-base.md`), en vez del formulario sin estilo que existe
hoy (`004-auth-admin-central.md`), para que el primer contacto con el sistema se vea profesional y
consistente con el resto de la marca.

## Objetivo / Alcance

Rediseñar visualmente `LoginView.vue`, `ForgotPasswordView.vue` y `ResetPasswordView.vue` ya
existentes (spec 004), aplicando un layout de dos columnas (imagen a la izquierda, formulario a la
derecha) y los tokens de color/tipografía de la spec 005. **No** crea pantallas nuevas, **no**
cambia rutas, el store de auth, ni ningún endpoint del backend — es un cambio puramente visual
sobre pantallas que ya funcionan.

## Decisión técnica

- Cada una de las 3 vistas repite su propio markup del layout de dos columnas — no se crea un
  componente de layout compartido (`AuthLayout.vue` o similar) entre ellas; es una decisión
  explícita para mantener cada vista independiente.
- La columna izquierda (imagen) se oculta en pantallas angostas (mobile); en ese caso el
  formulario ocupa el ancho completo, sin apilar la imagen arriba.
- El layout ocupa exactamente el alto de la pantalla (`h-screen`, no `min-h-screen`) y ambas
  columnas se estiran a ese mismo alto — la imagen (`object-cover`) se recorta a la altura de
  pantalla en vez de imponer su propia relación de aspecto. La página nunca hace scroll vertical
  completo; si el contenido del formulario no cabe en pantallas muy bajas, hace scroll interno
  solo dentro de la columna del formulario (`overflow-y-auto`), sin mover la columna de la imagen.
- La imagen `banner.png` (`delivery/public/banner.png`, ~1.8 MB) se comprime y convierte a
  `banner.webp`, y se copia a `inDriver/frontend/src/assets/banner.webp` — proyecto independiente
  de `delivery`, sin referencias cruzadas de ruta entre repos.
- La imagen se importa como asset de Vite (`import banner from '@/assets/banner.webp'`), no se
  sirve desde una carpeta pública sin procesar, para que el build la optimice y le asigne un
  nombre con hash de caché.
- La imagen es decorativa: `alt=""` en las 3 vistas.
- El nombre de marca junto al logo es **"inDriver"**, usando el `logo.svg` ya existente en
  `frontend/src/assets/` — no se agrega ningún ícono nuevo.
- Todos los textos de las 3 pantallas van en español, consistente con el resto del panel.
- Colores y tipografía usan los tokens ya definidos en la spec 005 (`brand.dark`, `brand.yellow`,
  `brand.blue`, `fontFamily.sans` = Montserrat) — sin hex sueltos nuevos.
- El checkbox **"Recordarme"** en `LoginView` es solo visual: no cambia el body del `POST
  /api/v1/admin/login` ni agrega lógica de sesión persistente. Queda un comentario en el código
  dejando explícito que no tiene efecto, para que no se asuma funcionalidad inexistente.

## Frontend (Vue 3)

- **Asset nuevo**: `frontend/src/assets/banner.webp` (versión comprimida de `banner.png`).
- **`LoginView.vue`**: layout de dos columnas (imagen | formulario). Columna derecha: logo +
  "inDriver", título "Bienvenido de nuevo", subtítulo, campos "Correo electrónico" y "Contraseña"
  (los mismos de hoy, restyleados), checkbox "Recordarme" (visual, sin lógica), link "¿Olvidaste tu
  contraseña?" apuntando a la ruta ya existente `/admin/forgot-password`, botón "Iniciar sesión".
  Misma lógica de `onSubmit`/`useAdminAuthStore` que ya existe, sin cambios.
- **`ForgotPasswordView.vue`**: mismo layout de dos columnas (imagen igual a las otras vistas),
  título y subtítulo propios de recuperar contraseña, campo "Correo electrónico", botón de envío.
  Misma lógica existente (`forgotPassword`), sin cambios.
- **`ResetPasswordView.vue`**: mismo layout de dos columnas, título y subtítulo propios de
  restablecer contraseña, campos de nueva contraseña y confirmación, botón de guardar. Misma
  lógica existente (`resetPassword`), sin cambios.
- En las 3 vistas, la columna de imagen usa una clase responsiva de Tailwind (oculta por defecto,
  visible desde el breakpoint de desktop) para que en mobile solo se vea el formulario, sin scroll
  horizontal.
- `<main>` usa `h-screen` (no `min-h-screen`) y ambas columnas `h-full`, para que la página nunca
  exceda el alto de pantalla; la columna del formulario usa `overflow-y-auto` como resguardo si su
  contenido no cabe en pantallas bajas.
- Se reutilizan tokens/clases de la spec 005 (`brand.dark/yellow/blue`, Montserrat) y componentes
  de `components/ui/` donde aplique (p. ej. `UiCard` para el panel del formulario), sin duplicar
  estilos sueltos.

## Fuera de alcance

- Cualquier cambio de backend: rutas, controlador o comportamiento de
  `004-auth-admin-central.md` — los 3 formularios llaman a los mismos endpoints de siempre.
- Lógica funcional de "Recordarme" (sesión extendida/persistente) — queda solo como elemento
  visual.
- Un componente de layout compartido entre las 3 vistas — decisión explícita de no crearlo.
- Rediseño del `DashboardView.vue` u otras pantallas del panel — solo las 3 vistas de
  autenticación.
- Modo oscuro/claro alternable.

## Criterios de aceptación

1. `LoginView.vue`, `ForgotPasswordView.vue` y `ResetPasswordView.vue` muestran un layout de dos
   columnas en desktop: imagen a la izquierda, formulario a la derecha.
2. En pantallas angostas (mobile), la columna de imagen no se muestra en ninguna de las 3 vistas;
   el formulario ocupa el ancho completo sin generar scroll horizontal.
3. Ninguna de las 3 vistas genera scroll vertical de página completa: el layout ocupa
   exactamente el alto de pantalla y la imagen se recorta a ese alto (no impone su propia relación
   de aspecto). Si el contenido del formulario no cabe en una pantalla muy baja, el scroll ocurre
   solo dentro de la columna del formulario.
4. La imagen usada es `frontend/src/assets/banner.webp` (versión comprimida/WebP de
   `banner.png`), importada como asset de Vite — no existe una copia sin comprimir servida desde
   una carpeta pública.
5. La imagen tiene `alt=""` en las 3 vistas.
6. Los colores y la tipografía de las 3 vistas usan los tokens `brand.dark/yellow/blue` y
   `fontFamily.sans` (Montserrat) definidos en `005-guia-diseno-base.md` — no hay hex ni fuentes
   sueltas nuevas.
7. Los textos visibles de las 3 vistas están en español.
8. El checkbox "Recordarme" existe visualmente en `LoginView` pero no cambia el comportamiento del
   login: la petición a `/api/v1/admin/login` es idéntica a la de hoy con o sin el checkbox
   marcado.
9. El link "¿Olvidaste tu contraseña?" sigue apuntando a `/admin/forgot-password` sin cambios de
   comportamiento respecto a la spec 004.
10. Ningún endpoint de `/api/v1/admin/*`, el store `useAdminAuthStore`, ni las rutas del router
    (`router/index.ts`) cambian de comportamiento respecto a la spec 004.
11. `npm run build` compila sin errores; ESLint/Prettier corren sin errores sobre el código nuevo.
12. No existe ningún componente de layout compartido nuevo entre las 3 vistas — cada una contiene
    su propio markup del layout de dos columnas.

## Supuestos asumidos (registro completo)

1. Esta spec rediseña `LoginView.vue`, `ForgotPasswordView.vue` y `ResetPasswordView.vue` ya
   existentes (spec 004) — no crea rutas ni pantallas nuevas.
2. Cada una de las 3 vistas repite su propio markup del layout de dos columnas; no se crea ningún
   componente de layout compartido entre ellas (decisión explícita).
3. Layout de dos columnas en desktop (imagen | formulario); en mobile la columna de imagen se
   oculta por completo y el formulario ocupa el ancho completo, siguiendo el criterio "100%
   responsivo" de la spec 005.
4. El nombre de marca mostrado junto al logo es "inDriver", no el nombre genérico del mockup de
   referencia.
5. Los textos de las 3 vistas van en español, igual que el resto del panel.
6. `banner.png` se comprime/convierte a WebP y se copia como asset nuevo dentro de
   `inDriver/frontend/src/assets/` — proyecto independiente de `delivery`, sin rutas cruzadas
   entre proyectos.
7. La imagen se importa como asset de Vite (no como archivo público suelto), para que el build la
   optimice y la cachee con hash.
8. La imagen es decorativa: `alt=""` en las 3 vistas.
9. El ícono junto al nombre de marca se resuelve con el `logo.svg` ya existente, sin agregar un
   ícono nuevo de `lucide-vue-next`.
10. El checkbox "Recordarme" es solo visual, sin lógica nueva de sesión persistente — el backend
    de la spec 004 no contempla "recordar sesión" y esta spec no reabre el backend. Queda un
    comentario en el código dejando explícito que no tiene efecto.
11. El link "¿Olvidaste tu contraseña?" apunta a la ruta ya existente `/admin/forgot-password`,
    sin cambios de comportamiento.
12. El rediseño usa los tokens y componentes de la spec 005 (`brand.dark/yellow/blue`,
    Montserrat, componentes de `ui/` donde aplique) — no se inventan colores ni fuentes nuevas.
13. Es un cambio puramente visual/frontend: no se toca `useAdminAuthStore`, las rutas del router,
    ni ningún endpoint de `/api/v1/admin/*`.
</content>
