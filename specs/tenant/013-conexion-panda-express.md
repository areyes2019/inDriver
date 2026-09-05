# Spec: Conexión de la app de conductor (panda_express) con inDriver

## Historia de usuario

Como Conductor, quiero iniciar sesión desde la app móvil (panda_express, Vue 3 + Capacitor) con la
misma cuenta que ya tengo en mi tenant, conectarme/desconectarme, ver los pedidos disponibles de mi
empresa, aceptar uno, avanzar su estado hasta la entrega, y que mi ubicación se comparta en tiempo
real, para operar sin depender del panel web de despachador.

## Objetivo / Alcance

`panda_express` ya existe como app funcional, pero fue construida contra una API que **no existe**
en el backend: hoy ninguna ruta acepta al rol `Conductor` (`routes/api.php` solo abre
`/t/{slug}/...` a `AdminCliente`/`Despachador`), el login es por sesión/cookie (no por token), y
tablas ya creadas para esto (`conductor_estado`, `conductor_posiciones`) no tienen ningún
controlador. Esta historia construye ese puente completo: backend nuevo + ajustes en
`panda_express` para que hable con la API real.

Deja funcionando:

- Login del Conductor por token (Sanctum), scoped al tenant por slug.
- Endpoint de pool de pedidos `PUBLICADO` disponibles, aceptar uno, avanzar su estado paso a paso,
  cancelarlo, y restaurar el pedido activo al reabrir la app.
- Conectarse/desconectarse (`conductor_estado`) y envío de ubicación GPS
  (`conductor_estado` + histórico `conductor_posiciones`).
- Consulta de saldo de viajes prepagados del conductor.
- Tiempo real vía Laravel Reverb: los conductores conectados de un tenant se enteran al instante de
  pedidos nuevos y de pedidos que ya tomó otro conductor, sin esperar el sondeo de 10s que
  `panda_express` ya trae.
- Ajustes correspondientes en `panda_express`: `services/api.js`, `stores/auth.js`, composables de
  viaje/ubicación y `services/realtime.js`, para hablar con los nombres de campo y estados reales
  del backend (no los que la app asumía).

**No incluye:**

- Ningún cambio al panel de despachador/admin ni a `PedidoController` existente (se reutiliza su
  lógica, no se modifica su comportamiento actual).
- El modelo de asignación dirigida de `pedido_asignaciones` (sigue sin usarse, igual que en spec
  011).
- Registro/alta de conductores desde la app — se sigue haciendo desde el panel (spec 003 del
  módulo tenant, `ConductorController`).
- Notificaciones push nativas (FCM) — las alertas de pedido nuevo siguen siendo sonido/vibración/
  toast dentro de la app, como ya tiene `panda_express`.
- Que la app mande a `conductor_estado.estado` otro valor que no sea `ONLINE`/`OFFLINE` — el
  conductor no elige `OCUPADO`/`DESCANSO` explícitamente, solo se conecta o desconecta.
  `conductores.disponibilidad` sí queda sincronizada por este toggle (ver "Decisión técnica"), pero
  solo entre `DISPONIBLE` y `FUERA_DE_SERVICIO`; los valores `OCUPADO`/`DESCANSO` de ese enum quedan
  sin usarse por ahora.

## Decisión técnica

### Login por token, con guard nuevo separado del guard de sesión existente

El guard `usuario` (`config/auth.php`) es `driver: session` y lo sigue usando tal cual el panel web
de despachador/admin — no se toca. Para el conductor se agrega un guard nuevo:

```php
'conductor-token' => [
    'driver' => 'sanctum',
    'provider' => 'usuarios',
],
```

`Usuario` agrega el trait `Laravel\Sanctum\HasApiTokens`. El login del conductor
(`POST /t/{slug}/conductor/login`) valida credenciales igual que `TenantAuthController::login`,
pero además exige `rol === 'Conductor'` (403 si no), y en vez de `Auth::guard('usuario')->login()`
hace `$usuario->createToken('panda-express')->plainTextToken`. Como la restricción de rol ya se
aplica al crear el token, las rutas protegidas del conductor solo necesitan `auth:conductor-token`
— no hace falta reutilizar `AsegurarRolTenant` (que además asume `$request->user('usuario')`, el
guard de sesión).

