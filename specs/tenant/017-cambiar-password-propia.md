# Spec: Cambiar mi contraseña desde el panel (AdminCliente y Despachador)

## Historia de usuario

Como AdminCliente o Despachador, quiero tener un espacio dentro de mi panel para cambiar mi propia
contraseña, para no tener que cerrar sesión y usar "olvidé mi contraseña" cuando simplemente quiero
actualizarla.

## Objetivo / Alcance

Hoy el único mecanismo para cambiar una contraseña es el flujo de recuperación
(`forgot-password`/`reset-password` de `tenant/001-login-y-crud-usuarios.md`), pensado para cuando el
usuario **no puede** iniciar sesión. No existe ninguna pantalla de autoservicio para cambiarla estando
ya logueado, ni para `AdminCliente` ni para `Despachador` (`Conductor` no tiene panel web y queda
fuera de esta historia).

Esta historia agrega:

1. Un endpoint nuevo de autoservicio, compartido por ambos roles, que exige la contraseña actual
   antes de guardar la nueva.
2. Un formulario reutilizable de "cambiar contraseña", usado en dos lugares distintos según el rol:
   - Para `AdminCliente`: una pestaña nueva **"Mi cuenta"** dentro de `ConfiguracionView.vue`
     (`tenant/015-configuracion-comisiones.md`), junto a Tarifas / Comisión-Prepago / Zonas de
     cobertura.
   - Para `Despachador`: una pantalla nueva y liviana, ya que este rol no tiene acceso a
     `ConfiguracionView.vue`.
3. El ícono de engranaje del navbar (`UiNavbar.vue`), que hoy solo se muestra para `AdminCliente`, se
   muestra también para `Despachador` y lo lleva directo a esa pantalla nueva.

**No incluye:**

- Cambiar la contraseña de otro usuario (eso ya es un CRUD aparte, fuera de esta historia).
- El rol `Conductor`.
- Historial de contraseñas (evitar reutilizar una anterior) ni expiración periódica.
- Notificación por correo al cambiar la contraseña.
- Cerrar la sesión actual (ni otras sesiones activas) tras el cambio.

## Decisión técnica

### Un solo endpoint compartido, sin restricción de rol

A diferencia del resto de rutas de `tenant/{slug}`, que casi todas están protegidas por
`rol.tenant:<Rol>` (ver `backend/routes/api.php`), esta ruta nueva solo exige sesión iniciada
(`auth:usuario`): cualquier rol autenticado cambia **su propia** contraseña, nunca la de otro usuario
(no recibe ningún id en la URL, opera siempre sobre `$request->user('usuario')`).

### Confirmar la contraseña actual, con nombres de campo distintos a los de "nueva"

Se reutiliza el mismo criterio de seguridad que ya usa `UsuarioController::destroy()` (exigir
re-escribir la contraseña de sesión antes de una acción sensible), pero como aquí conviven una
contraseña "actual" y una "nueva" en la misma petición, se usan nombres de campo distintos para no
chocar con la convención ya usada en `resetPassword()` (`password` + `password_confirmation` para la
contraseña nueva):

- `password_actual`: la contraseña de sesión, validada con `Hash::check()` contra
  `$request->user('usuario')->password`.
- `password` + `password_confirmation`: la contraseña nueva, con las mismas reglas que
  `resetPassword()` (`min:8`, `confirmed`).

### Un componente de formulario compartido entre las dos pantallas

Para no duplicar la lógica del formulario (los tres campos, la llamada al store, mensajes de error),
se extrae un componente `CambiarPasswordForm.vue`, usado tanto dentro de la pestaña "Mi cuenta" de
`ConfiguracionView.vue` como en la pantalla nueva de `Despachador`. El componente no sabe de rol ni de
navegación: solo expone el formulario y emite el resultado (éxito o error).

### El ícono de engranaje pasa a depender del click, no solo del rol

