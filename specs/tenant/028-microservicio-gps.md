# SPEC-028 — Microservicio GPS en tiempo real

**Metadatos**

| Campo | Valor |
|---|---|
| ID | SPEC-028 |
| Módulo | Logística / Tiempo real |
| Autor | A. Rivas |
| Versión | 1.0 |
| Estado | Borrador |
| Sprint | S-12 |
| Depende de | SPEC-018 (Protocolo realtime), SPEC-019 (Disponibilidad), SPEC-021 (Live tracking), SPEC-026 (Polilínea) |
| Habilita a | — |

Sustituye a `specs/go.md`, que planteaba la misma idea sobre un stack que no es el de este proyecto
(PostGIS, JWT en lugar de Sanctum, Docker obligatorio, sin multi-tenancy).

---

## 1. Objetivo

Sacar la ingesta de posiciones GPS del ciclo de PHP y llevarla a un servicio dedicado, para que el
rastreo en vivo aguante muchos conductores simultáneos y siga funcionando aunque la API de negocio
esté caída o reiniciándose.

**Laravel sigue siendo el dueño de la verdad del negocio.** El servicio nuevo solo administra
posición y presencia: no decide a quién se le ofrece un pedido, ni quién tiene saldo, ni quién está
autorizado a nada.

## 2. Alcance

**Incluye:** el servicio de ingesta GPS, el permiso temporal con el que el conductor le habla, el
almacenamiento en memoria de la posición actual, la búsqueda de conductores cercanos, la
persistencia del recorrido en tandas, la difusión al Panel sin que el Panel cambie, y la migración
ordenada desde el tracking actual.

**No incluye:** reemplazar Laravel Reverb (SPEC-018 sigue vigente tal cual), mover la App del
conductor a un canal WebSocket propio del servicio, PostgreSQL/PostGIS, migrar la plataforma a
contenedores, ni cambiar oferta, aceptación, cola, estados de pedido, saldo, comisiones o geocercas.

**Explícitamente fuera:** el servicio **no** escribe en MySQL. Todo lo que deba persistir pasa por
Laravel.

## 3. Actores y permisos

| Actor | Permisos frente al servicio GPS |
|---|---|
| `conductor` | Manda **su propia** posición. No puede consultar la de nadie. |
| `admin_cliente` / `despachador` | Ninguno directo: ven el mapa por el canal de siempre (SPEC-018). |
| `Laravel` (servicio a servicio) | Consulta conductores cercanos y publica avisos de negocio. |
| `super_admin` | Ninguno directo. |

## 4. Arquitectura y frontera

```text
   App conductor (Capacitor)
        │  posición
        ▼
   ┌─────────────────┐        buzón        ┌──────────────────┐
   │  Servicio GPS   │ ──────────────────▶ │     Laravel      │
   │                 │ ◀────────────────── │                  │
   │  posición       │        buzón        │  negocio, MySQL, │
   │  presencia      │                     │  Sanctum, Reverb │
   │  cercanía       │ ◀─── consulta ───── │                  │
   └────────┬────────┘                     └────────┬─────────┘
            │                                       │ Reverb
            ▼                                       ▼
         memoria                                Panel Vue
   (posición actual)                          (sin cambios)
```

| Componente | De qué responde |
|---|---|
| **Laravel** | Usuarios, tenants, conductores, pedidos, asignaciones, saldo, estados, permisos, persistencia y difusión al Panel. |
| **Servicio GPS** | Recibir posiciones, validar el permiso, guardar la posición actual, presencia, cercanía. |
| **Memoria (Redis)** | Posición actual, presencia, permisos cancelados y los dos buzones de comunicación. |

Esta frontera no se cruza en ninguna dirección durante toda la implementación.

## 5. Modelo de datos

### 5.1 MySQL — sin cambios de esquema

`conductor_posiciones` y `conductor_estados` se conservan tal cual. Lo único que cambia es **quién
escribe** en ellas y **con qué frecuencia** (§7.4, §7.5).

### 5.2 Memoria — claves, todas con el tenant por delante

