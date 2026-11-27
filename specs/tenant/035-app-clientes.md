# SPEC-035 — App de clientes (PWA)

**Metadatos**

| Campo | Valor |
|---|---|
| ID | SPEC-035 |
| Módulo | Pedidos / Canal App de clientes |
| Versión | 1.3 |
| Estado | Borrador del 2026-11-27 (1.1: ajustes de la revisión técnica; 1.2: código de acceso por correo; 1.3: primero instalar, después registrarse) |
| Depende de | SPEC-005 (multi-tenant), SPEC-006 (pedidos, tarifa y máquina de estados), SPEC-015 (tarifas y saldo, RN-C5), SPEC-016 (geocerca), SPEC-020 (oferta y aceptación), SPEC-021 (live tracking), SPEC-024 (publicación), SPEC-025 (TEST/LIVE), SPEC-026 (cola), SPEC-028 (microservicio GPS), SPEC-031 (pedidos por WhatsApp), SPEC-034 (modo automático) |
| Plan de implementación | [`035-app-clientes-plan.md`](035-app-clientes-plan.md) |

---

> **Estados:** esta spec no agrega estados. Usa la máquina de estados de `tenant/006-crud-pedidos.md`, que sigue siendo la fuente única, y solo traduce sus estados a mensajes para el cliente (RN-40).

> **Nombres:** las clases, tablas, columnas y rutas nuevas son propuestas y pueden cambiar al programarlas.

> **Producción:** nada de esta spec se despliega a producción sin autorización expresa del dueño del proyecto. Todo sale apagado (D17).

### Cambios de la versión 1.1

| Tema | Cambio |
|---|---|
| Dirección | `motopanda.mx/pide/{flotilla}` en lugar de `/app/{flotilla}`, que ya usa Reverb (D4) |
| Saldo | Se conserva RN-C5: la falta de saldo no bloquea pedidos. Se elimina la antigua RN-33 (sección 16) |
| TEST | Se conserva el comportamiento actual. Sin staging: las pruebas de punta a punta se hacen en producción con una flotilla de prueba en LIVE (sección 15) |
| PWA | Solo PWA, sin Capacitor ni APK. Manifiesto, service worker, sesión y avisos aislados por flotilla (RN-05 a RN-09) |
| Teléfonos | Formato E.164. Los teléfonos duplicados quedan bloqueados en la app hasta conciliarlos (RN-16 a RN-19) |
| Código de acceso | Reglas de seguridad completas y nunca en logs de producción (RN-10 a RN-13) |
| Cotización | Servicio compartido `CotizacionPedidoService`, con pruebas de regresión de WhatsApp antes del cambio. Sello ligado al cliente y a todas las condiciones (RN-20 a RN-24) |
| Duplicados | La llave va ligada al cliente y a los datos. Si se repite con otros datos, se rechaza (RN-31) |
| Programados | La duración de la ventana es configurable por flotilla, con 60 minutos por defecto (RN-28) |
| GPS | Reenvío al cliente sin consultas a la base por cada posición, sin duplicados, dimensionado para 100 repartidores (RN-42) |
| Mapa | Ruta y tiempo estimado en el teléfono, con límites de recálculo y tokens de sesión de Places (RN-43, RN-45, RN-48) |
| Avisos | Varios dispositivos, aislados por flotilla, se desvinculan al cerrar sesión, se envían después de guardar y sin duplicados (RN-50 a RN-54) |
| Pedidos | 4 columnas nuevas para contacto de recogida y referencias |

### Cambios de la versión 1.3

| Tema | Cambio |
|---|---|
| Instalación | **Corrección:** en la definición de asunciones se eligió invitar a instalar "desde el primer momento", y la 1.0 lo anotó al revés ("después del registro"). Ahora: la liga abre una bienvenida con un botón grande "Instalar"; el registro viene después, desde la app instalada (D1, D7, 6.0) |
| Fases | El manifiesto, el service worker y la instalación pasan de la Fase 8 a la **Fase 3b**. En la Fase 8 quedan el logo y el banner de cada flotilla y los avisos push |

### Cambios de la versión 1.2

| Tema | Cambio |
|---|---|
| Código de acceso | Llega **por correo**, no por WhatsApp (D6). WhatsApp queda como mejora futura (sección 16) |
| Identidad | El correo es la identidad del cliente en la app (columna nueva `email_acceso`, única). El teléfono es un dato de contacto **sin verificar** (D23) |
| Clientes existentes | La app ya no se une sola a un cliente existente por su teléfono. Si el teléfono coincide con otro cliente, se crea la cuenta y el teléfono pasa a conciliación (RN-15, RN-19) |
| Teléfonos en revisión | Ya no impiden entrar a la app. Solo esperan a que el AdminCliente los unifique (D20) |
| Correo saliente | Se envía con Resend desde un remitente de `motopanda.mx` (sección 17) |

## 1. Historia de usuario

Como cliente nuevo de una flotilla quiero pedir un motomandado desde mi teléfono, de forma sencilla y
visual, sin escribirle a un despachador por WhatsApp, y seguir a mi repartidor en el mapa hasta que
me entregue.

## 2. Objetivo

Hoy un pedido entra por dos puertas: el Panel (lo captura un despachador) y WhatsApp (spec 031).
Esta spec agrega una tercera: una app web instalable (PWA) que el cliente usa por su cuenta.

El pedido que entra por la app es **un pedido normal**: se cotiza con la misma fórmula, se crea con
el mismo servicio, se oferta con las mismas rondas, respeta las reglas de saldo, TEST/LIVE y el
modo automático, y el conductor lo atiende en `panda_express` sin ningún cambio.

## 3. Alcance

**Incluye:**

- Una PWA por flotilla en `motopanda.mx/pide/{flotilla}`, con una sola base de código.
- Registro y entrada con correo y código de un solo uso enviado por correo. Sin contraseña.
- Pantalla de inicio con logo, banner, campanita y **solo dos botones principales: Enviar y
  Recibir**.
- Asistente de 4 pasos: Recogida, Entrega, Detalles y Confirmación.
- Cotización en el servidor, con precio fijo por 10 minutos.
- Creación del pedido sin duplicados.
- Pantalla "Estamos buscando un repartidor para ti".
- Seguimiento en vivo con mapa, repartidor, tiempo estimado, línea de progreso y llamada.
- Cancelación por el cliente antes de que un repartidor acepte.
- "Mis pedidos" (activos, completados y cancelados) y "Repetir servicio".
- "Mi cuenta": datos, direcciones favoritas y cerrar sesión.
- Avisos en el teléfono (push) y en la campanita.
- Invitación a instalar la app.
- En el Panel: interruptor de la app, logo, banner, horario de atención, ventana de programados y
  conciliación de clientes con teléfono duplicado.
- Pruebas controladas en producción con una flotilla de prueba (sección 15).

**No incluye:**

- Capacitor, APK, Android Studio, Play Store o App Store. Todo es PWA (D2).
- Pagos con tarjeta o en línea. Solo efectivo (D11).
- Chat dentro de la app. El contacto es una llamada (RN-44).
- Colores configurables por flotilla (D16).
- Dominios o subdominios propios por flotilla (D4).
- Cargos adicionales a la tarifa (RN-25).
- Tipos de servicio nuevos además de Enviar y Recibir (D9).
- Cambios en `panda_express` (D14).
- Cambios en el flujo de oferta, cola, publicación, modo automático, comisiones, saldo o TEST/LIVE.
- Que el cliente cambie un pedido ya creado (destino, hora). Eso sigue en el Panel (spec 022).
- Prepago estricto o límite de crédito por flotilla (sección 16).