`TenantLayout.vue` cambia `:mostrar-configuracion="auth.usuario?.rol === 'AdminCliente'"` a
`true` (visible para ambos roles), y `onClickConfiguracion()` decide el destino según el rol:
`AdminCliente` sigue yendo a `tenant-configuracion` (comportamiento sin cambios); `Despachador` va a
la ruta nueva `tenant-cambiar-password`. `UiNavbar.vue` no cambia — ya soporta mostrar/ocultar el
ícono y emitir el clic.

## Reglas de negocio

1. Solo se puede cambiar la contraseña de la propia sesión — el endpoint nunca recibe un id de
   usuario distinto.
2. La contraseña actual es obligatoria y debe coincidir con la de la sesión (`Hash::check`); si no
   coincide, se rechaza con un error en el campo `password_actual` sin revelar más detalle.
3. La contraseña nueva exige mínimo 8 caracteres y confirmación repetida (`password_confirmation`) —
   mismas reglas que ya aplica `resetPassword()`.
4. Tras un cambio exitoso, la sesión actual permanece iniciada; no se envía correo ni se cierran otras
   sesiones.
5. La ruta está limitada por `throttle:tenant-usuarios` (20/min por usuario), igual que el resto de
   rutas autenticadas sensibles del tenant, para frenar intentos repetidos de adivinar la contraseña
   actual.
6. Aplica a `AdminCliente` y `Despachador` por igual; `Conductor` no tiene acceso a esta pantalla (no
   tiene panel web).

## Backend (Laravel)

- **`backend/routes/api.php`**: agrega, dentro del grupo `Route::middleware('auth:usuario')` (línea
  59, junto a `/logout` y `/me`, **antes** de los subgrupos con `rol.tenant`):
  ```php
  Route::middleware('throttle:tenant-usuarios')->post('/cambiar-password', [TenantAuthController::class, 'changePassword']);
  ```
- **`App\Http\Controllers\Tenant\AuthController::changePassword()`** (nuevo método, junto a
  `resetPassword()`):
  - Valida `password_actual` (`required`, `string`), `password` (`required`, `string`, `min:8`,
    `confirmed`).
  - Si `! Hash::check($data['password_actual'], $request->user('usuario')->password)`, lanza
    `ValidationException` con mensaje en `password_actual` ("La contraseña actual no es correcta.").
  - Si es correcta: `$request->user('usuario')->forceFill(['password' => Hash::make($data['password'])])->save();`
  - Responde `['message' => 'Contraseña actualizada correctamente.']`.
- No se agrega ninguna migración: se reutiliza la columna `usuarios.password` ya existente (cast
  `'hashed'` en `App\Models\Tenant\Usuario`).

## Frontend (Vue 3)

- **`frontend/src/stores/tenantAuth.ts`**: agrega la acción
  `changePassword(currentSlug, { password_actual, password, password_confirmation })`, que llama
  `http.post(\`/t/${currentSlug}/cambiar-password\`, payload)`, siguiendo el mismo patrón que
  `resetPassword()`.
- **`frontend/src/components/tenant/CambiarPasswordForm.vue`** (nuevo, compartido): tres campos
  (`password_actual`, `password`, `password_confirmation`), botón "Guardar", usa `UiAlert` para
  mostrar error/éxito. Al enviar, llama `tenantAuth.changePassword()`; en éxito, limpia el formulario
  y muestra el mensaje de éxito (no navega ni cierra sesión).
- **`frontend/src/views/tenant/cuenta/CambiarPasswordView.vue`** (nuevo): pantalla para
  `Despachador`, envuelta en `TenantLayout`, con `UiCard` conteniendo `CambiarPasswordForm.vue`.
- **`frontend/src/views/tenant/configuracion/ConfiguracionView.vue`**: agrega una 4ª pestaña
  `{ id: 'cuenta', label: 'Mi cuenta' }` al arreglo `pestanas`, que renderiza
  `CambiarPasswordForm.vue` dentro de un `UiCard` (mismo patrón que las demás pestañas).