| Clave | Tipo | Contenido | Caducidad |
|---|---|---|---|
| `tenant:{slug}:conductores:geo` | GEO | Posición de cada conductor con envío activo | Se limpia al terminar el envío |
| `tenant:{slug}:conductor:{id}:pos` | Hash | `latitud`, `longitud`, `rumbo`, `velocidad`, `precision`, `bateria`, `fecha_posicion` | 3 min |
| `tenant:{slug}:conductor:{id}:envio` | String | `id_pedido` del envío en curso | 12 h |
| `tenant:{slug}:conductor:{id}:cancelado-desde` | String | Instante desde el que sus permisos dejan de valer | 30 min |
| `gps:hacia-laravel` | Stream | Posiciones a difundir y tandas de recorrido | 24 h |
| `gps:hacia-servicio` | Stream | Avisos de negocio de Laravel | 24 h |

El `id_conductor` **nunca** determina el tenant por sí solo: el tenant sale siempre del permiso
(RN-14).

### 5.3 El permiso GPS

Boleto firmado que Laravel emite y el servicio verifica sin consultar a nadie. Contiene:

| Campo | Significado |
|---|---|
| `jti` | Identificador único del permiso |
| `tenant` | Slug del tenant, el mismo de la URL |
| `sub` | `id_conductor` |
| `iat` | Cuándo se emitió — es lo que permite cancelarlo (RN-10) |
| `exp` | Caduca a los 30 minutos |

Se firma con `GPS_TOKEN_SECRET`, un secreto propio y distinto de `APP_KEY`.

## 6. Endpoints y eventos

### 6.1 Servicio GPS

| Método | Ruta | Quién | Éxito |
|---|---|---|---|
| GET | `/health` | Nadie (público) | 200 |
| POST | `/gps/v1/ping` | Conductor con permiso GPS | 204 |
| GET | `/gps/v1/nearby` | Laravel con token de servicio | 200 |

**`POST /gps/v1/ping`** — `Authorization: Bearer {permiso_gps}`

```json
{
  "latitud": 20.5248,
  "longitud": -100.8132,
  "precision": 12,
  "velocidad": 34.5,
  "rumbo": 118,
  "bateria": 74,
  "fecha_posicion": "2026-09-08T10:14:22Z"
}
```

No lleva `id_conductor` ni `tenant`: se toman del permiso. Si vienen en el cuerpo, se ignoran
(RN-15).

**`GET /gps/v1/nearby?lat=&lng=&radio_km=&limite=`** — `Authorization: Bearer {token_servicio}` y
`X-Tenant: {slug}`

```json
{
  "conductores": [
    { "id_conductor": 41, "distancia_km": 0.8, "fecha_ms": 1788862460000 },
    { "id_conductor": 17, "distancia_km": 1.4, "fecha_ms": 1788862451000 }
  ]
}
```

Devuelve **solo geografía**. Ordenar, filtrar por saldo, por despachador o por elegibilidad es de
Laravel (RN-16).

**`GET /health`** responde el estado del servicio y si la memoria contesta. No revela versiones ni
configuración.

### 6.2 Laravel

| Método | Ruta | Descripción | Éxito |
|---|---|---|---|
| POST | `/api/{slug}/conductor/gps-token` | Emite o renueva el permiso GPS | 200 |

Autenticado con `auth:conductor-token` (Sanctum, como todo el resto de la App).

```json
{
  "token": "…",
  "expira_en": "2026-09-08T10:44:22Z",
  "renovar_en": "2026-09-08T10:34:22Z",
  "url": "https://delivery.prosello.com.mx/gps/v1/ping"
}
```

`POST /api/{slug}/conductor/ubicacion` y
`POST /api/{slug}/conductor/pedidos/{pedido}/ubicaciones/lote` **se conservan** (§12).

### 6.3 Buzón Laravel → servicio (`gps:hacia-servicio`)

| Aviso | Cuándo | Efecto en el servicio |
|---|---|---|
| `ENVIO_INICIADO` | El pedido pasa a un estado de envío en curso | Habilita el rastreo del conductor y fija su `id_pedido` |
| `ENVIO_TERMINADO` | Entregado, cancelado o reasignado | Vacía la tanda pendiente y saca al conductor del mapa |
| `PERMISO_CANCELADO` | Cierre de sesión o apagado del conductor | Anota desde cuándo dejan de valer sus permisos |