## 4. Decisiones

| # | Decisión |
|---|---|
| D1 | La app es para clientes nuevos. No hay pantalla pública de pedidos: sin sesión, la app abre en la bienvenida con "Instalar" (si no está instalada) y después en "Registrarme", con un enlace chico "Ya tengo cuenta, entrar" |
| D2 | Solo PWA. Sin Capacitor, sin APK, sin Android Studio y sin tiendas. Una sola base de código para todas las flotillas |
| D3 | El cliente de la app es el mismo `Cliente` de hoy: misma tabla `clientes`, mismo módulo del Panel. Solo se agrega la entrada con correo y código |
| D4 | Cada flotilla tiene su app en `motopanda.mx/pide/{flotilla}`. `/app/` no se usa porque nginx ya lo manda a Reverb. Para el teléfono son apps distintas: manifiesto, service worker, sesión, almacenamiento y avisos separados por flotilla (RN-05 a RN-09) |
| D5 | Un mismo correo puede ser cliente de dos flotillas, pero solo usando las dos apps. Las cuentas nunca se mezclan |
| D6 | El código de acceso llega **por correo**, desde un remitente de MotoPanda con el nombre de la flotilla. El código por WhatsApp se agregará después como otra forma de entrar, sin quitar la del correo (decisión del 2026-11-26) |
| D7 | **Primero instalar, después registrarse** (corregida en 1.3). La liga abre una bienvenida con un botón grande "Instalar": en Android abre el aviso de instalación del teléfono; en iPhone, y en Android cuando el navegador no lo ofrece (por ejemplo, la liga abierta dentro de WhatsApp), muestra la guía de 3 pasos. Un enlace chico "Continuar en el navegador" salta la bienvenida; a quien lo usa se le recuerda instalar una sola vez, tras su primer pedido. En iPhone importa además porque la app instalada no comparte la sesión con Safari |
| D8 | Quien pide elige quién paga, con las mismas tres modalidades del Panel, en el paso 3 y con tres tarjetas con ícono. Es igual en Enviar y en Recibir |
| D9 | Solo dos servicios: Enviar y Recibir. Cada flotilla puede apagar uno de los dos |
| D10 | El precio lo calcula siempre el servidor con `CotizacionPedidoService`. La cotización queda fija 10 minutos con un sello cifrado del servidor |
| D11 | El único método de pago es efectivo al repartidor |
| D12 | Cada confirmación lleva una llave única ligada al cliente y a los datos. El servidor crea el pedido una sola vez por llave, y el botón se bloquea al apretarlo |
| D13 | Avisos push desde esta versión: repartidor asignado, llegó a recoger, va en camino, entregado y cancelado |
| D14 | `panda_express` no cambia |
| D15 | El seguimiento usa Reverb, se reconecta solo y, mientras la conexión está cortada, consulta al servidor cada 15 segundos |
| D16 | El dueño solo sube logo y banner. Nombre y contacto salen de lo que la flotilla ya tiene. Los colores son fijos (morado, amarillo y blanco). Sin logo o banner, se usan los de MotoPanda |
| D17 | Interruptores por flotilla, apagados por defecto: `app_clientes` (toda la app) y `app_push` (avisos push). Con `app_clientes` apagado, nada del sistema cambia para esa flotilla |
| D18 | Se conserva la regla de saldo actual (spec 015, RN-C5): el saldo de viajes puede quedar negativo y nada se bloquea por él |
| D19 | Se conserva el modo TEST actual. Igual que WhatsApp (spec 031, D3), la app no crea pedidos con la flotilla en TEST. El ciclo completo se prueba en producción con una flotilla de prueba en LIVE (sección 15) |
| D22 | No hay entorno staging por ahora (decisión del 2026-11-26). Cada fase se despliega a producción con sus interruptores apagados, y solo con autorización expresa |
| D23 | En la app, la identidad del cliente es su correo verificado. El teléfono se pide al registrarse para que el repartidor y la flotilla puedan llamarle, pero **no está verificado**: no sirve para entrar ni para unirse a otro cliente |
| D20 | Un teléfono que aparece en más de un cliente de la flotilla queda "por revisar" hasta que el AdminCliente concilie esos clientes. El sistema nunca elige uno por su cuenta ni une clientes solo. Un teléfono por revisar no impide entrar a la app, porque la identidad es el correo (D23) |
| D21 | La ruta y el tiempo estimado se calculan en el teléfono del cliente. El tiempo se muestra siempre como aproximado |

## 5. Actores y permisos

| Actor | Permisos |
|---|---|
| Cliente (app) | Se registra y entra con su correo. Ve y usa solo sus datos y sus pedidos dentro de la flotilla de esa app |
| `admin_cliente` | Prende y apaga la app y los avisos de su flotilla. Sube logo y banner. Configura horario y ventana de programados. Concilia clientes duplicados. Ve a los clientes y pedidos de la app en el Panel, como cualquier otro |
| `despachador` | Ve y atiende los pedidos de la app como cualquier otro. No configura la app ni concilia clientes |
| `conductor` | Sin cambios. Su token no sirve en las rutas de cliente (RN-14) |

## 6. Pantallas

### 6.0 Bienvenida (instalar)

Primera pantalla al abrir la liga, solo si la app no está instalada, no hay sesión y el cliente no
eligió "Continuar en el navegador":

- Encabezado con logo y banner de la flotilla.
- "Instala {flotilla} en tu celular", con una línea que explica que no se descarga de ninguna tienda.
- Botón grande amarillo **Instalar** (D7). Al aceptar en Android: "¡Listo! Busca {flotilla} en tu
  pantalla de inicio y ábrela desde ahí para crear tu cuenta".
- Enlace chico **Continuar en el navegador**, que lleva a Registrarme.

Abierta desde el ícono (instalada), la app nunca muestra esta pantalla.

### 6.1 Registro y entrada

1. **Registrarme**: nombre completo, celular a 10 dígitos y correo. Botón "Enviarme código".
2. **Ya tengo cuenta, entrar**: solo correo.
3. **Código**: 6 casillas, reenvío habilitado a los 60 segundos y el aviso "Si no lo ves, revisa tu
   carpeta de spam".
4. Al verificar entra a Inicio. Si acaba de registrarse, se le pide el permiso de avisos (RN-50).

### 6.2 Inicio

Siguiendo la imagen de referencia:

- Arriba: menú, logo y nombre de la flotilla, campanita con punto si hay avisos sin leer.
- Banner de la flotilla.
- "¿Qué necesitas hoy?" con **solo dos botones principales: Enviar y Recibir**. Un botón apagado
  por la flotilla no se muestra.
- Barra inferior fija: **Inicio**, **Mis pedidos** y **Mi cuenta**.
- Si el cliente tiene un pedido activo, una franja arriba de los botones lleva a su seguimiento.
- La franja "Entrega el mismo día" de la imagen de referencia no se incluye.

### 6.3 Asistente (4 pasos)

Barra de pasos arriba, botón "Siguiente" grande abajo y "Atrás" que conserva lo capturado.