- **`frontend/src/router/index.ts`**: agrega la ruta
  ```ts
  {
    path: '/t/:slug/panel/cambiar-password',
    name: 'tenant-cambiar-password',
    component: () => import('../views/tenant/cuenta/CambiarPasswordView.vue'),
    meta: { requiresTenantAuth: true },
  }
  ```
  sin guard de rol adicional (accesible para cualquier rol autenticado del tenant, a diferencia de
  `tenant-configuracion`).
- **`frontend/src/layouts/TenantLayout.vue`**:
  - `:mostrar-configuracion="auth.usuario?.rol === 'AdminCliente'"` pasa a `:mostrar-configuracion="true"`.
  - `onClickConfiguracion()` navega a `tenant-configuracion` si el rol es `AdminCliente`, o a
    `tenant-cambiar-password` en caso contrario (`Despachador`).

## Fuera de alcance

- Cambiar la contraseña de otro usuario (ya cubierto por el CRUD de usuarios existente).
- El rol `Conductor`.
- Historial/reutilización de contraseñas anteriores y expiración periódica.
- Notificación por correo al cambiar la contraseña.
- Cerrar la sesión actual u otras sesiones activas tras el cambio.
- Cambios al flujo existente de "olvidé mi contraseña" (`forgot-password`/`reset-password`).

## Criterios de aceptación

1. Como `AdminCliente`, dentro de "Configuración" existe una pestaña "Mi cuenta" con el formulario de
   cambio de contraseña.
2. Como `Despachador`, el ícono de engranaje del navbar es visible y lleva a una pantalla dedicada de
   cambio de contraseña (sin acceso a las demás pestañas de Configuración).
3. Enviar el formulario con la contraseña actual incorrecta muestra un error y no cambia nada.
4. Enviar el formulario con la contraseña actual correcta y una nueva válida (mínimo 8 caracteres,
   confirmación coincidente) actualiza la contraseña; con esa nueva contraseña se puede iniciar sesión
   en un login posterior.
5. Tras un cambio exitoso, la sesión actual sigue iniciada (no redirige a login).
6. El rol `Conductor` no ve el ícono de engranaje ni puede acceder a `tenant-cambiar-password` (no
   tiene panel web, sin cambios respecto a hoy).
7. ESLint/Prettier (frontend) y Pint/tests de backend existentes corren sin errores.

## Supuestos asumidos (registro completo)

1. Spec numerada como `tenant/017-cambiar-password-propia.md`.
2. Alcance: roles `AdminCliente` y `Despachador` únicamente; `Conductor` queda fuera.
3. Se exige contraseña actual + nueva contraseña con confirmación, reutilizando el patrón de
   seguridad ya usado en `UsuarioController::destroy()`.
4. La nueva contraseña usa las mismas reglas de validación que `resetPassword()` (`min:8`,
   `confirmed`).
5. `AdminCliente` accede vía una pestaña nueva "Mi cuenta" dentro de `ConfiguracionView.vue`.
6. `Despachador` accede vía una pantalla nueva y liviana, dedicada solo a esto, ya que no tiene acceso
   a `ConfiguracionView.vue`.
7. El acceso de `Despachador` se habilita mostrando el ícono de engranaje del navbar (hoy oculto para
   ese rol).
8. Tras un cambio exitoso, la sesión actual no se cierra.
9. No se envía correo de notificación al cambiar la contraseña (a diferencia de la creación de
   usuario, que sí notifica por correo).
10. El endpoint nuevo (`POST /t/{slug}/cambiar-password`) es único y compartido por ambos roles, sin
    middleware `rol.tenant` adicional — solo `auth:usuario` y `throttle:tenant-usuarios`.
11. No se requiere historial de contraseñas ni expiración periódica.
12. Se documenta como spec nueva (`017`), sin modificar specs `001`–`016` salvo esta referencia
    cruzada.
