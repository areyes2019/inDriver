# Spec: Protocolo de comunicación en tiempo real (AdminCliente, Despachador y Conductor)

## Historia de usuario

Como Conductor, AdminCliente o Despachador, quiero que los avisos importantes (pedido nuevo
disponible, pedido tomado, pedido cancelado, pedido reprogramado, saldo acreditado, ubicación del
conductor) me lleguen al instante mientras uso la app/el Panel, y que si pierdo la conexión mi
pantalla se reconstruya con el estado real del servidor en cuanto vuelvo — sin quedarme viendo datos
viejos ni duplicados.

## Objetivo / Alcance

`tenant/013-conexion-panda-express.md` ya dejó funcionando Laravel Reverb, un canal privado por
tenant y tres eventos (`PedidoDisponible`, `PedidoYaTomado`, `PedidoCanceladoParaConductor`)
escuchados solo por `panda_express`. Esta spec formaliza y completa ese protocolo para que también lo
use el Panel (`frontend/`, AdminCliente/Despachador), y le agrega las tres piezas que faltaban:
respaldo por notificación push nativa cuando el socket está caído, un endpoint de sincronización para
reconstruir el estado tras una reconexión, y deduplicación de eventos que puedan llegar por los dos
caminos (socket + push) a la vez. Las specs de flujo que dependen de esta (`019`, `020`, `021`, `022`)
heredan este protocolo y no lo redefinen.

**Incluye:** convención y autorización de canales para los tres roles, formato del evento
(`event_id` para deduplicar), respaldo a push (FCM) para eventos críticos, endpoint de
sincronización, reconexión con espera creciente.

**No incluye:** la UI que consume los eventos nuevos (`PedidoReprogramado`, `SaldoAcreditado`,
`UbicacionActualizada`) en `panda_express` ni en `frontend/` — solo se deja la infraestructura lista;
consumirlos en pantalla es alcance de las specs `019`-`022`. Tampoco incluye traducir esas specs (que
hoy usan nombres genéricos en inglés, `couriers`/`deliveries`) a los nombres reales del sistema —
queda pendiente como trabajo aparte antes de implementarlas.

## Actores y permisos

- **Conductor** — recibe avisos de pedidos de su tenant y manda su propia ubicación.
- **AdminCliente** — ve en tiempo real la ubicación de los conductores y el estado de los pedidos de
  su tenant, desde el Panel.
- **Despachador** — igual que AdminCliente, desde el Panel.

Broker: **Laravel Reverb**, self-hosted, en el mismo servidor que la API (ya instalado y configurado
desde spec 013 — `config/reverb.php`/`config/broadcasting.php` no cambian en esta spec).

## Modelo de datos

**`conductor_dispositivos`** — migración de **tenant** (como `personal_access_tokens`, porque
`Conductor`/`Usuario` viven en la base de cada tenant), para poder mandar push cuando el socket está
caído:

- `id` — ulid, PK
- `id_conductor` — FK a `conductores`, indexado
- `fcm_token` — string(255)
- `updated_at` — timestamp

Único registro por conductor: al iniciar sesión en `panda_express` se sobreescribe el token anterior.

No hay bitácora de eventos. El estado real vive en las tablas de negocio (`pedidos`, `conductores`,
`conductor_estado`), y de ahí se reconstruye todo con `GET /t/{slug}/conductor/sync`.

## Decisión técnica

### Un solo canal privado por tenant, compartido por los tres roles

Se mantiene el canal que ya existe, `tenant.{slug}.conductores` (`routes/channels.php`, spec 013), en
vez de crear canales separados por rol — evita duplicar la lógica de autorización y de disparo de
eventos. La autorización en `routes/channels.php` se amplía para aceptar dos guards distintos según
quién pide el canal:

- `conductor-token` (Sanctum) — ya existente, para `panda_express`.
- `usuario` (sesión) — nuevo, para el Panel (AdminCliente/Despachador).

En ambos casos se verifica que el tenant autenticado coincida con el `{slug}` del canal — nunca se
confía en el cliente (RN-01).

### Dos endpoints de `/broadcasting/auth`, uno por guard

Cada guard autentica peticiones distinto (Bearer token vs. cookie de sesión), así que cada uno
necesita su propia ruta de autorización de canal:

- `POST /t/{slug}/conductor/broadcasting/auth` — ya existe (guard `conductor-token`).
- `POST /t/{slug}/broadcasting/auth` — nueva, dentro del grupo `auth:usuario` que ya usa el resto del
  Panel (guard `usuario`).