| Paso | Campos |
|---|---|
| 1. Recogida | Dirección (autocompletado de Google Places, "Usar mi ubicación", o elegir en el mapa) · Referencias (opcional) · Interruptor "La entrega otra persona": si se activa, pide nombre y teléfono de contacto; si no, se usan los del cliente |
| 2. Entrega | Dirección (autocompletado o mapa) · Referencias (opcional) · Nombre y teléfono de quien recibe |
| 3. Detalles | Quién paga: 3 tarjetas con ícono (D8) · Importe del producto, solo con la tercera · "Lo antes posible" o "Programar" · Fecha y hora, solo si programa |
| 4. Confirmación | Mapa con la ruta · recogida y entrega · distancia y tiempo aproximados · costo del envío · importe del producto si aplica · total · "Pago en efectivo" · botón **Confirmar y solicitar servicio** |

Rellenos de entrada:

- **Enviar**: la recogida trae la ubicación actual, o "Casa" si no hay permiso de ubicación. El
  contacto de la recogida es el cliente.
- **Recibir**: la entrega trae la ubicación actual o "Casa", y quien recibe es el cliente. La
  recogida queda vacía.
- En cualquier dirección, las favoritas del cliente aparecen como atajos arriba del buscador.

### 6.4 Buscando repartidor

Animación y el mensaje "Estamos buscando un repartidor para ti", número de servicio, resumen corto
de la ruta y el botón "Cancelar pedido" (RN-36).

### 6.5 Seguimiento

- Mapa con: moto (repartidor), marcador verde (recogida), marcador rojo (entrega), línea punteada
  hacia la recogida mientras va por el paquete y línea continua hacia la entrega.
- Tarjeta inferior: globo con las iniciales del repartidor (spec 033), nombre, tiempo aproximado,
  botón **Llamar** y número de servicio. Si en el futuro los conductores tienen foto, se muestra la
  foto.
- Línea de progreso con los mensajes de RN-40.
- Aviso "Reconectando..." si la conexión en tiempo real está cortada (RN-47).

### 6.6 Mis pedidos

Tres pestañas: **Activos**, **Completados** y **Cancelados**. Cada tarjeta muestra fecha y hora,
tipo (Enviar/Recibir), recogida → entrega, total y estado. Al tocarla se abre el detalle. Los
activos llevan a su seguimiento. Los completados y cancelados tienen el botón **Repetir** (RN-55).

### 6.7 Mi cuenta

Nombre, celular, correo (solo lectura), direcciones favoritas ("Casa" y "Trabajo"
fijas, más otras con nombre libre), "Instalar la app" (si no está instalada), avisos encendidos
o apagados, y **Cerrar sesión**.

### 6.8 Panel: clientes duplicados

En el módulo de clientes del Panel, solo para el AdminCliente, una pestaña **"Por revisar"** con los
teléfonos que aparecen en más de un cliente. Por cada teléfono muestra los clientes, su número de
pedidos y su último pedido, y el botón **"Unificar"** (RN-18).

## 7. Modelo de datos

Todas las migraciones son de solo agregar: columnas nulas y tablas nuevas.

### `clientes` (columnas nuevas, todas nulables)

- `email_acceso` — string(191), **único**. El correo con el que el cliente entra a la app, en
  minúsculas y sin espacios. Es una columna aparte de `email` (texto libre que ya existe y puede
  estar repetido), así que no choca con los datos actuales.
- `email_verificado_en` — timestamp. Se llena la primera vez que el cliente verifica un código.
- `telefono_e164` — string(16), **único**. El teléfono en formato E.164 (`+52` y 10 dígitos). Solo
  lo tiene un cliente cuyo teléfono no está repetido (RN-16).
- `telefono_verificado_en` — timestamp. Queda vacío en esta versión: se llenará cuando exista el
  código por WhatsApp (sección 16).
- `origen` — enum `PANEL`, `WHATSAPP`, `APP`. Nulo en los clientes que ya existen. Solo informa en
  el Panel de dónde llegó el cliente; no cambia ninguna regla.

`Cliente` usa `HasApiTokens` (Sanctum).

### `pedidos` (columnas nuevas, todas nulables)

- `origen` — enum `PANEL`, `WHATSAPP`, `APP`. Nulo en los pedidos existentes.
- `tipo_servicio` — enum `ENVIAR`, `RECIBIR`. Solo la llenan los pedidos de la app.
- `contacto_recogida_nombre`, `contacto_recogida_telefono` — quién entrega el paquete.
- `referencias_recogida`, `referencias_entrega` — string(255).

El Panel y `panda_express` no muestran todavía las columnas de contacto y referencias. Mostrarlas es
una mejora aparte (sección 16).

### Tabla nueva `telefonos_en_revision` (por tenant)

- `telefono_e164` — PK
- `clientes` — JSON con los `id_cliente` que comparten el teléfono
- `detectado_en` — timestamp

### Tabla nueva `cliente_codigos` (por tenant)

- `id_codigo` — PK
- `email` — indexado (el `email_acceso` al que se mandó)
- `codigo_hmac` — HMAC-SHA256 del código con una llave del servidor. El código nunca se guarda en
  claro
- `intentos` — tinyint, default 0
- `expira_en` — timestamp
- `usado_en` — timestamp nulable
- `ip` — string(45)
- `created_at`

Un comando diario borra los de más de 24 horas.

### Tabla nueva `cliente_solicitudes_pedido` (por tenant)

La llave anti-duplicados (RN-31).

- `id_solicitud` — PK
- `id_cliente` — FK
- `llave` — UUID
- `huella` — SHA-256 de los datos de la solicitud
- `id_pedido` — FK nulable (nulo mientras se procesa)
- `created_at`
- Índice único `(id_cliente, llave)`.

Un comando diario borra las de más de 48 horas.

### Tabla nueva `cliente_suscripciones_push` (por tenant)

- `id_suscripcion` — PK
- `id_cliente` — FK, indexado
- `id_token` — FK a `personal_access_tokens`. Liga la suscripción a la sesión que la creó
- `endpoint` — único
- `clave_p256dh`, `clave_auth`
- `created_at`, `updated_at`

### Tabla nueva `cliente_notificaciones` (por tenant)

La campanita y el registro de avisos enviados. No se reusa `notificaciones` (tabla 17) porque cuelga
de `id_usuario`.

- `id_notificacion` — PK
- `id_cliente` — FK, indexado
- `id_pedido` — FK
- `tipo` — enum `ASIGNADO`, `EN_RECOGIDA`, `EN_CAMINO`, `ENTREGADO`, `CANCELADO`
- `titulo`, `mensaje`
- `leida_en` — timestamp nulable
- `created_at`
- Índice único `(id_pedido, tipo)`: un aviso por pedido y tipo.

Se conservan 90 días.

### `configuraciones_tenant` (sin tabla nueva, claves nuevas)

| Clave | Valores | Default |
|---|---|---|
| `app_clientes` | `No`, `Si` | `No` |
| `app_push` | `No`, `Si` | `No` |
| `app_logo` | ruta del archivo | nulo (usa el de MotoPanda) |
| `app_banner` | ruta del archivo | nulo (usa el de MotoPanda) |
| `app_servicio_enviar` | `No`, `Si` | `Si` |
| `app_servicio_recibir` | `No`, `Si` | `Si` |
| `horario_atencion` | JSON por día de la semana | nulo (24 horas) |
| `ventana_programado_min` | entero de 15 a 240 | 60 |

### Configuración central (no por tenant)

