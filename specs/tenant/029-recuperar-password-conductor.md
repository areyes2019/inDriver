# Spec: Recuperar contraseña desde panda_express (Conductor)

## Historia de usuario

Como Conductor, quiero un método para recuperar mi contraseña desde la app móvil (panda_express)
cuando la olvido, para poder volver a iniciar sesión sin depender de que alguien más la restablezca
por mí.

## Objetivo / Alcance

Hoy `Login.vue` de `panda_express` solo muestra un texto estático ("¿Olvidaste tu contraseña?
Contacta al administrador"), sin ninguna acción real. El backend ya resuelve todo el mecanismo de
recuperación para cualquier usuario de tenant (`AdminCliente`/`Despachador`/`Conductor`), construido
en `tenant/001-login-y-crud-usuarios.md`: `POST /t/{slug}/forgot-password` envía el correo con el
enlace, y `POST /t/{slug}/reset-password` (consumido hoy por `ResetPasswordView.vue` del panel web)
permite fijar la nueva contraseña.

Esta historia solo conecta `panda_express` a la mitad "pedir el enlace" de ese mecanismo ya
existente. La mitad "fijar la nueva contraseña" sigue resolviéndose fuera de la app, en el navegador
del teléfono, sobre la pantalla web que ya existe — no se construye una pantalla de reset dentro de
la app móvil.

Deja funcionando:

- Un enlace real "¿Olvidaste tu contraseña?" en `Login.vue`, en vez del texto estático actual.
- Una pantalla nueva en `panda_express` donde el conductor captura su correo y pide el enlace de
  recuperación.
- La conexión de esa pantalla con el endpoint ya existente `POST /t/{slug}/forgot-password`.

**No incluye:**

- Una pantalla de "restablecer contraseña" dentro de `panda_express` — se reutiliza tal cual la que
  ya existe en el panel web (`ResetPasswordView.vue`), abierta en el navegador del teléfono.
- Ningún cambio al backend — el endpoint ya existente es válido para cualquier rol de `usuarios`,
  `Conductor` incluido.
- Deep link o regreso automático a la app tras restablecer la contraseña en el navegador.
- Verificación por SMS/OTP u otro canal distinto al correo electrónico.
- Cambios al flujo de recuperación ya existente de `AdminCliente`/`Despachador` en el panel web.
- Cualquier cambio a `global.css` o clase de estilo nueva.

## Decisión técnica

### Se reutiliza el endpoint ya existente, sin distinguir por rol

`Tenant\AuthController::forgotPassword` (spec `tenant/001`) usa el broker `usuarios`, que cubre a
cualquier fila de la tabla `usuarios` del tenant sin filtrar por `rol`. No hay ninguna regla que hoy
excluya a `Conductor` de pedir su enlace de recuperación por ese endpoint — simplemente
`panda_express` nunca lo había llamado. Por eso no se toca el backend: la única pieza que falta es la
pantalla y la llamada desde el lado de la app móvil.

### El enlace del correo abre el navegador del teléfono, no una pantalla dentro de la app

`ResetPassword::createUrlUsing` (spec `tenant/001`) ya arma el enlace como
`/t/{slug}/reset-password/{token}?email=...`, apuntando al panel web de tenant. Un correo abierto
desde la app de correo del teléfono abre ese enlace en el navegador del sistema (Chrome/Safari), no
dentro de `panda_express` — comportamiento por defecto, sin configuración adicional. No se construye
un equivalente de esa pantalla dentro de la app móvil (ni se agrega manejo de deep link/esquema de
URL personalizado vía Capacitor) porque duplicaría una pantalla que ya existe, funciona y está
probada en el panel web, para una acción que el conductor hace una sola vez y de forma esporádica.

### Estilo: se reutilizan las clases ya existentes, no se toca `global.css`

La pantalla nueva usa tal cual las clases que ya aplica `Login.vue` (`login-page`, `login-content`,
`brand-section`, `field-group`, `field-label`, `field-input`, `footer-text`, `btn-login`) — ninguna
clase nueva se agrega a `global.css`, para que la pantalla se vea consistente con el resto de la app
sin tocar sus estilos.

## Reglas de negocio

1. Cualquier `Usuario` con `rol = 'Conductor'` puede pedir el enlace de recuperación desde la app,
   usando el mismo correo con el que inicia sesión.
2. La respuesta a "enviar enlace" es siempre el mismo mensaje genérico, exista o no ese correo en el
   tenant — no se revela si el correo está registrado (mismo criterio que ya usa el panel web).
3. El campo de correo es obligatorio; enviarlo vacío se valida dentro de la propia app antes de
   llamar al servidor.
4. Mientras se espera la respuesta del servidor, el botón de envío queda deshabilitado y muestra
   "Enviando..." — evita que un doble toque dispare dos correos.
5. El restablecimiento real (fijar la contraseña nueva) ocurre fuera de la app, en la pantalla ya
   existente del panel web (`/t/{slug}/reset-password/{token}`); al terminar, el conductor vuelve
   manualmente a `panda_express` e inicia sesión con la contraseña nueva.
6. Las reglas de expiración/validez del enlace y de la contraseña nueva son las mismas ya vigentes
   para `AdminCliente`/`Despachador` (spec `tenant/001`) — no hay reglas nuevas específicas para
   `Conductor`.

## Backend (Laravel)

- Sin cambios. Se reutiliza tal cual `POST /t/{slug}/forgot-password`
  (`Tenant\AuthController::forgotPassword`, spec `tenant/001`), ya válido para cualquier rol de la
  tabla `usuarios`, `Conductor` incluido.

## Frontend (panda_express, Vue 3 + Capacitor)

- **`src/stores/auth.js`**: agrega la acción `forgotPassword(email)`, que llama a
  `api.post('/forgot-password', { email })` (el `baseURL` de `services/api.js` ya incluye
  `/t/{slug}`, igual que ya hace `login()`). No toca el estado de sesión (`token`/`user`).
- **`src/views/ForgotPassword.vue`** (nueva): mismo layout que `Login.vue`, reutilizando sin cambios
  las clases `login-page`/`login-content`/`brand-section`/`field-group`/`field-label`/
  `field-input`/`footer-text`/`btn-login` ya definidas en `global.css`:
  - Campo único: correo electrónico (`field-input`, `type="email"`, `autocomplete="email"`).
  - Validación local: si el campo viene vacío al enviar, muestra "El correo electrónico es
    obligatorio" (mismo patrón que `Login.vue`) sin llamar al servidor.
  - Botón "Enviar enlace" (clase `btn-login`), deshabilitado mientras la petición está en curso,
    mostrando "Enviando...".
  - Tras la respuesta (éxito o error de red), muestra siempre el mismo mensaje genérico: "Si el
    correo existe, se envió un enlace de recuperación." — nunca distingue si el correo existe o no.
  - Enlace "Volver a iniciar sesión" debajo del formulario (clase `footer-text`), que navega a
    `/login`.
- **`src/views/Login.vue`**: el texto estático
  `<p class="footer-text">¿Olvidaste tu contraseña? Contacta al administrador</p>` se reemplaza por
  `<RouterLink to="/forgot-password" class="footer-text">¿Olvidaste tu contraseña?</RouterLink>` —
  misma clase, mismo lugar, solo cambia de texto estático a enlace real.
- **`src/router/index.js`**: agrega la ruta pública
  `{ path: '/forgot-password', component: ForgotPassword, meta: { public: true } }`, junto a la de
  `/login`.

## Fuera de alcance

- Pantalla de "restablecer contraseña" dentro de `panda_express` (se usa la del panel web, vía
  navegador externo del teléfono).
- Cambios al backend o a las reglas de expiración/validación ya vigentes (spec `tenant/001`).
- Deep link o regreso automático a la app tras restablecer la contraseña en el navegador.
- Verificación por SMS/OTP u otro canal distinto al correo.
- Cambios al flujo de recuperación de `AdminCliente`/`Despachador` (panel web).
- Nuevos estilos o clases CSS — todo se resuelve con las clases ya existentes en `global.css`.

## Criterios de aceptación

1. Desde `/login` de `panda_express`, el enlace "¿Olvidaste tu contraseña?" navega a
   `/forgot-password`.
2. En `/forgot-password`, enviar el formulario con el correo vacío muestra "El correo electrónico es
   obligatorio" sin llamar al servidor.
3. Enviar un correo válido (exista o no en el tenant) llama a `POST /t/{slug}/forgot-password` y
   muestra el mismo mensaje genérico de éxito en ambos casos.
4. Mientras la petición está en curso, el botón de envío queda deshabilitado y muestra "Enviando...".
5. Un correo real recibido para un `email` que sí existe en el tenant contiene un enlace a
   `/t/{slug}/reset-password/{token}?email=...`, que abre en el navegador del teléfono (no dentro de
   la app) y muestra la pantalla ya existente del panel web.
6. Tras fijar una nueva contraseña en esa pantalla, el conductor puede iniciar sesión en
   `panda_express` con la contraseña nueva.
7. No se modifica `global.css` ni se agrega ninguna clase nueva — la pantalla nueva se ve
   visualmente consistente con `Login.vue` usando únicamente clases ya existentes.
8. ESLint/Prettier corren sin errores sobre el código nuevo de `panda_express`.

## Supuestos asumidos (registro completo)

1. El punto de entrada es la pantalla de login de `panda_express`: se reemplaza el texto actual
   "¿Olvidaste tu contraseña? Contacta al administrador" por un enlace real a una pantalla nueva.
2. En la pantalla nueva, el conductor solo captura su correo electrónico y presiona "Enviar enlace".
3. Tras enviarlo, se muestra siempre el mismo mensaje de confirmación, sin revelar si el correo
   existe o no en el sistema — mismo criterio que ya usa el panel web.
4. El enlace del correo no abre una pantalla dentro de la app móvil: abre el navegador del teléfono
   y muestra la pantalla de "restablecer contraseña" que ya existe en el panel web de inDriver.
5. Tras cambiar la contraseña en esa pantalla del navegador, el conductor vuelve manualmente a
   `panda_express` e inicia sesión con la contraseña nueva — sin regreso automático ni deep link.
6. No se construye una pantalla de "restablecer contraseña" dentro de `panda_express`.
7. El proceso (envío de correo, validez/expiración del enlace, reglas de la contraseña nueva) es
   exactamente el mismo que ya usan `AdminCliente`/`Despachador` — sin reglas de negocio nuevas
   específicas para `Conductor`.
8. La pantalla nueva es pública (no requiere sesión iniciada), igual que la de Login.
9. No se agrega verificación por SMS/OTP ni ningún otro método distinto al de correo electrónico.
10. No se crea ni se modifica ningún estilo/clase CSS nueva: la pantalla nueva reutiliza tal cual las
    clases ya definidas en `global.css` que usa `Login.vue`.
11. El botón "Enviar enlace" queda deshabilitado y muestra "Enviando..." mientras se espera la
    respuesta del servidor, para evitar un doble envío accidental.
12. La pantalla nueva incluye un enlace "Volver a iniciar sesión" hacia `/login`, igual que ya tiene
    su equivalente en el panel web (`ForgotPasswordView.vue`).
13. Si el campo de correo viene vacío al enviar, se valida dentro de la propia app (mismo patrón que
    `Login.vue` con sus dos campos) sin llamar al servidor.