### FCM como servicio de envío de push únicamente — MySQL sigue siendo la única base de datos

Un WebSocket (Reverb incluido) solo funciona mientras la app tiene una conexión activa; no puede
avisar nada si la app está cerrada o en segundo plano por mucho tiempo. Para eso existen las
notificaciones push nativas del sistema operativo, que en Android/iOS se entregan a través de
Firebase Cloud Messaging (FCM) — un servicio de **envío de mensajes**, no una base de datos. No se usa
Firestore ni Realtime Database en ningún punto: MySQL sigue siendo la única fuente de datos del
sistema; `conductor_dispositivos.fcm_token` es solo un string guardado ahí.

Para no agregar un SDK pesado (`kreait/firebase-php`) por una sola llamada, se implementa un servicio
propio `App\Services\FcmSender` que llama directo a la API HTTP v1 de FCM con el cliente `Http` que
ya trae Laravel, autenticado con un archivo de cuenta de servicio de Firebase (JSON, gratis,
descargado una vez desde la consola de Firebase). Las credenciales se agregan a `config/services.php`
+ `.env` (`FIREBASE_CREDENTIALS_PATH`) — configuración nueva, no dependencia de Composer nueva.

### Deduplicación con `event_id`, no con una tabla de bitácora

Cada evento de broadcast (existente y nuevo) agrega una propiedad `event_id` (uuid, generado al
construirse el evento) a su `broadcastWith()`. Cuando un evento crítico se manda por los dos caminos
a la vez (socket y FCM), ambos payloads llevan el mismo `event_id`; el cliente descarta el segundo que
le llegue. No hay tabla ni bitácora en el backend para esto — vive solo en memoria del cliente
(RN-06), reconstruible siempre desde `/conductor/sync` si se pierde.

### Un listener central decide cuándo también mandar push, sin tocar cada controlador

`App\Listeners\EnviarPushSiEsCritico` escucha los cuatro eventos "que no se pueden perder"
(`PedidoDisponible`, `PedidoCanceladoParaConductor`, `PedidoReprogramado`, `SaldoAcreditado`) y, además
de lo que el broadcast ya hace solo, busca el `fcm_token` del conductor afectado en
`conductor_dispositivos` y lo manda por `FcmSender`. Así ningún controlador necesita saber que existe
FCM — solo dispara el evento de dominio, igual que ya hace hoy.

### Endpoint de sincronización, no una ruta global suelta

`GET /t/{slug}/conductor/sync` (dentro del grupo `auth:conductor-token` que ya existe) devuelve, en
una sola respuesta, el pedido activo del conductor, su pool de pedidos disponibles y su saldo — el
mismo shape que ya arman `PedidoController@activo`/`@disponibles` y `SaldoController@show` por
separado. Es a lo que la app llama al reconectar (RN-02/RN-07), en vez de confiar en lo último que
tenía pintado.

### Reconexión con espera creciente sobre `pusher-js`

`pusher-js` (que ya usa `panda_express`, spec 013) reconecta solo, pero sin el patrón de espera
creciente que pide RN-07. `services/realtime.js` envuelve el evento `disconnected` con un temporizador
propio (1s, 2s, 4s… tope 30s) antes de reintentar `connect()`, y al recuperar la conexión
(`connected`) llama automáticamente a `/conductor/sync`.

### El Panel también necesita un cliente de tiempo real (hoy no tiene ninguno)

`frontend/` (Panel de AdminCliente/Despachador) no usa Echo/Pusher en absoluto hoy — solo
`panda_express` lo tiene. Se agrega un cliente nuevo, mismo patrón que `realtime.js` pero autenticando
con la sesión (`usuario`) contra `/t/{slug}/broadcasting/auth` en vez de un token Bearer. En esta spec
solo se deja la conexión lista (recibe eventos, sin romper si Reverb no está disponible); la UI que
reacciona a esos eventos es alcance de specs `020`/`021`.

## Reglas de negocio

- **RN-01**: Un usuario solo se suscribe a canales de su propio tenant. Se verifica en
  `/broadcasting/auth` (el del conductor o el del Panel, según el guard), nunca en el cliente.
- **RN-02**: El estado del servidor manda. Ante cualquier duda, la app llama a
  `GET /conductor/sync` y pinta lo que venga de ahí.
- **RN-03**: El WebSocket avisa, no decide. Los cambios de estado se hacen por HTTP; el socket solo
  difunde el resultado.