- Correo saliente: el mismo Resend por SMTP que ya usa el sistema. Remitente propio para los
  códigos, `OTP_MAIL_FROM` (por ejemplo `codigos@motopanda.mx`), con el nombre de la flotilla como
  nombre visible.
- Canal del código: `OTP_CANAL` = `correo` o `log`. `log` solo se acepta con `APP_ENV=local`; en
  cualquier otro ambiente el arranque falla (RN-13). `whatsapp` se agregará después (sección 16).
- Llaves VAPID para los avisos push (variables de entorno).
- Llave HMAC de los códigos y llave del sello: derivadas de la `APP_KEY` de Laravel.

## 8. Endpoints

Todos bajo `/api/t/{slug}` con el middleware `tenant.slug`, como el resto del sistema.

**Públicos** (con límite de peticiones):

- `GET /t/{slug}/cliente/marca` — nombre, logo, banner, teléfono de contacto, servicios activos y
  si la app está prendida.
- `GET /t/{slug}/cliente/manifest.webmanifest` — manifiesto de la flotilla (RN-05).
- `POST /t/{slug}/cliente/codigo` — `{ email, modo: "registro" | "entrar", nombre?, telefono? }`.
  Manda el código (RN-10).
- `POST /t/{slug}/cliente/verificar` — `{ email, codigo, nombre?, telefono? }`. Devuelve el token.

**Con sesión** (`auth:cliente-token`):

- `POST /t/{slug}/cliente/salir`
- `GET|PUT /t/{slug}/cliente/perfil`
- `GET|POST|PUT|DELETE /t/{slug}/cliente/direcciones[/{id}]`
- `POST /t/{slug}/cliente/cotizar` → distancia, importe y sello.
- `POST /t/{slug}/cliente/pedidos` — encabezado `Idempotency-Key`. Cuerpo con el sello y todos los
  datos del pedido.
- `GET /t/{slug}/cliente/pedidos?grupo=activos|completados|cancelados`
- `GET /t/{slug}/cliente/pedidos/{pedido}`
- `GET /t/{slug}/cliente/pedidos/{pedido}/seguimiento` — estado, repartidor y última posición.
  Es la consulta de respaldo de D15.
- `POST /t/{slug}/cliente/pedidos/{pedido}/cancelar`
- `GET /t/{slug}/cliente/notificaciones` y `POST .../{id}/leida`
- `POST|DELETE /t/{slug}/cliente/push`
- `POST /t/{slug}/cliente/broadcasting/auth`

**Panel** (`auth:usuario`, solo AdminCliente):

- `PUT /t/{slug}/configuracion/app-clientes` — interruptores, servicios, horario y ventana.
- `POST /t/{slug}/configuracion/app-clientes/logo` y `.../banner` — subida de imágenes.
- `GET /t/{slug}/clientes/por-revisar`
- `POST /t/{slug}/clientes/unificar` — `{ telefono_e164, id_principal }`.

## 9. Reglas de negocio

### Interruptores y sesión

- **RN-01.** Con `app_clientes = No`, `marca` responde que la app no está disponible y la app
  muestra "Esta flotilla todavía no recibe pedidos por aquí", con su teléfono de contacto. Los
  demás endpoints de cliente responden 403, salvo consultar un pedido que ya estaba en curso.
- **RN-02.** Sin sesión, la app solo muestra Registro, Entrar y Código (D1).
- **RN-03.** La sesión dura hasta que el cliente cierra sesión. El token no caduca por tiempo; se
  revoca al cerrar sesión, al unificar al cliente con otro (RN-18) o si el cliente queda
  `Inactivo` (los clientes solo tienen `Activo` e `Inactivo`).
- **RN-04.** Un cliente con `estado` distinto de `Activo` no puede entrar ni pedir. La app le
  muestra el teléfono de la flotilla.
- **RN-04b.** Las rutas `/api/v1/t/{slug}/cliente/*` no piden token CSRF. La app vive en el mismo
  dominio que el Panel y Sanctum trataría sus peticiones como "del sitio", pero se autentica con
  token Bearer y no con la cookie de sesión. El resto de rutas conserva el CSRF.

### PWA por flotilla

- **RN-05.** El manifiesto de cada flotilla tiene:
  - `id` y `start_url`: `/pide/{slug}/`
  - `scope`: `/pide/{slug}/`
  - `name` y `short_name`: el nombre de la flotilla
  - íconos de 192 y 512 con el logo de la flotilla, o con el de MotoPanda
  - `display: standalone` y los colores fijos de la marca
- **RN-06.** El service worker se sirve en `/pide/sw.js` y se registra con alcance `/pide/{slug}/`,
  así que cada flotilla tiene su propio registro y sus propias suscripciones push. El archivo se
  sirve sin caché (`Cache-Control: no-cache`).
- **RN-07.** Actualización segura del service worker:
  - Una versión nueva se instala en espera y no reemplaza a la vieja a media sesión.
  - La app muestra "Hay una versión nueva" con el botón "Actualizar". Al tocarlo se activa la nueva
    y la página se recarga.
  - Si el cliente no la toca, la nueva se activa la siguiente vez que abra la app.
- **RN-08.** Caché: el service worker solo guarda los archivos estáticos de la app, que llevan
  huella en el nombre. **Nunca** guarda respuestas de `/api`, tokens, datos del cliente ni
  posiciones. Al activarse, borra las cachés de versiones anteriores.
- **RN-09.** Almacenamiento en el teléfono: todas las llaves llevan el slug (`pide:{slug}:...`). Se
  guarda solo el token, la marca de "ya se ofreció instalar" y el borrador del asistente. Cerrar
  sesión borra todas las llaves de esa flotilla y ninguna de otra.

### Código de acceso

- **RN-10.** Normalización:
  - El correo se pasa a minúsculas y sin espacios, y debe tener un formato válido con dominio que
    reciba correo (registro MX). Así se rechazan errores como `gmial.com` sin mandar nada.
  - El teléfono se normaliza a E.164 de México (`+52` y 10 dígitos): se quitan espacios, guiones y
    prefijos viejos (`044`, `045`, `1` después de `52`). Un número que no queda en 10 dígitos se
    rechaza. En el registro es obligatorio.
- **RN-11.** El código es de 6 dígitos, se genera con un generador seguro, dura **10 minutos** (el
  correo puede tardar más que un mensaje) y se guarda como HMAC. Es de un solo uso y pedir otro
  invalida el anterior.
- **RN-12.** Límites:
  - Reenvío: 1 código por minuto por correo.
  - 5 códigos por hora y 10 por día por correo.
  - 20 códigos por hora por IP.
  - 300 códigos por hora por flotilla y un tope diario global por debajo del límite del plan de
    Resend, para que un abuso no agote el envío de correos de todo el sistema.
  - 5 intentos por código; al quinto fallo se invalida.
  - 10 fallos en una hora bloquean ese correo 1 hora.

  Las respuestas no revelan si el correo existe, salvo lo que pide RN-15.
- **RN-13.** El código nunca se escribe en logs, ni en errores ni en la auditoría. Se manda en un
  correo sencillo (texto y HTML) con el nombre y el logo de la flotilla, el código en grande y el
  aviso "Si no pediste este código, ignora este correo". El asunto no lleva el código. El canal
  `log` solo existe en desarrollo local (sección 7, configuración central).
- **RN-14.** Separación de tokens: el guard `cliente-token` solo acepta tokens cuyo dueño es un
  `Cliente`, y `conductor-token` solo los de un `Usuario` (Sanctum ya compara el modelo del provider).
  Además, el token de cliente lleva la habilidad `cliente`, y las rutas de cliente la exigen.