### `personal_access_tokens` debe vivir en la base de cada tenant, no en la central

Sanctum ya está instalado (`laravel/sanctum` en `composer.json`) y su migración de
`personal_access_tokens` ya existe, pero está en `database/migrations/` (base **central**). Como
`Usuario` es un modelo de tenant (cada tenant tiene su propia base de datos, vía
`tenancy()->initialize()` en `IdentificarTenantPorSlug`), sus tokens deben guardarse en la base del
tenant, no en la central — exactamente el mismo motivo por el que `password_reset_tokens` ya está
duplicada en `database/migrations/tenant/`. Se agrega una migración nueva en
`database/migrations/tenant/` que crea `personal_access_tokens` (misma estructura que la de
Sanctum) en cada base de tenant.

### Los nombres de campo y el `estado` en mayúsculas los define el backend; `panda_express` se adapta

`panda_express` fue escrito asumiendo campos que no existen (`pickup_lat`/`drop_lat`, estados en
minúscula `tomado`/`arribado_a_entrega`). El backend real usa
`latitud_recogida`/`longitud_recogida`/`latitud_entrega`/`longitud_entrega` y el enum de
`pedidos.estado` en mayúsculas (`TOMADO`, `ARRIBADO_A_ENTREGA`, …). En vez de duplicar esos nombres
en el backend para no tocar `panda_express`, se ajusta `panda_express` (composables, mapas de
estado, plantillas) a los nombres y valores reales — evita mantener dos convenciones de nombres
para el mismo dato y mantiene al backend como única fuente de verdad, consistente con el resto del
sistema (panel de despachador/admin).

### Transiciones del conductor reutilizan la tabla de `PedidoController`, no la duplican

El mapa `TRANSICIONES` y la liquidación (`liquidarConductor`) de `Tenant\PedidoController` ya
contienen exactamente las reglas que necesita el conductor (`TOMADO→ARRIBADO→EN_CAMINO→
ARRIBADO_A_ENTREGA→ENTREGADO`, descuento de prepago o cálculo de comisión al entregar). Se extraen
a un servicio compartido `PedidoEstadoService` (transición + liquidación), usado tanto por
`Tenant\PedidoController` (despachador/admin, sin cambios de comportamiento) como por el nuevo
`Tenant\Conductor\PedidoController`, que además restringe qué transiciones puede disparar un
conductor (no puede mandar un pedido a `RECHAZADO`, ni tocar uno que no sea el suyo).

### Diseño del canal de tiempo real (Reverb)