- **RN-04**: Los eventos que el conductor no puede perderse (`PedidoDisponible`,
  `PedidoCanceladoParaConductor`, `PedidoReprogramado`, `SaldoAcreditado`) se emiten por socket y por
  FCM al mismo tiempo. No hay reintentos: si llegan los dos, la app descarta el segundo por
  `event_id`.
- **RN-05**: Los eventos de alta frecuencia (`UbicacionActualizada`) van solo por socket. Si no hay
  conexión, se pierden — la siguiente posición los reemplaza sin problema.
- **RN-06**: La app guarda en memoria los `event_id` procesados de la sesión y descarta repetidos sin
  error. Al cerrar la app se olvidan; no hace falta persistirlos.
- **RN-07**: Al perder el socket, la app reconecta con espera creciente (1s, 2s, 4s… tope 30s) y llama
  a `/conductor/sync` en cuanto vuelve.

## Backend (Laravel)

- **Migración nueva** `database/migrations/tenant/…_create_conductor_dispositivos_table.php`: crea
  `conductor_dispositivos` según el modelo de datos de arriba.
- **Modelo nuevo** `App\Models\Tenant\ConductorDispositivo` + relación `Conductor::dispositivo(): HasOne`.
- **`config/services.php`** + `.env`: credenciales de Firebase (`FIREBASE_CREDENTIALS_PATH`).
- **Servicio nuevo** `App\Services\FcmSender`: manda un push a un `fcm_token` vía la API HTTP v1 de
  FCM, usando `Http` de Laravel — sin SDK nuevo en `composer.json`.
- **Listener nuevo** `App\Listeners\EnviarPushSiEsCritico`: registrado sobre los cuatro eventos
  críticos; resuelve el/los conductor(es) afectado(s) y llama a `FcmSender`.
- **Eventos existentes** (`PedidoDisponible`, `PedidoYaTomado`, `PedidoCanceladoParaConductor`):
  agregan `event_id` (uuid) a su `broadcastWith()`.
- **Eventos nuevos** (mismo patrón `ShouldBroadcast` + `event_id`):
  - `PedidoReprogramado` — se dispara al cambiar la fecha/hora agendada de un pedido ya asignado.
  - `SaldoAcreditado` — se dispara al acreditar viajes prepagados a un conductor
    (`VentaViajeConductorController`).
  - `UbicacionActualizada` — se dispara desde `Conductor\UbicacionController@actualizar`, además de lo
    que ya persiste en `conductor_estado`/`conductor_posiciones`.
- **Endpoint nuevo** `POST /t/{slug}/conductor/dispositivo` (`auth:conductor-token`): registra/actualiza
  el `fcm_token` del conductor autenticado en `conductor_dispositivos`.
- **Endpoint nuevo** `GET /t/{slug}/conductor/sync` (`auth:conductor-token`): devuelve pedido activo +
  pool disponible + saldo, para reconstruir el estado tras una reconexión.
- **Ruta nueva** `POST /t/{slug}/broadcasting/auth` (`auth:usuario`): autoriza el canal del tenant para
  el Panel.
- **`routes/channels.php`**: la autorización de `tenant.{slug}.conductores` acepta tanto `Usuario`
  autenticado por `conductor-token` como por sesión (`usuario`), verificando el tenant en ambos casos.

## Frontend (`panda_express`)

- **Registro de push**: al iniciar sesión, pide permiso de notificaciones al SO, obtiene el token del
  dispositivo y lo manda a `POST /conductor/dispositivo`.
- **`services/realtime.js`**: agrega un `Set` en memoria de `event_id` vistos (descarta repetidos);
  reconexión con espera creciente (1s, 2s, 4s… tope 30s); al reconectar llama a `GET /conductor/sync`.

## Frontend (`frontend/`, Panel)

- **Cliente de tiempo real nuevo** (mismo patrón que `realtime.js`, autenticado con la sesión del
  Panel contra `/t/{slug}/broadcasting/auth`): deja la conexión al canal del tenant lista para que las
  specs `020`/`021` la usen. Sin cambios visuales en esta spec.

## Fuera de alcance

- UI en `panda_express`/`frontend/` que reaccione a `PedidoReprogramado`, `SaldoAcreditado` o
  `UbicacionActualizada` — solo se deja la infraestructura (evento + canal + listener de push).
- Traducir las specs `019`-`022` (hoy en `couriers`/`deliveries` genérico) a los nombres reales del
  sistema.