- **RN-15.** Al verificar:
  - Si hay un cliente con ese `email_acceso`, entra a ese cliente y ve sus pedidos.
  - Si no existe y vino de "Registrarme", se crea un cliente con `estado = Activo`, `origen = APP`,
    `email_acceso`, `nombre` y `telefono`. Nunca se une solo a un cliente existente, aunque el
    teléfono coincida (D23):
    - Si el teléfono normalizado no lo tiene nadie, el cliente nuevo recibe su `telefono_e164`.
    - Si ya lo tiene otro cliente (por ejemplo, uno de WhatsApp), el nuevo se guarda sin
      `telefono_e164` y el teléfono entra a `telefonos_en_revision`. El cliente entra y pide
      normalmente; su historial anterior aparece cuando el AdminCliente los unifica (RN-18).
  - Si alguien pide código por "Ya tengo cuenta" con un correo que no existe en esa flotilla, se le
    dice "No encontramos tu cuenta" y se le ofrece registrarse con ese mismo correo, sin mandar
    código.

### Teléfonos duplicados y conciliación

- **RN-16.** Un comando, `clientes:normalizar-telefonos`, recorre los clientes de cada flotilla:
  - Por defecto solo reporta y no cambia nada.
  - Con `--aplicar`, llena `telefono_e164` en los clientes cuyo teléfono normalizado es único, y
    registra en `telefonos_en_revision` los que se repiten.
  - Los teléfonos inválidos quedan en el reporte y sin `telefono_e164`.
- **RN-17.** Se corre con `--aplicar` en cada flotilla **antes** de prender su `app_clientes`, para
  que los clientes de la app se comparen contra teléfonos ya normalizados. Los teléfonos en revisión
  no impiden prender la app ni entrar a ella (D20).
- **RN-18.** Unificar (solo AdminCliente, con confirmación):
  1. El AdminCliente elige el cliente principal.
  2. En una sola transacción, los pedidos, direcciones y conversaciones de WhatsApp de los demás
     pasan al principal.
  3. Los demás quedan `Inactivo`, sin `telefono_e164`, y sus tokens se revocan.
  4. El principal recibe el `telefono_e164` y el teléfono sale de `telefonos_en_revision`.
  5. Si alguno de los unificados tenía `email_acceso` y el principal no, el principal lo recibe, para
     que el cliente siga entrando a la app con su correo y ahora vea todo su historial. Si dos tenían
     `email_acceso`, el AdminCliente elige cuál conserva el principal; el otro deja de servir para
     entrar.
  6. Queda una entrada en `auditoria` con los ids movidos, para poder revisarlo a mano.
- **RN-19.** Los clientes que se den de alta después por el Panel o por WhatsApp no cambian de
  comportamiento. Si su teléfono normalizado ya pertenece a otro cliente, se guardan como hoy, sin
  `telefono_e164`, y el teléfono entra a `telefonos_en_revision`. Así no se rompe ningún flujo
  existente.

### Cotización

- **RN-20.** Recogida y entrega deben caer dentro del área de servicio de la flotilla (spec 016). Si
  alguna queda fuera, el paso no avanza y se muestra "Por ahora no llegamos a esta dirección".
- **RN-21.** El importe lo calcula `CotizacionPedidoService`: área de servicio, distancia por calles y
  tarifa, redondeado a múltiplo de $5 (spec 006). Sin ruta real no se cotiza (spec 031, RN-02). Es
  el mismo servicio que usa WhatsApp. Antes de mover la lógica de WhatsApp a este servicio se
  escriben pruebas de regresión que fijan sus cotizaciones y respuestas actuales, y deben pasar sin
  cambios después (sección 13).
- **RN-22.** La app nunca calcula ni envía un precio propio. Muestra lo que devuelve `cotizar`.
- **RN-23.** El sello va cifrado y firmado por el servidor, y contiene: `id_cliente`, slug,
  coordenadas de recogida y entrega, km, importe, tipo de servicio, modalidad de pago, importe del
  producto, "lo antes posible" o fecha y hora, y vencimiento (10 minutos).
- **RN-24.** Al confirmar, el servidor:
  - Rechaza un sello alterado, de otro cliente o de otra flotilla.
  - Compara cada dato del pedido con el sello; si alguno difiere, rechaza.
  - Vuelve a validar el área de servicio, el horario (RN-29), el estado del cliente, el
    interruptor de la app y el ambiente (D19).
  - Si el sello venció, responde con una cotización nueva. Si el precio cambió, la app lo muestra y
    pide confirmar otra vez. Si es igual, la app reenvía con el sello nuevo sin preguntar.
- **RN-25.** No hay cargos adicionales en esta versión. Si después se agregan, entran en
  `CotizacionPedidoService` y la app los muestra sin cambios en el frontend.
- **RN-26.** Si la flotilla no tiene las tres tarifas configuradas, la app no deja cotizar y
  muestra el teléfono de contacto (mismo bloqueo de la spec 006).

### Detalles y horario

- **RN-27.** Las tres modalidades y sus reglas son las del Panel (spec 006): el importe del producto
  solo existe con `RECEPTOR_PAGA_ENVIO_PRODUCTOS` y el servidor lo fuerza a 0 en las demás. En esa
  modalidad, el resumen avisa la regla de regreso si el comprador no paga (spec 031, RN-09).
- **RN-28.** Un pedido programado se guarda con `hora_desde` = la hora elegida y `hora_hasta` =
  `hora_desde` + `ventana_programado_min` (60 por defecto). Se publica 15 minutos antes de
  `hora_desde` (spec 024). La hora debe quedar dentro de `horario_atencion` y al menos 30 minutos
  en el futuro.
- **RN-29.** Fuera de `horario_atencion` solo se puede programar. "Lo antes posible" aparece
  deshabilitado con el horario de la flotilla. Sin `horario_atencion` configurado, la flotilla
  atiende las 24 horas.

### Creación del pedido

- **RN-30.** `POST pedidos` crea el pedido con `CreacionPedidoService::crear()`, el mismo que usan el
  Panel y WhatsApp, con `id_cliente` del token, `origen = APP`, `tipo_servicio` y las columnas de
  contacto y referencias. No se escribe una creación paralela.
- **RN-31.** Llave anti-duplicados:
  - La app genera un UUID al entrar al paso 4 y lo renueva al cambiar cualquier dato.
  - El servidor intenta insertar `(id_cliente, llave, huella)` en `cliente_solicitudes_pedido`; el
    índice único hace que solo una petición gane aunque lleguen al mismo tiempo.
  - Si la llave ya existe para ese cliente con la misma huella y el pedido ya se creó, responde con
    ese pedido.
  - Si existe con la misma huella y todavía se está procesando, responde 409 "Tu pedido se está
    procesando" y la app reintenta en 2 segundos.
  - Si existe con otra huella, responde 422 y no crea nada.
  - La búsqueda siempre es por `(id_cliente, llave)`: la llave de otro cliente nunca se encuentra,
    así que nunca se devuelve un pedido ajeno.
  - Si la creación falla, se borra la fila para que un reintento pueda crear el pedido.
- **RN-32.** Todo lo que pasa después es lo de hoy: publicación (spec 024), ofertas y rondas
  (spec 020), cola (spec 026), modo automático (spec 034) y aviso al Panel en tiempo real con
  `PedidoCreado`.