### 6.4 Buzón servicio → Laravel (`gps:hacia-laravel`)

| Aviso | Cuándo | Efecto en Laravel |
|---|---|---|
| `POSICION` | En cada posición aceptada, con envío en curso | Dispara `UbicacionActualizada` por Reverb, igual que hoy |
| `POSICION_SIN_ENVIO` | En cada posición aceptada, sin envío en curso (§16) | Actualiza `conductor_estados.ultima_latitud/longitud`, sin difundir ni generar historia |
| `RECORRIDO` | Cada 10 s o 50 puntos | Inserta la tanda en `conductor_posiciones` |
| `LATIDO` | Cada 60 s por conductor | Actualiza `conductor_estados.ultima_actualizacion` |

Ambos buzones son **buzón, no megáfono**: un reinicio del servicio o del worker no pierde avisos
(RN-17).

## 7. Proceso

### 7.1 El conductor inicia sesión

Entra con Sanctum como siempre, pide su permiso GPS y guarda la dirección del servicio. Si el
endpoint del permiso falla, la App sigue mandando posición a Laravel por la ruta de siempre
(§12, RN-19).

### 7.2 Renovación del permiso

La App renueva a los 20 minutos, en segundo plano. El conductor no ve nada. Si no hay internet para
renovar, deja de mandar en vivo y acumula localmente hasta reconectar (RN-08).

### 7.3 Llega una posición

1. Verificar la firma y que no esté caducado. Si falla → `401`.
2. Comprobar que el `jti` no esté cancelado. Si lo está → `401`.
3. Sacar `tenant` e `id_conductor` **del permiso**, nunca del cuerpo.
4. Validar coordenadas, precisión y antigüedad. Si la posición es más vieja que la última guardada →
   se descarta (RN-11).
5. Guardar posición actual y presencia en memoria, **haya o no envío en curso** (§16): un conductor
   sin envío sigue siendo geolocalizable en `/nearby` mientras esté en línea (RN-06), y Laravel
   necesita su último punto real para poder arrancar ahí la simulación TEST del próximo envío que
   acepte.
6. Comprobar que el conductor tenga envío en curso (§6.3).
   - Si lo tiene, encolar `POSICION` para el Panel y sumar el punto a la tanda del recorrido.
   - Si no, encolar `POSICION_SIN_ENVIO`: Laravel actualiza `conductor_estados` sin difundir al
     Panel ni generar historia (RN-01).
7. Responder `204` sin esperar a nada de lo anterior.

### 7.4 El recorrido llega a MySQL

El servicio junta los puntos por conductor y los suelta al buzón cada **10 segundos o 50 puntos**,
lo que ocurra primero, y siempre al recibir `ENVIO_TERMINADO`. Un worker de Laravel los inserta en
`conductor_posiciones` con su `id_pedido`. El servicio **nunca** abre MySQL.

Si el servicio se reinicia con una tanda a medias, se pierden a lo sumo esos 10 segundos de trazo
(RN-09). La polilínea de SPEC-026 y el resumen de ruta de SPEC-021 (RN-08) no cambian.

### 7.5 El Panel se entera

El worker de Laravel lee `POSICION` y dispara `UbicacionActualizada` en el canal privado del tenant,
el mismo evento y el mismo canal de hoy. **El Panel no cambia ni una línea.**

### 7.6 Laravel busca conductores cercanos

Cuando el negocio necesita candidatos, Laravel llama a `/gps/v1/nearby`, recibe una lista ordenada
por distancia y **encima de eso** aplica sus reglas: saldo, elegibilidad, despachador, cola,
prioridad. Si el servicio no responde, Laravel cae a la consulta actual sobre `conductor_estados`
(RN-18).

### 7.7 Cuando algo se cae

| Se cae | Qué sigue funcionando | Qué se pierde |
|---|---|---|
| Laravel | La App manda posición y el servicio la guarda | El Panel deja de verlo moverse hasta que vuelva; el recorrido espera en el buzón |
| Servicio GPS | La App acumula localmente y sube al reconectar | El vivo, mientras dure. Nada del recorrido |
| Memoria | Nada del GPS | El Panel muestra la última posición conocida con aviso **"sin señal"**, nunca un mapa vacío |

## 8. Reglas de negocio