- Cambios a `config/reverb.php`/`config/broadcasting.php` (ya resueltos por spec 013).
- Cualquier cambio al modelo de asignación de pedidos, transiciones de estado o liquidación (spec
  013, sin tocar).

## Criterios de aceptación

- Un conductor del tenant A recibe 403 al suscribirse a `private-tenant.B.conductores`.
- Un usuario del Panel (AdminCliente/Despachador) del tenant A recibe 403 al suscribirse al canal del
  tenant B, y 401 sin sesión.
- Con token/sesión expirado, `/broadcasting/auth` (cualquiera de los dos) devuelve 401.
- Un `PedidoCanceladoParaConductor` que llega por socket y por push actualiza la pantalla una sola vez
  (mismo `event_id`).
- Con la app cerrada, un `PedidoDisponible` llega como notificación push.
- Tras una reconexión, la app muestra lo que devuelve `/conductor/sync`, no lo que tenía en pantalla
  antes de perder la conexión.
- Cambiar de dispositivo deja un solo `fcm_token` activo por conductor en `conductor_dispositivos`.
- El Panel recibe `UbicacionActualizada` por socket en menos de 2 segundos después de que
  `panda_express` manda `POST /conductor/ubicacion`.
- Pint y ESLint/Prettier corren sin errores sobre el código nuevo; `php artisan test` pasa.

## Supuestos asumidos (registro completo)

1. `courier`/`delivery`/`repartidor` de la redacción original se traducen a `Conductor`/`Pedido`, los
   modelos y tablas ya existentes — no se crean tablas nuevas en inglés.
2. Se mantiene el canal único `tenant.{slug}.conductores` (spec 013) para los tres roles, en vez de
   crear canales separados por actor.
3. `courier_devices` se traduce a `conductor_dispositivos`, migración de **tenant** (no central),
   porque `Conductor`/`Usuario` son modelos de tenant.
4. FCM se usa **solo** como servicio de envío de push — no se usa ninguna base de datos de Firebase;
   MySQL sigue siendo la única fuente de datos del sistema. Se prefiere una llamada HTTP directa a la
   API v1 de FCM sobre instalar `kreait/firebase-php`, para no agregar una dependencia pesada por una
   sola llamada — esto se puede reconsiderar si en la implementación real la firma de la petición
   (JWT de la cuenta de servicio) resulta más simple con el SDK.
5. `event_id` se agrega a cada evento de broadcast (existente y nuevo) para deduplicar; no hay
   bitácora ni tabla de eventos en el backend, la deduplicación vive en memoria del cliente.
6. `/api/v1/sync` de la redacción original se traduce a `GET /t/{slug}/conductor/sync`, anidada en el
   grupo de rutas real del conductor, no una ruta global.
7. Mapeo de eventos: `NEW_DELIVERY_AVAILABLE` = `PedidoDisponible` (ya existe), `DELIVERY_CANCELLED` =
   `PedidoCanceladoParaConductor` (ya existe), `DELIVERY_SCHEDULE_UPDATED` = `PedidoReprogramado`
   (nuevo), `BALANCE_CREDITED` = `SaldoAcreditado` (nuevo).
8. Se agrega `UbicacionActualizada` (`LOCATION_UPDATE`) como evento nuevo de alta frecuencia, disparado
   desde `Conductor\UbicacionController@actualizar` — hoy ese endpoint no emite nada por socket.
9. El Panel (`frontend/`) no tiene hoy ningún cliente de tiempo real; se agrega uno nuevo con el mismo
   patrón que `panda_express`, autenticado por sesión (`usuario`) en vez de token.
10. Se agrega una ruta `/broadcasting/auth` nueva para el guard `usuario` (Panel), separada de la que
    ya existe para `conductor-token`.
11. Un listener central (`EnviarPushSiEsCritico`) decide cuándo mandar push, en vez de que cada
    controlador conozca la existencia de FCM.
12. El backoff de reconexión (RN-07) se implementa envolviendo los eventos de conexión de `pusher-js`
    con un temporizador propio, sin reemplazar la librería cliente.
13. La UI que consume `PedidoReprogramado`/`SaldoAcreditado`/`UbicacionActualizada` en pantalla queda
    fuera de esta spec — se deja la infraestructura lista para que las specs `019`-`022` la usen.
14. Las specs `019`-`022` (hoy con nombres genéricos en inglés) no se traducen en esta spec — queda
    como trabajo pendiente antes de implementarlas.