- **RN-33.** *(Eliminada en 1.1.)* El saldo de viajes no bloquea pedidos de la app (D18).
- **RN-34.** Límite: 5 pedidos por hora por cliente y no más de 3 activos a la vez.

### Espera y cancelación

- **RN-35.** Mientras el pedido esté en `PENDIENTE` o `PUBLICADO`, la app muestra "Estamos buscando
  un repartidor para ti". Si agota las rondas y vuelve a `PENDIENTE`, el mensaje no cambia.
- **RN-36.** El cliente puede cancelar solo en `PENDIENTE` o `PUBLICADO`. Se usa la transición
  existente de `PedidoEstadoService` con `cancelado_por = CLIENTE`, así que salen los mismos avisos
  que hoy a conductores y Panel.
- **RN-37.** Con repartidor asignado, la app no muestra "Cancelar". Muestra "¿Necesitas cancelar?
  Llama a la flotilla" con su teléfono.
- **RN-38.** Si el sistema cancela el pedido (despachador o modo automático), el cliente ve el
  pedido en "Cancelados", recibe un aviso y, si lo canceló el modo automático, el mensaje "No
  encontramos repartidor disponible".

### Seguimiento

- **RN-40.** Mensajes por estado:

  | Estado | Mensaje al cliente |
  |---|---|
  | `PENDIENTE`, `PUBLICADO` | Buscando repartidor |
  | `TOMADO` | Tu repartidor va por el paquete |
  | `ARRIBADO` | Tu repartidor llegó a recoger |
  | `EN_CAMINO` | Tu paquete va en camino |
  | `ARRIBADO_A_ENTREGA` | Tu repartidor llegó a la entrega |
  | `ENTREGADO` | Entregado |
  | `INCIDENCIA` | Hubo un problema con tu envío. La flotilla te contactará |
  | `EN_REGRESO` | Tu paquete regresa con quien lo envió |
  | `DEVUELTO` | Tu paquete fue devuelto |
  | `CANCELADO` | Pedido cancelado |

  La línea de progreso tiene 5 puntos: Buscando, Recogida, En camino, Entrega y Entregado.

- **RN-41.** Canal privado nuevo `tenant.{slug}.cliente.{id_cliente}`, autorizado solo para un token
  de cliente de esa flotilla cuyo `id_cliente` coincide. Ahí llegan los cambios de estado de **sus**
  pedidos (`PedidoClienteActualizado`). Los eventos actuales del Panel y de los conductores no
  cambian.
- **RN-42.** Posición del repartidor (`UbicacionRepartidor`):
  - Solo se emite si el conductor tiene un pedido con `origen = APP` entre `TOMADO` y
    `ARRIBADO_A_ENTREGA`, y solo al canal del cliente de ese pedido.
  - Para no consultar la base en cada posición, la relación conductor → (pedido, cliente) se guarda
    en caché (Redis) por 60 segundos, incluido el caso "sin pedido de app". Se borra en cada
    transición del pedido. Así hay como máximo una consulta por minuto por conductor.
  - Se engancha en `ConsumirBuzonGps::difundir()` y en `TrackingService::registrarPosicion()`,
    después del filtro de posición que ya existe y del `UbicacionActualizada` actual. Cada posición
    pasa por uno solo de los dos caminos.
  - No se emite si la posición es igual a la última enviada o si pasaron menos de 3 segundos desde
    la última de ese conductor.
  - Si Reverb falla, se pierde ese refresco y nada más, como en el Panel (spec 018, RN-05).
  - Dimensionado para 100 repartidores mandando posición cada 5 a 10 segundos (hasta 20 posiciones
    por segundo en total).
- **RN-43.** La ruta del mapa la pide el teléfono a Google Maps (D21):
  - Una vez al asignarse el repartidor (hacia la recogida) y otra al pasar a `EN_CAMINO` (hacia la
    entrega).
  - Se vuelve a pedir solo si el repartidor se aleja más de 200 metros de la ruta y pasó al menos
    1 minuto desde la última, con un máximo de 10 rutas por pedido.
  - Las posiciones nuevas solo mueven el marcador de la moto; no se redibuja el mapa.
- **RN-44.** El botón **Llamar** abre la marcación al teléfono del repartidor. Solo aparece
  mientras el pedido está activo y tiene repartidor.
- **RN-45.** El tiempo de llegada se calcula en el teléfono: la duración de la ruta de Google,
  proporcional a la distancia que falta. Siempre se muestra como aproximado ("~8 min").
- **RN-46.** El cliente solo puede consultar sus propios pedidos. Un pedido de otro cliente responde
  404, no 403, para no confirmar que existe. La posición del repartidor solo se entrega mientras el
  pedido está activo.
- **RN-47.** Si la conexión en tiempo real se corta, la app se reconecta sola. Mientras tanto
  consulta `seguimiento` cada 15 segundos y muestra "Reconectando...". Al reconectar deja de
  consultar. Con la app en segundo plano no consulta.
- **RN-48.** Autocompletado de direcciones:
  - Espera 300 ms después de la última tecla y al menos 3 caracteres.
  - Usa un token de sesión de Places por búsqueda (desde que empieza a escribir hasta que elige),
    para que Google cobre una sesión y no cada tecla.
  - Limita los resultados a México y los orienta a la zona de servicio de la flotilla.
  - La llave pública de Google Maps de la app solo funciona en `motopanda.mx/pide/*`.

### Avisos

- **RN-50.** El permiso de avisos se pide después de registrarse, nunca al abrir la app por primera
  vez. En iPhone solo se pide si la app ya está instalada.
- **RN-51.** Con `app_push = Si`, avisos push y en campanita en: repartidor asignado (`TOMADO`),
  llegó a recoger (`ARRIBADO`), va en camino (`EN_CAMINO`), entregado (`ENTREGADO`) y cancelado
  (`CANCELADO`). Solo para pedidos con `origen = APP`. Con `app_push = No`, solo la campanita.
- **RN-52.** Envío seguro:
  - El aviso se prepara después de que la transición se guardó en la base, con el mismo mecanismo
    diferido que ya usa `PedidoEstadoService` para el consumo de viajes.
  - Primero se inserta en `cliente_notificaciones`. Si ya existía `(id_pedido, tipo)`, no se manda
    nada: así no hay avisos duplicados.
  - El push sale por la cola (`EnviarAvisoCliente`) a cada suscripción del cliente, y una falla
    nunca frena un cambio de estado.
- **RN-53.** Suscripciones:
  - Un cliente puede tener varios dispositivos. Cada suscripción queda ligada a su cliente y a la
    sesión (token) que la creó, en la base de su flotilla.
  - Si llega una suscripción con un `endpoint` que ya existe, se reasigna al cliente y token actuales.
  - Al cerrar sesión, la app da de baja la suscripción del navegador y el servidor borra las
    suscripciones de ese token, aunque la app no haya avisado.
  - Si el servicio de push responde que la suscripción ya no existe (404 o 410), se borra.
- **RN-54.** Los avisos de WhatsApp de la spec 031 no se mandan a pedidos de la app.

### Historial y cuenta

- **RN-55.** "Repetir" abre el asistente con las mismas direcciones, contactos, modalidad e importe
  del producto. Se vuelve a cotizar con la tarifa actual y vale todo lo anterior (área de servicio,
  horario).