- **RN-01:** Se guarda toda posición válida, haya o no envío en curso (§16, corrige la redacción
  original heredada de SPEC-021). Sin envío no se difunde al Panel ni se acumula recorrido —eso sí
  sigue exclusivo del envío en curso—, pero la posición se conserva en `conductor_estados` como
  última ubicación conocida del conductor.
- **RN-02:** El servicio sabe si hay envío en curso **porque Laravel se lo avisó** (§6.3), nunca
  preguntándole en cada posición.
- **RN-03:** La App sigue decidiendo cuándo enviar y qué lecturas descartar: cada 15 s, o antes al
  moverse 50 m, o cada minuto si lleva 2 min detenido; descarta precisión mayor a 100 m o velocidad
  implícita mayor a 150 km/h. Hereda RN-02 y RN-03 de SPEC-021.
- **RN-04:** Un conductor en línea **sin** envío manda un latido cada 60 s, para no ser apagado
  mientras espera trabajo.
- **RN-05:** El apagado por inactividad sigue siendo de Laravel, a los **10 minutos** sin señal
  (SPEC-019, RN-04). No cambia.
- **RN-06:** La presencia en memoria caduca a los **3 minutos**. Es una cosa distinta del apagado de
  RN-05: a los 3 min el conductor deja de aparecer en búsquedas de cercanía y en el mapa como "en
  vivo"; sigue en línea hasta los 10 min.
- **RN-07:** El permiso GPS dura **30 minutos** y se renueva a los **20**.
- **RN-08:** Sin internet, la App acumula hasta 200 puntos localmente y los sube por el endpoint de
  lotes de Laravel al reconectar. Hereda RN-05 de SPEC-021, y los puntos por lote **no** se difunden
  al Panel (RN-07 de SPEC-021).
- **RN-09:** El recorrido se persiste en tandas de 10 s o 50 puntos. Se acepta perder hasta esa
  tanda ante un reinicio.
- **RN-10:** Al cerrar sesión o apagarse, **todos** los permisos vivos del conductor quedan
  cancelados de inmediato y la siguiente posición se rechaza, aunque no hayan caducado. Se cancela
  por conductor y por fecha, no permiso a permiso: un conductor puede tener más de uno vigente a la
  vez —acaba de renovar, o entró desde un segundo teléfono— y revocar solo el último dejaría al
  anterior mandando posiciones hasta media hora después.
- **RN-11:** Se ignora toda posición más vieja que la última ya guardada de ese conductor.
- **RN-12:** Máximo **30 posiciones por minuto** por conductor y **2 KB** de cuerpo. Lo que exceda se
  rechaza sin guardar.
- **RN-13:** Los envíos en modo TEST se siguen manejando por Laravel: el simulador escribe como hoy y
  el servicio GPS no participa.
- **RN-14:** El tenant sale siempre del permiso. Ninguna estructura en memoria es global: si a una
  clave le falta el tenant, es un defecto.
- **RN-15:** El `id_conductor` efectivo es el del permiso. Si el cuerpo trae otro, se ignora en
  silencio.
- **RN-16:** El servicio devuelve geografía, no decisiones. Nunca elige a quién asignar, quién tiene
  prioridad, quién tiene saldo ni quién puede aceptar.
- **RN-17:** La comunicación entre Laravel y el servicio es por buzón con acuse: un aviso no se
  descarta hasta que se procesó.
- **RN-18:** Si el servicio no responde, Laravel cae a `conductor_estados` para la cercanía y a su
  endpoint actual para la posición. La operación nunca se detiene por el servicio GPS.
- **RN-19:** La App detecta que el servicio no responde y vuelve al endpoint de Laravel mientras
  tanto.
- **RN-20:** El servicio no escribe en MySQL. Nunca.

## 9. Errores

| Situación | Respuesta | Qué ve el conductor |
|---|---|---|
| Permiso ausente, mal firmado o caducado | `401` | La App renueva y reintenta una vez; si falla, cae a Laravel |
| Permiso cancelado | `401` | Se le pide volver a entrar |
| Coordenadas fuera de rango o cuerpo inválido | `422` | Nada: la App descarta el punto |
| Cuerpo mayor a 2 KB | `413` | Nada |
| Más de 30 por minuto | `429` con `Retry-After` | La App espacia los envíos |
| Sin envío en curso | `204` | Nada: se comporta como éxito (RN-01) |
| Memoria caída | `503` | La App acumula localmente |
| Tenant del permiso desconocido | `401` | Se le pide volver a entrar |