Un canal privado por tenant, `tenant.{slug}.conductores` — se usa el slug (no el id numérico
`id_tenant`) porque `panda_express` ya lo conoce en build-time (asunción 1, "un solo tenant por
build"); usar el slug evita agregar `id_tenant` a las respuestas de login/me solo para esto.
Autorizado en `routes/channels.php` verificando que quien pide el canal es un `Usuario`
autenticado (`conductor-token`) del tenant cuyo slug coincide — así un conductor de la empresa A
nunca escucha eventos de la empresa B. Eventos
que se disparan ahí:

- `PedidoDisponible` — al pasar un pedido a `PUBLICADO` (mismo lugar donde hoy
  `PedidoController::cambiarEstado` ya hace ese cambio).
- `PedidoYaTomado` — al pasar a `TOMADO`, para que los demás conductores lo quiten de su lista sin
  esperar el sondeo.
- `PedidoCanceladoParaConductor` — si un pedido que ya tiene conductor asignado se cancela desde el
  panel de despachador (no desde la app), para que el conductor se entere al instante (esto ya lo
  cubre el `cancelledExternally` que `panda_express` trae listo en `stores/trip.js`, hoy sin
  ninguna fuente real que lo dispare).

`panda_express` ya trae `pusher-js` (`services/realtime.js`) — Reverb es compatible con el
protocolo de Pusher Channels, así que solo cambian las credenciales de conexión (host/puerto propio
en vez de `pusher.com`), no la librería cliente.

### `pedidos` recupera coordenadas — se revierte parcialmente spec 006

Al implementar se descubrió que `latitud_recogida`/`longitud_recogida`/`latitud_entrega`/
`longitud_entrega` habían sido eliminadas de `pedidos` a propósito (spec tenant/006, migración
`2026_08_31_000003_remove_coordenadas_from_pedidos_table.php`): el panel de despachador calculaba
el total del viaje con esas coordenadas solo en memoria del navegador, sin persistirlas. La app de
conductor sí las necesita — sin ellas no hay contra qué comparar el GPS para detectar la llegada
automática al punto de recogida/entrega, ni ruta que dibujar en el mapa. Se agrega una migración
nueva (`add_coordenadas_to_pedidos_table`) que las vuelve a crear (nullable), sin deshacer la
migración que las quitó. `PedidoController@store`/`update` (despachador) las valida y guarda de
nuevo, y `NuevaEntregaPanel.vue` (frontend del panel) vuelve a enviarlas en el `POST /pedidos` —ya
las tenía resueltas en `recogidaCoord`/`entregaCoord`, solo no las incluía en el payload.

### El toggle ONLINE/OFFLINE también sincroniza `conductores.disponibilidad`

Hasta ahora `conductores.disponibilidad` era un campo que el AdminCliente editaba a mano desde el
CRUD de conductores (`tenant/003`) — pero esa decisión no le corresponde a él: es el propio
conductor quien decide si está disponible para operar, al conectarse o desconectarse desde esta app.
Por eso `EstadoController@actualizar` ya no solo escribe `conductor_estado.estado`: en la misma
petición actualiza `conductores.disponibilidad` (`ONLINE → 'DISPONIBLE'`,
`OFFLINE → 'FUERA_DE_SERVICIO'`). `ConductorController@update` (spec `tenant/003`) deja de aceptar
`disponibilidad` en su `PUT` — el AdminCliente ya solo controla el `estado` del conductor (darlo de
baja del equipo si ya no labora ahí), no su disponibilidad operativa.

### Ubicación: se escribe en dos lugares con cada envío

Cada `POST /conductor/ubicacion` actualiza `conductor_estado.ultima_latitud/ultima_longitud/
ultima_actualizacion` (para que el mapa del panel muestre la posición actual) **y** además inserta
una fila en `conductor_posiciones` (para dejar la traza histórica del recorrido) — ambas tablas ya
existen en el esquema, ninguna se modifica.

## Reglas de negocio

1. Solo un `Usuario` con `rol = 'Conductor'` puede autenticarse en `/conductor/login`; cualquier
   otro rol recibe 403 aunque la contraseña sea correcta.
2. Un conductor solo ve en su pool pedidos con `estado = 'PUBLICADO'` y `id_conductor = null`, de
   su propio tenant.
3. Un conductor no puede aceptar un segundo pedido si ya tiene uno activo (`id_conductor` propio en
   un pedido cuyo estado no sea final: `ENTREGADO`, `RECHAZADO`, `CANCELADO`).
4. Al aceptar (`PUBLICADO→TOMADO`), el pedido toma automáticamente el vehículo propio del conductor
   (`vehiculos.id_conductor`, relación 1 a 1 — ver `tenant/004-vehiculo-del-conductor.md`); como todo
   conductor activo tiene su propio vehículo desde el alta, `id_vehiculo` nunca queda `null`.
5. Desde la app, el conductor solo puede mover su propio pedido activo por:
   `TOMADO→ARRIBADO→EN_CAMINO→ARRIBADO_A_ENTREGA→ENTREGADO`, o cancelarlo (`→CANCELADO`) en
   cualquier punto antes de `ENTREGADO`.
6. Conectarse (`estado: 'ONLINE'`) es requisito para ver el pool de pedidos disponibles;
   desconectarse (`'OFFLINE'`) no afecta un pedido ya en curso.
7. Cada cambio de `ONLINE`/`OFFLINE` actualiza también `conductores.disponibilidad`
   (`ONLINE → DISPONIBLE`, `OFFLINE → FUERA_DE_SERVICIO`) — es la única forma en que ese campo
   cambia; el AdminCliente ya no lo edita a mano (`tenant/003`).
8. El saldo de viajes del conductor (`GET /conductor/saldo-viajes`) solo aplica cuando el tenant usa
   modalidad `Prepago` (spec 015); con modalidad `Comision`, el endpoint responde `saldo: null` y la
   app oculta el chip de saldo.
9. Cada pedido `ENTREGADO` por el conductor liquida igual que hoy (`PedidoEstadoService`, migrado
   sin cambios de `PedidoController::liquidarConductor`): descuenta 1 viaje prepagado o calcula la
   comisión, según la modalidad del tenant.

## Backend (Laravel)

- **Migración nueva** `database/migrations/tenant/…_create_personal_access_tokens_table.php`: copia
  la estructura estándar de Sanctum, ejecutada por tenant (mismo patrón que
  `password_reset_tokens`).
- **Modelo `Usuario`**: agrega `use Laravel\Sanctum\HasApiTokens;`.
- **`config/auth.php`**: agrega el guard `conductor-token` (`driver: sanctum`,
  `provider: usuarios`).
- **Nuevo namespace `App\Http\Controllers\Tenant\Conductor`**:
  - `AuthController@login` — valida credenciales + `rol === 'Conductor'`, emite el token, responde
    `{ token, usuario }` (mismo shape de datos de usuario que ya arma
    `TenantAuthController::respuestaUsuario`, sin `ciudades_tenant`/`cobertura_bounds` que no
    aplican al conductor).
  - `AuthController@logout` — revoca el token actual (`$request->user('conductor-token')
    ->currentAccessToken()->delete()`).
  - `EstadoController@actualizar` — `POST /conductor/estado`, body `{ estado: 'ONLINE'|'OFFLINE' }`,
    `ConductorEstado::updateOrCreate(['id_conductor' => …], [...])`, y además actualiza
    `conductores.disponibilidad` del conductor autenticado (`ONLINE → 'DISPONIBLE'`,
    `OFFLINE → 'FUERA_DE_SERVICIO'`).
  - `UbicacionController@actualizar` — `POST /conductor/ubicacion`, body
    `{ latitud, longitud, precision?, velocidad?, rumbo?, bateria? }`; actualiza
    `conductor_estado` e inserta en `conductor_posiciones` (ver "Decisión técnica").
  - `PedidoController@disponibles` — `GET /conductor/pedidos/disponibles`.
  - `PedidoController@activo` — `GET /conductor/pedidos/activo` (restaura el pedido en curso al
    reabrir la app; `null` si no hay ninguno).
  - `PedidoController@aceptar` — `POST /conductor/pedidos/{pedido}/aceptar`.
  - `PedidoController@cambiarEstado` — `POST /conductor/pedidos/{pedido}/estado`, usa
    `PedidoEstadoService` restringido a las transiciones de la regla de negocio 5.
  - `PedidoController@cancelar` — `POST /conductor/pedidos/{pedido}/cancelar`.
  - `SaldoController@show` — `GET /conductor/saldo-viajes`.
- **Nuevo recurso** `App\Http\Resources\Tenant\Conductor\PedidoResource`: expone
  `latitud_recogida/longitud_recogida/latitud_entrega/longitud_entrega` y `estado` tal como están en
  la tabla (sin traducir nombres ni casing), más lo que ya expone `PedidoResource` que le aplique.
- **Nuevo servicio** `App\Services\PedidoEstadoService`: extrae `TRANSICIONES` y
  `liquidarConductor` de `Tenant\PedidoController` sin cambiar su comportamiento; ambos
  controladores (despachador/admin y conductor) lo consumen.
- **Rutas** (`routes/api.php`), dentro del grupo `prefix('t/{slug}')->middleware('tenant.slug')`:

  ```php
  Route::post('/conductor/login', [Conductor\AuthController::class, 'login'])
      ->middleware('throttle:tenant-login');

  Route::prefix('conductor')->middleware('auth:conductor-token')->group(function () {
      Route::post('/logout', [Conductor\AuthController::class, 'logout']);
      Route::get('/me', [Conductor\AuthController::class, 'me']);
      Route::post('/estado', [Conductor\EstadoController::class, 'actualizar']);
      Route::post('/ubicacion', [Conductor\UbicacionController::class, 'actualizar']);
      Route::get('/pedidos/disponibles', [Conductor\PedidoController::class, 'disponibles']);
      Route::get('/pedidos/activo', [Conductor\PedidoController::class, 'activo']);
      Route::post('/pedidos/{pedido}/aceptar', [Conductor\PedidoController::class, 'aceptar']);
      Route::post('/pedidos/{pedido}/estado', [Conductor\PedidoController::class, 'cambiarEstado']);
      Route::post('/pedidos/{pedido}/cancelar', [Conductor\PedidoController::class, 'cancelar']);
      Route::get('/saldo-viajes', [Conductor\SaldoController::class, 'show']);
  });
  ```

- **Broadcasting**: agrega `laravel/reverb` (composer), publica `config/broadcasting.php`,
  `BROADCAST_CONNECTION=reverb` + credenciales Reverb en `.env`. `routes/channels.php` define
  `tenant.{tenantId}.conductores` autorizando por tenant actual. `PedidoEstadoService` dispara
  `PedidoDisponible`/`PedidoYaTomado`/`PedidoCanceladoParaConductor` (`ShouldBroadcast`) en los
  puntos descritos en "Decisión técnica".

## Frontend (panda_express)

- **`.env` / `.env.production`**: se agrega `VITE_TENANT_SLUG` (slug fijo del tenant de este build,
  regla funcional 1) y `VITE_API_BASE_URL` pasa a incluirlo (`/api/v1/t/{slug}`); se agregan
  `VITE_REVERB_APP_KEY`/`VITE_REVERB_HOST`/`VITE_REVERB_PORT`/`VITE_REVERB_SCHEME` para la conexión
  a Reverb (reemplazan `VITE_PUSHER_APP_KEY`/`VITE_PUSHER_CLUSTER`, que se quitan).
- **`services/api.js`**: sin cambios (ya inyecta `Authorization: Bearer`), solo el `baseURL`
  efectivo cambia por el slug.
- **`stores/auth.js`**: `login()` pasa a llamar a `/conductor/login` y a leer `data.token` /
  `data.usuario` (el backend no envuelve en `data.data`); `fetchMe()` apunta a `/conductor/me`. Los
  getters `role`/`isDriver` leen `user.rol` (no `user.role`) y comparan contra `'Conductor'` (no
  `'driver'`) — los valores reales del enum del backend. Se quitan `isSuperAdmin`/`isClientAdmin`
  (comparaban contra roles que tampoco existen en el backend real; no se usaban fuera del archivo
  muerto `src/store/index.js`, que se elimina — era un duplicado sin uso de este mismo store).
- **`stores/driver.js`**: se quitan `todayEarnings`/`todayTrips`/`guaranteeBalance` (sin
  equivalente en el backend real); `viajesDisponibles` pasa a ser la única fuente de saldo, y
  `canAcceptTrips` se recalcula sobre ella.
- **`composables/useTripManagement.js`**: `TRANSITIONS` pasa a claves en mayúsculas
  (`TOMADO: 'ARRIBADO'`, …, alineado 1:1 con el enum real); las llamadas apuntan a
  `/conductor/pedidos/...` en vez de `/driver/trips/...`; `toggleDriverStatus` llama a
  `POST /conductor/estado`; las respuestas se leen directas (`data`), no `data.data`.
- **`composables/useOrderPolling.js`**: apunta a `GET /conductor/pedidos/disponibles`; el id del
  pedido es `id_pedido`, no `id`.
- **`composables/useRealTracking.js`** y **`composables/useSimulator.js`**: usan
  `latitud_recogida`/`longitud_recogida`/`latitud_entrega`/`longitud_entrega` en vez de
  `pickup_lat`/`pickup_lng`/`drop_lat`/`drop_lng`; `syncLocation` llama a
  `POST /conductor/ubicacion` con `{ latitud, longitud }` (sin `orderId`, que el backend no usa);
  las comparaciones de estado pasan a mayúsculas (`'TOMADO'`, `'EN_CAMINO'`).
- **`composables/useMapController.js`**: mismos renombres de coordenadas y estado en mayúsculas al
  determinar la fase del viaje y los popups de los marcadores.
- **`stores/trip.js`** (`canCompleteDelivery`/`statusBadgeClass`/`statusLabel`): leen
  `activeOrder.estado` (no `.status`), claves del mapa en mayúsculas.
- **`components/driver/ActiveTripCard.vue`**, **`AvailableOrderCard.vue`**, **`DriverTopBar.vue`**:
  renombres de campo (`direccion_recogida`/`direccion_entrega`/`nombre_solicitante`/
  `telefono_solicitante`/`importe_envio`) y de estado en mayúsculas; se quita `distance_km` (sin
  equivalente); `payment_type` (`'prepaid'`/otro) se reemplaza por una traducción simple del enum
  real `modalidad_pago`; el chip de `DriverTopBar` pasa de mostrar ganancias en dinero a mostrar
  `viajesDisponibles`, oculto por completo cuando es `null` (modalidad Comisión).
- **`views/Dashboard.vue`**: `auth.role !== 'driver'` → `!== 'Conductor'` (el fork entre vista de
  admin y vista de conductor, en la plantilla y en `onMounted`); ya no lee `auth.user.driver.*`
  (sin equivalente — cada sesión arranca `OFFLINE` y el conductor debe conectarse manualmente);
  `realtime.subscribe()` pasa a llamarse sin argumentos (un solo canal por tenant, sin
  `client_id`/`driver_id`); referencias sueltas a `activeOrder.id`/`activeOrder.drop_address` se
  corrigen a `id_pedido`/`direccion_entrega`.
- **`services/realtime.js`**: la conexión Pusher cambia sus opciones de `cluster`/`key` de Pusher
  Cloud por `wsHost`/`wsPort`/`forceTLS` apuntando al servidor Reverb, con `channelAuthorization`
  configurado para mandar el token del conductor (`Authorization: Bearer`) en vez de depender de
  una cookie de sesión. Se suscribe a un único canal `private-tenant.{VITE_TENANT_SLUG}.conductores`
  (ya no hay canales por cliente/conductor) y escucha `pedido.disponible`/`pedido.tomado`/
  `pedido.cancelado`.
- **`composables/useRealtime.js`**: `subscribe()` ya no recibe `clientId`/`driverId` — se suscribe
  al único canal del tenant y marca `driver.orderListDirty`/`trip.cancelledExternally` según el
  evento, en vez de fusionar a mano campos de ganancias/garantía que no existen en el backend real.
- **`composables/useEarnings.js`**: se reescribe para pedir `GET /conductor/saldo-viajes` y escribir
  el resultado en `driver.viajesDisponibles` (en vez de las 4 propiedades de ganancias/garantía que
  asumía antes, sin backend real).

**Fuera de esta ronda, deliberadamente:** `views/WalletDashboard.vue` sigue mostrando datos
ficticios (no llama a ningún composable ni store) — no fue tocada porque ya vivía aislada de
`useEarnings.js`/`driver` antes de esta historia; conectarla a movimientos reales no está definido
en el backend (no existe un endpoint de "historial de movimientos" del conductor) y queda fuera de
alcance.

## Fuera de alcance

- Cambios al panel de despachador/admin o a sus rutas/controladores existentes (solo se extrae
  `PedidoEstadoService` sin alterar su comportamiento).
- Modelo de asignación dirigida vía `pedido_asignaciones`.
- Registro de conductores desde la app.
- Notificaciones push nativas (FCM/APNs).
- Que la app envíe o elija directamente `DISPONIBLE`/`OCUPADO`/`DESCANSO`/`FUERA_DE_SERVICIO` — solo
  maneja `ONLINE`/`OFFLINE`; `conductores.disponibilidad` se deriva de eso en el backend, sin que la
  app conozca esos valores.
- Que el conductor elija `OCUPADO`/`DESCANSO` explícitamente — esos dos valores del enum de
  `disponibilidad` quedan sin usarse por ahora (posible historia futura).
- Selección manual de vehículo por el conductor al aceptar un pedido.
- Multi-tenant dentro de una sola instalación de la app (selector de empresa al login).

## Criterios de aceptación

1. `POST /t/{slug}/conductor/login` con credenciales de un `Usuario` `rol=Conductor` responde un
   token válido; con cualquier otro rol responde 403.
2. Las rutas bajo `/t/{slug}/conductor/*` responden 401 sin token, y el token de un conductor no
   sirve para las rutas de despachador/admin ni viceversa.
3. `GET /conductor/pedidos/disponibles` solo devuelve pedidos `PUBLICADO` sin conductor asignado del
   tenant del token.
4. Aceptar un pedido ya tomado por otro conductor responde 409/422 sin modificar el pedido.
5. Un conductor con un pedido activo no puede aceptar uno segundo.
6. Las transiciones de estado del conductor respetan exactamente
   `TOMADO→ARRIBADO→EN_CAMINO→ARRIBADO_A_ENTREGA→ENTREGADO`; cualquier otra combinación responde
   422.
7. Al llegar a `ENTREGADO` desde la app, se liquida igual que hoy desde el panel (descuento de
   prepago o comisión, según modalidad del tenant) — mismo resultado, sin duplicar lógica.
8. `POST /conductor/estado` con `{ estado: 'ONLINE' }` deja `conductores.disponibilidad` en
   `'DISPONIBLE'`; con `{ estado: 'OFFLINE' }` la deja en `'FUERA_DE_SERVICIO'`.
   `PUT /t/{slug}/conductores/{id}` (panel de AdminCliente) que incluya `disponibilidad` en el
   payload la ignora sin error y sin modificar la columna.
9. `POST /conductor/ubicacion` dentro de un mismo minuto deja una fila nueva en
   `conductor_posiciones` por cada envío, y actualiza `conductor_estado.ultima_latitud/longitud` a
   la más reciente.
10. Un segundo conductor conectado al mismo tenant recibe el evento `PedidoYaTomado` por WebSocket en
    menos de 2 segundos después de que el primero acepta el pedido, sin necesidad de esperar al
    sondeo.
11. `panda_express` compilado contra este backend completa el flujo: login → conectarse → ver pool →
    aceptar → avanzar hasta entregado → ver saldo actualizado, sin ningún error de red por nombre de
    campo o de endpoint inexistente.
12. Pint y ESLint/Prettier corren sin errores sobre el código nuevo; `php artisan test` pasa.

## Supuestos asumidos (registro completo)

1. Un solo tenant por build de `panda_express` — el conductor no elige empresa al iniciar sesión.
2. El conductor inicia sesión con la cuenta `Usuario` (`rol=Conductor`) que ya le creó su
   AdminCliente/Despachador desde el panel; no hay alta ni auto-registro desde la app.
3. Modelo de pool abierto: todo pedido `PUBLICADO` es visible para cualquier conductor `ONLINE` del
   tenant, y lo gana el primero que lo acepta — no se usa asignación dirigida
   (`pedido_asignaciones`).
4. El conductor solo puede disparar las transiciones `TOMADO→ARRIBADO→EN_CAMINO→
   ARRIBADO_A_ENTREGA→ENTREGADO` y cancelar su propio pedido activo — nunca `RECHAZADO` ni tocar
   pedidos ajenos.
5. Un conductor tiene como máximo un pedido activo (no final) a la vez.
6. Conectar/desconectar desde la app escribe directo en `conductor_estado.estado`
   (`ONLINE`/`OFFLINE` únicamente); los demás valores de ese enum quedan fuera de alcance de la app.
   Ese mismo cambio sincroniza además `conductores.disponibilidad` (`DISPONIBLE`/
   `FUERA_DE_SERVICIO`) — es la única forma en que ese campo cambia, ya no lo edita el AdminCliente
   a mano desde el CRUD (`tenant/003`).
7. Cada envío de ubicación actualiza la posición "actual" en `conductor_estado` y además deja
   registro histórico en `conductor_posiciones` (no se descarta el histórico).
8. El vehículo del pedido se toma automático del vehículo propio del conductor
   (`vehiculos.id_conductor`, no una tabla de asignaciones); el conductor no lo elige manualmente al
   aceptar.
9. "Ganancias"/saldo en la app equivale al saldo de viajes prepagados restante
   (`ventas_viajes_conductor` menos pedidos consumidos), no a dinero adeudado al conductor; con
   modalidad `Comision` no aplica y se oculta.
10. Autenticación por token (Sanctum) para el conductor, separada del guard de sesión (`usuario`)
    que sigue usando el panel web sin cambios.
11. Se agrega tiempo real con Laravel Reverb (canal privado por tenant) para avisar al instante
    pedidos nuevos/tomados/cancelados; el sondeo cada 10s de `panda_express` se conserva como
    respaldo, no se elimina.
12. El alcance de esta historia es solo el flujo de conductor — no se modifica el comportamiento ni
    las rutas existentes de despachador/admin.
13. `personal_access_tokens` se crea como migración de tenant (una tabla por base de tenant), igual
    que ya está hecho para `password_reset_tokens`, porque `Usuario` es un modelo de tenant.
14. Los nombres de campo (`latitud_recogida`, etc.) y el `estado` en mayúsculas del backend son la
    fuente de verdad; es `panda_express` quien se adapta a ellos, no al revés.
15. La lógica de transición de estados y liquidación (`TRANSICIONES`/`liquidarConductor`) se
    comparte entre el panel de despachador/admin y la app del conductor vía un servicio único
    (`PedidoEstadoService`), sin duplicarla ni cambiar su comportamiento actual.
16. El canal de tiempo real es privado y está delimitado por tenant — un conductor nunca recibe
    eventos de pedidos de otra empresa.
17. No se agregan notificaciones push nativas (FCM/APNs) en esta historia — las alertas siguen
    siendo dentro de la app (sonido/vibración/toast), como ya tiene `panda_express`.
18. `pedidos` recupera las columnas de coordenadas que spec tenant/006 había eliminado a propósito
    (decisión confirmada explícitamente durante la implementación, no parte de las 12 asunciones
    originales): sin ellas la app de conductor no tiene contra qué comparar el GPS para detectar
    llegada ni ruta que dibujar. El panel de despachador vuelve a enviarlas al crear un pedido.
19. `views/WalletDashboard.vue` de `panda_express` queda fuera de esta historia — sigue mostrando
    datos ficticios, sin conectarse a `driver.viajesDisponibles` ni a ningún endpoint real, porque
    el backend no tiene (ni esta historia agrega) un endpoint de historial de movimientos.
20. La disponibilidad operativa del conductor (`conductores.disponibilidad`) la decide el conductor,
    no el AdminCliente: `EstadoController@actualizar` sincroniza esa columna en cada
    conexión/desconexión (`ONLINE → DISPONIBLE`, `OFFLINE → FUERA_DE_SERVICIO`), y
    `ConductorController@update` (`tenant/003`) deja de aceptarla en su `PUT`. `OCUPADO`/`DESCANSO`
    quedan sin usarse por ahora, al no existir todavía una forma de que el conductor los elija desde
    la app.