- **RN-56.** Las direcciones favoritas se guardan en `direcciones_clientes` (spec 005), con alias
  "Casa", "Trabajo" o libre. El despachador también las ve. Máximo 10 por cliente.
- **RN-57.** El cliente puede cambiar su nombre y su celular (el nuevo pasa por RN-10 y, si ya lo
  tiene otro cliente, por RN-15). El correo de acceso no se cambia desde la app en esta versión,
  porque cambiarlo exige verificar el nuevo.

### Marca de la flotilla

- **RN-60.** Logo: PNG o JPG cuadrado, máximo 1 MB, mínimo 512 × 512. El servidor genera los
  íconos de 192 y 512.
- **RN-61.** Banner: PNG, JPG o WebP horizontal, máximo 2 MB.
- **RN-62.** El nombre comercial y el teléfono de contacto salen de los datos que la flotilla ya
  tiene. Sin logo o banner, se usan los de MotoPanda (D16).

## 10. Proceso

```
Cliente abre motopanda.mx/pide/{flotilla}
            │
   ¿app_clientes = Si?  ── no ──▶ "Esta flotilla todavía no recibe pedidos por aquí"
            │ sí
   ¿tiene sesión? ── no ──▶ Registrarme / Entrar ─▶ código por correo ─▶ token
            │ sí                 (teléfono de otro cliente ─▶ cuenta nueva + teléfono por revisar)
          Inicio ─▶ Enviar o Recibir
            │
   Paso 1 Recogida ─▶ Paso 2 Entrega ─▶ (área de servicio)
            │
   Paso 3 Detalles ─▶ Paso 4 Confirmación ◀── cotizar (CotizacionPedidoService + sello 10 min)
            │
   Confirmar (Idempotency-Key ligada al cliente y a los datos)
            │
   ¿TEST? ── sí ──▶ "servicio no disponible"
            │ no
   CreacionPedidoService::crear()  ── mismo flujo de hoy ──▶ Panel (PedidoCreado)
            │                                          └──▶ ofertas a conductores
   "Buscando repartidor"
            │  (canal tenant.{slug}.cliente.{id})
   TOMADO ─▶ seguimiento en vivo + push ─▶ … ─▶ ENTREGADO
```

## 11. Cambios en archivos existentes (lo mínimo, sin romper nada)

| Archivo | Cambio |
|---|---|
| `config/auth.php` | Guard `cliente-token` (Sanctum) y provider `clientes` |
| `Cliente` | `HasApiTokens`, columnas nuevas, relación con pedidos |
| `ConfiguracionTenant` | Constantes de las claves nuevas |
| `ConversacionWhatsappService` | `cotizar()` y la validación de área pasan a llamar a `CotizacionPedidoService`, después de las pruebas de regresión. `registrarCliente()` aplica RN-19 |
| `ClienteController` (Panel) | Alta y edición aplican RN-19 sin cambiar sus respuestas |
| `CreacionPedidoService::crear()` | Acepta `origen`, `tipo_servicio` y las columnas de contacto y referencias |
| `PedidoEstadoService::transicionar()` | Tras guardar: borra la caché de RN-42, emite `PedidoClienteActualizado` y prepara el aviso al cliente (RN-52) |
| `ConsumirBuzonGps::difundir()` y `TrackingService::registrarPosicion()` | Una llamada nueva al final para RN-42; lo que ya hacen no cambia |
| `routes/api.php` | Grupo `t/{slug}/cliente` y rutas de configuración y conciliación |
| `routes/channels.php` | Canal `tenant.{slug}.cliente.{id}` |
| `routes/console.php` | Purgas diarias de códigos, solicitudes y notificaciones |
| `frontend/vite.config.ts` | Segunda entrada `pide.html` |
| `deploy/vps/nginx-motopanda.mx.conf` | Bloque `location /pide/` antes del bloque del Panel, y `/pide/sw.js` sin caché |
| Panel: Configuración | Sección "App de clientes" |
| Panel: Clientes | Pestaña "Por revisar" y etiqueta de origen "App" |
| Panel: Pedidos | Etiqueta de origen "App" |

**No cambian:** la máquina de estados, la fórmula de precio, el redondeo, la oferta y sus rondas,
la cola, la publicación de agendados, el modo automático, las comisiones, el saldo y su consumo, el
modo TEST, el bot y los avisos de WhatsApp, los eventos y canales actuales, y `panda_express`.

## 12. Criterios de aceptación

1. Con `app_clientes = No`, la app muestra el mensaje de RN-01, los endpoints de cliente responden
   403 y todas las pruebas actuales pasan sin modificarse.
2. Un correo nuevo se registra, recibe el código por correo, entra y queda en `clientes` con
   `origen = APP`, su `email_acceso` y su `telefono_e164`.
3. Un registro cuyo teléfono ya tiene otro cliente crea una cuenta nueva, no ve el historial ajeno
   y deja el teléfono "por revisar". Después de unificar, el mismo correo entra al principal y ve
   todo el historial.
4. "Ya tengo cuenta" con un correo que no existe no manda código y ofrece registrarse.
5. El mismo correo registrado en dos flotillas tiene dos cuentas separadas. El token de una
   responde 401 en la otra.
6. Un token de conductor responde 401 en rutas de cliente, y uno de cliente responde 401 en rutas
   de conductor.
7. Los límites de RN-12 se cumplen, y ningún código aparece en los logs con `APP_ENV=production`.
8. Una dirección fuera del área de servicio no deja avanzar el asistente.
9. Las cotizaciones de WhatsApp dan exactamente lo mismo antes y después del cambio a
   `CotizacionPedidoService`.
10. El precio del paso 4 es igual al que calcula el Panel para la misma ruta.
11. Se rechaza un sello alterado, de otro cliente, de otra flotilla o con algún dato distinto.
12. Dos `POST pedidos` simultáneos con la misma llave crean un solo pedido. La misma llave con
    otros datos responde 422.
13. Con la flotilla en TEST, la app no crea pedidos.
14. Con saldo de viajes en cero o negativo, la app crea el pedido igual que el Panel.
15. El pedido creado aparece en el Panel en tiempo real y recibe ofertas como cualquier otro.
16. Un pedido programado usa la ventana configurada de la flotilla.
17. El cliente cancela en `PUBLICADO`; en `TOMADO` ya no ve el botón.
18. El cliente ve moverse la moto solo entre `TOMADO` y `ARRIBADO_A_ENTREGA`, y otro cliente no
    puede suscribirse a su canal.
19. Con 100 repartidores simulados mandando posición cada 5 segundos, el consumo del buzón GPS no se
    atrasa y cada posición hace como máximo una consulta a la base por minuto por conductor.
20. Al cortar la conexión, aparece "Reconectando..." y el estado se sigue actualizando cada
    15 segundos.
21. El cliente recibe un solo push por transición en cada uno de sus dispositivos. Al cerrar sesión
    en uno, ese dispositivo deja de recibirlos.
22. Dos flotillas instaladas en el mismo Android y el mismo iPhone quedan como dos apps, con su
    propio ícono, su propia sesión y sus propios avisos. Cerrar sesión en una no afecta a la otra.
23. Una versión nueva de la app muestra "Hay una versión nueva" y no rompe la sesión en curso.
24. Ninguna respuesta de `/api` queda guardada en la caché del service worker.
25. `panda_express` funciona sin cambios con pedidos de la app.

## 13. Pruebas automáticas

**Backend (Pest):**