Nunca se registra el permiso completo en los logs, ni coordenadas junto a datos personales.

## 10. Criterios de aceptación

1. **200 conductores por tenant** mandando posición cada 5 s se sostienen 10 minutos con el 99 % de
   respuestas por debajo de 50 ms y sin crecimiento de memoria.
2. Apagando Laravel, las posiciones se siguen aceptando; al volver, el Panel se pone al día y
   **ningún punto del recorrido se perdió**.
3. Apagando el servicio, la App acumula y al volver el recorrido del envío queda completo.
4. Un permiso de un tenant no puede escribir ni leer nada de otro tenant. Se prueba explícitamente.
5. Cerrar sesión invalida el permiso: la siguiente posición recibe `401`.
6. Una posición con fecha anterior a la última guardada no mueve al conductor en el mapa.
7. El recorrido guardado por el servicio es equivalente al que guardaba Laravel: misma polilínea y
   mismo resumen de distancia al entregar.
8. El Panel funciona sin ningún cambio de código.
9. Sin envío en curso, ninguna posición llega a `conductor_posiciones`.
10. Con la memoria caída, el Panel muestra la última posición con aviso de "sin señal".

## 11. Adiciones técnicas aceptadas

1. **Permiso firmado (HS256)** con `GPS_TOKEN_SECRET` propio, distinto de `APP_KEY`. Evita consultar
   a Laravel en cada posición y es lo que sostiene la resiliencia de §7.7.
2. **Marca de cancelación por conductor** en memoria, con la misma vida que el permiso (30 min):
   se guarda desde cuándo dejan de valer sus permisos, y el servicio rechaza los emitidos antes de
   ese instante. Cubre de una vez todos los que tuviera abiertos.
3. **Índice geoespacial en memoria** (`GEOADD` / `GEOSEARCH`) para la posición actual y la cercanía.
4. **Caducidad automática** de la presencia: sin proceso vigilante.
5. **Persistencia en tandas** a través del buzón, con inserción masiva del lado de Laravel.
6. **Buzón con acuse** (Redis Streams con grupo de consumidores) en ambas direcciones, no
   publicación al aire.
7. **Difusión al Panel por Laravel**: el servicio encola, un worker de Laravel dispara
   `UbicacionActualizada`. El Panel y SPEC-018 quedan intactos.
8. **Arranque como servicio del sistema** (systemd) detrás de nginx, bajo el dominio y certificado
   actuales, en la ruta `/gps/`. Sin puertos expuestos.
9. **Persistencia en disco de la memoria** (AOF) y política de expulsión que nunca tire las claves de
   posición antes que las de caché.
10. **Freno por conductor** y límite de tamaño de cuerpo.
11. **Descarte de posiciones desordenadas** comparando contra la última fecha guardada.
12. **Prueba de carga reproducible** que simule 200 conductores, versionada junto al servicio, como
    condición para evaluar más adelante un canal WebSocket propio.

## 12. Impacto en el código existente

| Archivo | Cambio |
|---|---|
| `Conductor/UbicacionController@actualizar` | **Se conserva** como respaldo (RN-19). Sin cambios de comportamiento. |
| `Conductor/UbicacionController@lote` | **Sin cambios.** El respaldo offline sigue por Laravel. |
| `TrackingService::registrarPosicion()` | **Sin cambios.** Lo reutiliza el worker del buzón para insertar las tandas. |
| `TrackingService::registrarLatido()` | **Sin cambios.** Lo llama el worker al recibir `LATIDO`. |
| `TrackingService::calcularResumenRuta()` | **Sin cambios.** Se dispara igual al entregar. |
| `ApagarConductoresInactivos` | **Sin cambios.** Sigue con sus 10 minutos (RN-05). |
| `PurgarPosicionesAntiguas` | **Sin cambios.** Misma retención. |
| `PedidoEstadoService` | Publica `ENVIO_INICIADO` / `ENVIO_TERMINADO` al buzón, después del commit y sin poder tumbar la transacción (SPEC-018, RN-08). |
| `Conductor/AuthController@logout` y disponibilidad | Publican `PERMISO_CANCELADO`. |
| Rutas de conductor | Se agrega `POST /gps-token`. |
| Comando nuevo | `gps:consumir-buzon`, worker que procesa `gps:hacia-laravel`. |
| Servicio nuevo | `gps-service/` en Go, fuera de `backend/`. |
| Panel Vue | **Ninguno.** |
| App Capacitor | Nueva dirección de envío, renovación del permiso y detección de caída. |

Nada de oferta, aceptación, cola, saldo, comisiones ni geocercas se toca.

## 13. Migración desde el tracking actual

Cinco pasos, cada uno reversible y sin dejar de rastrear ningún envío:

1. **Sombra.** El servicio se despliega y recibe posiciones, pero la App sigue mandando también a
   Laravel. Laravel manda al Panel; el servicio solo mide. Se comparan los dos recorridos.
2. **Recorrido.** El worker del buzón empieza a insertar en `conductor_posiciones` y Laravel deja de
   insertar en el flujo normal. La comparación del paso 1 valida que sale igual.
3. **Vivo.** La difusión al Panel pasa a salir del buzón. El evento y el canal no cambian.
4. **Corte.** La App deja de mandar a Laravel en el flujo normal y queda solo el respaldo de RN-19.
5. **Limpieza.** Se retira la doble escritura del paso 1.

En cualquier paso, quitar la dirección del servicio de la configuración de la App devuelve el sistema
al comportamiento de hoy.

## 14. Despliegue

Vive **solo en el VPS**, junto a la API (`deploy/vps/`). El paquete de Hostinger no lo ejecuta y no
recibe este cambio.

Hace falta: la memoria (Redis) instalada y con persistencia, el binario del servicio como unidad de
systemd con reinicio automático, nginx enrutando `/gps/` al servicio bajo el certificado actual, y el
worker `gps:consumir-buzon` supervisado igual que los demás.

Variables nuevas compartidas: `GPS_TOKEN_SECRET`, `GPS_SERVICE_TOKEN`, `GPS_SERVICE_URL`,
`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`.

Docker se usa **solo para desarrollo y pruebas de carga**. No es requisito de producción, y migrar
producción a contenedores es, si se decide, una fase aparte.

## 15. Estructura del servicio

```text
gps-service/
├── cmd/servidor/main.go     — arranque, configuración, apagado ordenado
├── internal/
│   ├── config/              — variables de entorno, valores por defecto
│   ├── permiso/             — verificación de firma, caducidad y cancelación
│   ├── memoria/             — conexión, reconexión y claves por tenant
│   ├── posicion/            — validación, descarte y guardado
│   ├── presencia/           — señal de vida y caducidad
│   ├── cercania/            — búsqueda geoespacial
│   ├── buzon/               — lectura y escritura de los dos streams
│   ├── tanda/               — acumulación y vaciado del recorrido
│   └── web/                 — rutas, middleware, freno, salud
├── go.mod
└── Dockerfile               — solo desarrollo y pruebas de carga
```

Nada de lógica en `main.go`. Cada paquete se prueba solo.

## 16. Incidente en producción (2026-09-11): el GPS real competía con el simulador durante un envío TEST

Al aceptar un envío con el switch del Panel en TEST desde un entorno remoto, el punto de recolección
del tramo de acercamiento (H1, spec tenant/026) aparecía a cientos de kilómetros de donde debía
—en California— y el tracking del Panel se quedaba clavado ahí el resto del envío. El viaje seguía
su curso normal en el teléfono hasta la entrega: solo el mapa del Panel se veía afectado.

**Causa.** `PedidoEstadoService::avisarServicioGps()` avisaba al microservicio GPS
(`BuzonGps::envioIniciado()`) al aceptar **cualquier** pedido, sin mirar el ambiente — a diferencia
de `abrirTramoSimulado()`, el método hermano que arranca el simulador dos líneas más abajo en el
mismo `transicionar()`, que sí comprobaba `$pedido->esTest()`. La RN-13 de esta spec ya decía "los
envíos en modo TEST se siguen manejando por Laravel... el servicio GPS no participa", pero nada en
el código lo hacía cumplir: el permiso GPS se emite por conductor al conectarse
(`PermisoGpsService::emitir()`), no por pedido, así que el teléfono seguía mandando su posición real
al servicio durante todo el envío TEST.