- `WhatsappCotizacionRegresionTest`: se escribe **antes** del cambio. Fija cotizaciones, mensajes y
  rechazos por área del bot con rutas conocidas (Google simulado).
- `CotizacionPedidoServiceTest`: área de servicio, igualdad con `TarifaService`, sin ruta real.
- `ClienteAislamientoTenantTest`: datos, tokens, canales y suscripciones nunca cruzan de flotilla.
- `ClienteAccesoTest`: envío del correo (con `Mail::fake()`) y verificación de código,
  normalización de correo y E.164, límites por correo, IP y flotilla, intentos, bloqueo, cliente
  existente y nuevo, teléfono de otro cliente, cliente suspendido, códigos fuera de logs y fuera
  del asunto.
- `TokensPorTipoTest`: cliente contra rutas de conductor y Panel, y viceversa.
- `TelefonosDuplicadosTest`: comando en modo reporte y `--aplicar`, bloqueo en verificación,
  unificación completa y su auditoría, RN-19 en Panel y WhatsApp.
- `ClienteCotizacionTest`: sello válido, alterado, de otro cliente, de otra flotilla, con datos
  distintos y vencido.
- `ClienteIdempotenciaTest`: misma llave y mismos datos, misma llave y otros datos, peticiones
  concurrentes (dos procesos), llave de otro cliente, fallo a mitad.
- `ClientePedidoTest`: creación con el servicio existente, TEST, saldo negativo, límites,
  modalidades, horario y ventana.
- `ClienteCancelacionTest`: estados permitidos y prohibidos.
- `ClienteCanalesTest`: autorización del canal propio, rechazo del ajeno y de otra flotilla.
- `ClienteSeguimientoTest`: posición solo en estados activos, 404 a ajenos, consulta de respaldo,
  caché de RN-42, sin duplicados.
- `ClienteAvisosTest`: un aviso por transición, solo después de guardar, solo `origen = APP`,
  varios dispositivos, baja al cerrar sesión, suscripciones caducadas.
- `ClienteDireccionesTest`: CRUD propio, límite de 10, sin acceso a direcciones ajenas.
- `ConfiguracionAppClientesTest`: permisos, subida de logo y banner, interruptores, ventana.
- Rendimiento: un comando de prueba que simula 100 conductores con posición cada 5 segundos contra
  `ConsumirBuzonGps` y mide tiempo por posición y consultas a la base.
- Las suites actuales de pedidos, oferta, publicación, cola, modo automático y WhatsApp deben pasar
  sin modificarse.

**Frontend (`node --test`):** pasos del asistente, mensajes por estado, cálculo del tiempo
aproximado, reglas de recálculo de ruta, llaves de almacenamiento por slug.

**Manual en producción, con flotillas de prueba (sección 15):** instalación de dos flotillas en un
Android y un iPhone reales, push con la app cerrada, actualización del service worker, corte de
conexión durante un seguimiento.

## 14. Fases

| Fase | Contenido |
|---|---|
| 1 | Entregable técnico y plan definitivo ([`035-app-clientes-plan.md`](035-app-clientes-plan.md)) |
| 2 | Prototipo navegable con datos simulados |
| 3 | Registro, código por correo y conciliación de teléfonos |
| 3b | Instalación: bienvenida con "Instalar", manifiesto por flotilla, service worker con aviso de versión nueva e íconos por defecto (adelantado de la Fase 8) |
| 4 | Pruebas de regresión de WhatsApp, `CotizacionPedidoService`, direcciones y cotización |
| 5 | Creación del pedido, idempotencia y cancelación |
| 6 | Seguimiento en vivo |
| 7 | Mis pedidos, Repetir y direcciones favoritas |
| 8 | Logo y banner por flotilla (también en el ícono instalado) y avisos push |
| 9 | Pruebas de seguridad y rendimiento, y pruebas controladas en producción con las flotillas de prueba |

Cada fase puede desplegarse a producción **solo con autorización expresa** y con los interruptores
apagados en las flotillas reales.

## 15. Pruebas en producción (sin staging)

No hay staging por ahora (D22). El ciclo completo (registro, código, pedido, oferta, aceptación desde
`panda_express`, GPS, Reverb y push) se prueba en producción así:

- **Dos flotillas de prueba en LIVE** dadas de alta en producción (dos, para probar el aislamiento y
  la instalación de dos PWA). Solo ellas tienen `app_clientes = Si`. En las flotillas reales sigue
  `No`.
- Sus conductores son teléfonos de prueba con la `panda_express` de producción.
- Sus pedidos son LIVE: consumen viajes del saldo de esas flotillas de prueba (puede quedar
  negativo, RN-C5) y generan comisiones de prueba. No tocan a las flotillas reales, porque cada
  flotilla tiene su propia base.
- **Código por correo:** los clientes de prueba usan correos reales del equipo. Como el correo no
  limita quién se registra, mientras la app esté prendida solo en las flotillas de prueba su
  dirección no se comparte fuera del equipo.
- Las pruebas de rendimiento GPS (criterio 19) corren en local, no en producción.
- Antes de prender la app en una flotilla real se borran o desactivan los datos de prueba que
  pudieran confundir (clientes de prueba, direcciones).

## 16. Mejoras futuras (fuera de esta versión)

- **Reglas de crédito por flotilla:** prepago estricto (no se crean pedidos sin saldo), saldo
  negativo permitido (lo de hoy, RN-C5) o un límite de crédito configurable. Afectaría por igual al
  Panel, a WhatsApp y a la app.
- **Código por WhatsApp** (decisión del 2026-11-26: después del correo). Se agrega como otra forma
  de entrar: el cliente elige "por correo" o "por WhatsApp". Al verificar por WhatsApp se llena
  `telefono_verificado_en`, y ese teléfono verificado sí podrá unirse solo al cliente de WhatsApp
  que ya existía, en lugar de esperar la conciliación. Requiere el número central de WhatsApp
  Business y una plantilla de autenticación aprobada por Meta.
- Mostrar en el Panel y en `panda_express` el contacto de recogida y las referencias.
- Foto del repartidor.
- Cargos adicionales configurables en la tarifa.
- Cambios del cliente sobre un pedido ya creado.

## 17. Pendientes para la implementación

- **Remitente de correo en `motopanda.mx`.** Hoy el sistema manda correo como
  `no-reply@prosello.com.mx`, el dominio viejo. Un código que llega desde otro dominio parece
  sospechoso y cae más fácil en spam. Hace falta verificar `motopanda.mx` en Resend (autorizado el
  2026-11-26): se agregan en GoDaddy los registros que da Resend: TXT `resend._domainkey` (DKIM) y
  CNAME `send` y `rsend` (envío). Ninguno está en el dominio raíz, así que **no cambia** el SPF de
  Zoho ni el DMARC actual. "Enable receiving" se deja apagado: la recepción es de Zoho. Luego, `OTP_MAIL_FROM` y el
  `MAIL_FROM_ADDRESS` del servidor pasan a una dirección de `motopanda.mx`.
- **Plan de Resend.** Revisar el límite diario de correos del plan actual. Si es el gratuito
  (100 al día), alcanza para las pruebas pero no para abrir la app a flotillas reales.
- **Memoria del droplet:** 2 GB sin swap y unos 640 MB libres (revisado el 2026-11-26). La app suma
  conexiones a Reverb y trabajos de push. Se recomienda agregar swap antes de prender la app en
  flotillas reales.