Con el aviso llegando igual, dos fuentes escribían `conductor_estado` a la vez: el simulador
(siempre coherente con las coordenadas del propio pedido) y el GPS real del teléfono. En el entorno
remoto donde se probó, el teléfono no tenía una lectura GPS confiable —geolocalización por red/IP al
probar fuera de sitio, el mismo caso que ya anticipaba el comentario de
`TrackingService::MAX_VELOCIDAD_KMH`— y esa segunda fuente escribió una coordenada absurdamente
lejana. RN-03 (velocidad implausible) la tomó como línea base y descartó en silencio cualquier
lectura real posterior por "salto imposible", dejando el tracking visual clavado ahí para siempre —
sin tocar la máquina de estados del pedido, que no depende de `conductor_estado` y por eso el envío
se entregó con normalidad.

Localmente nunca se vio: `gps:consumir-buzon` no está en el script `dev` de Composer, así que aunque
el servicio GPS esté arriba, nada consume su buzón y la carrera no tiene forma de manifestarse.

Corregido agregando el mismo `esTest()` que ya tenía `abrirTramoSimulado()` al inicio de
`avisarServicioGps()`: en TEST ya no se avisa `envioIniciado`/`envioTerminado` al microservicio, que
por lo tanto nunca acepta ni reenvía posición real de ese conductor mientras dure el envío simulado.
Cubierto por dos pruebas nuevas en `GpsTest.php` que verifican que ninguno de los dos avisos se
dispare para un pedido `ambiente = test`.

## 17. Incidente en producción (2026-09-11): un envío TEST llegaba a `ARRIBADO` al instante de aceptarse

Con el switch del Panel en TEST, al aceptar un envío no aparecía ningún recorrido hacia la recogida:
el estado saltaba directo a "Llegaste a tu destino" (`ARRIBADO`) sin dibujar el tramo de acercamiento.

**Causa.** `SimulacionEnvioService::posicionDelConductor()` arranca el tramo de acercamiento desde
`conductor_estado.ultima_latitud/longitud` (RN-08 de spec tenant/025); si esa columna está vacía,
arranca en el punto de recogida mismo, con lo que el tramo mide cero metros y se completa en la misma
petición que lo abre.

Esa columna estaba vacía porque el paso 4 original de §7.3 ("sin envío, se descarta la posición")
—heredado tal cual de RN-01 de SPEC-021, escrito antes de que existiera el modo TEST— descartaba
también la posición de un conductor en línea **sin** envío, sin guardar nada de ella. El equivalente
en Laravel (`Conductor\UbicacionController::actualizar()`) sí se había corregido para este caso
exacto al escribir spec tenant/025 (`TrackingService::registrarPosicionSinEnvio()`), pero esa
corrección nunca se trasladó al servicio Go cuando se construyó encima en `bdfe93a`: ese endpoint
quedó como respaldo que solo se usa si el servicio no responde, así que con el servicio arriba
—que es el caso normal en producción— ningún conductor tenía nunca una última posición conocida
hasta que aceptaba su primer envío, y en TEST no la tenía **nunca**, porque ahí el GPS real que
manda el teléfono durante el envío se descarta siempre (RN-11).

RN-06 de esta misma spec ya asumía lo contrario —que un conductor en línea sin envío es
geolocalizable en `/nearby` hasta que su posición caduca a los 3 min—, así que el defecto también
dejaba sin candidatos cualquier búsqueda de cercanía sobre un conductor que aún no había hecho su
primer envío.

Corregido moviendo el guardado de la posición (`almacen.Guardar`) antes de comprobar si hay envío en
curso, en `web.go`. Sin envío, el servicio ahora avisa a Laravel con un mensaje nuevo,
`POSICION_SIN_ENVIO` (§6.4), que solo actualiza `conductor_estados` — no dispara
`UbicacionActualizada` ni genera historia, respetando RN-01 en lo que sí importaba conservar. Cubierto
por una prueba nueva en `GpsTest.php`.
