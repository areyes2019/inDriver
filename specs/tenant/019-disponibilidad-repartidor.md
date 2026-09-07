# Spec: Disponibilidad del repartidor (AdminCliente, Despachador y Conductor)

Depende de: SPEC tenant/013, SPEC tenant/018
Habilita: SPEC tenant/020

## Historia de usuario

Como repartidor quiero poder ponerme en línea o fuera de línea en cualquier momento desde la app
(Vue + Capacitor), y como AdminCliente o Despachador quiero saber en todo momento y de forma
instantánea a quién puedo ofrecerle un envío, viendo de manera 100% visual quién está disponible.

## Objetivo / Alcance

**Incluye:** encender y apagar el modo en línea, escribirlo de forma consistente en las dos
representaciones que ya existen, reflejarlo en el Panel al instante (menú lateral de conductores
activos, tabla de conductores y mapa) con un aviso tipo toast, explicarle al repartidor por qué se le
rechazó el cambio, y apagar solo al que lleva demasiado tiempo sin dar señales.

**No incluye:** ofrecer envíos (spec `020`), horarios, turnos programados, geocercas ni aceptación de
tareas.

## Actores

- **Conductor** — es el único que decide su disponibilidad.
- **AdminCliente** — la ve, no la cambia.
- **Despachador** — la ve, no la cambia.

## Modelo de datos

No hay tabla nueva ni columna nueva: se usan las dos representaciones que la spec `013` ya dejó
creadas, y **esta spec las declara equivalentes** (RN-06).

**`conductor_estado`** (una fila por conductor, se crea al primer encendido):

- `id_conductor` — FK a `conductores`
- `estado` — enum; de esta spec solo se usan `ONLINE` y `OFFLINE`
- `ultima_conexion` / `ultima_desconexion` — timestamps
- `ultima_actualizacion` — timestamp; lo refresca cada `POST /conductor/ubicacion` (spec `021`) y cada
  `GET /conductor/sync` (spec `018`). Es lo que distingue "en línea de verdad" de "se le acabó la
  batería".

**`conductores.disponibilidad`** — enum `DISPONIBLE | OCUPADO | DESCANSO | FUERA_DE_SERVICIO`. De esta
spec solo se escriben dos valores: `DISPONIBLE` (equivale a `ONLINE`) y `FUERA_DE_SERVICIO` (equivale
a `OFFLINE`). Es la columna que leen el Panel y sus listados.

No se agrega un tercer estado para "ocupado": que un conductor tenga un envío en curso se sabe
consultando `pedidos`, no duplicando el dato aquí (RN-01).

## Endpoints

- `POST /t/{slug}/conductor/estado` (`auth:conductor-token`) — enciende o apaga. Cuerpo:
  `{ "estado": "ONLINE" | "OFFLINE" }`. Ningún otro valor del enum es aceptable desde la app.
- `GET /t/{slug}/conductores/activos` (`auth:usuario`) — lista para el menú lateral del Panel.
- `GET /t/{slug}/conductores` (`auth:usuario`) — tabla de conductores del Panel, incluye
  `disponibilidad`.

El AdminCliente **no** cambia la disponibilidad: `PUT /t/{slug}/conductores/{conductor}` no acepta ese
campo (spec `003`).

## Proceso

1. El repartidor toca el interruptor en la app.
2. El backend valida saldo (al encender) o entrega en curso (al apagar).
3. Se escriben `conductor_estado.estado` y `conductores.disponibilidad` en una sola transacción.
4. Confirmada la escritura, se emite `ConductorDisponibilidadCambiada`
   (`conductor.disponibilidad-cambiada`).
5. El Panel lo refleja al instante: el nombre aparece o desaparece del menú lateral derecho, la fila de
   la tabla de conductores cambia su columna de disponibilidad, y se muestra un toast cuando alguien se
   conecta.

## Modelos de respuesta

**Encendido/apagado correcto** — `200`:

```json
{ "estado": "ONLINE" }
```

**Saldo insuficiente** — `422`:

```json
{
  "message": "...",
  "errors": { "estado": ["INSUFFICIENT_BALANCE"] }
}
```

**Envío en curso** — `422`:

```json
{
  "message": "...",
  "errors": { "estado": ["HAS_ACTIVE_DELIVERY"] }
}
```

**Evento al Panel** — `conductor.disponibilidad-cambiada`:

```json
{
  "id_conductor": 12,
  "disponibilidad": "DISPONIBLE",
  "event_id": "uuid"
}
```

## Decisión técnica

### Un solo servicio decide, y escribe las dos representaciones a la vez

`App\Services\DisponibilidadService` es el único lugar que enciende o apaga a un conductor: lo usan el
endpoint de la app, el cierre de sesión y la tarea programada de inactividad. Las dos escrituras van
dentro de una misma transacción.

Media escritura —una tabla sí y la otra no— es exactamente la desincronización que el sistema muestra
como "la app dice en línea y el Panel dice fuera de servicio". No es un detalle de implementación: es
la razón por la que ambas representaciones pueden coexistir sin mentir (RN-06).

### El aviso al Panel va después del commit y no puede tumbar el cambio

Se emite fuera de la transacción, envuelto en un `try/catch` que registra `Log::warning` si falla. Si
el broker está apagado o mal configurado, el conductor igual queda conectado y recibe `200`; lo único
que se pierde es que el Panel se entere sin recargar. Es la RN-08 del spec `018` aplicada aquí.

### El Panel parchea la fila, no recarga la lista

En la tabla de conductores, al llegar el evento se cambia el valor de esa fila en memoria en vez de
volver a pedir la página de resultados. Recargar haría perder el filtro de búsqueda y la página en la
que está el AdminCliente, por un cambio que afecta a una sola celda.

### "Tener saldo" depende de la modalidad del tenant

La comprobación de RN-02 no es una sola:

- **Prepago** — se cuentan los viajes prepagados disponibles del conductor (spec `013`).
- **Comisión** — se mira su saldo en dinero (SPEC-023).

La modalidad se lee de la configuración del tenant; el conductor no elige ni ve esa diferencia, solo
recibe el mismo `INSUFFICIENT_BALANCE`.

### Cerrar sesión apaga siempre, incluso con entrega en curso

RN-03 protege al repartidor de quedarse sin trabajo a medias, pero al cerrar sesión no hay pantalla a
la que devolverle un error: el apagado se fuerza (RN-05). Es la única excepción, y es deliberada.

### La app explica el rechazo

Los códigos `INSUFFICIENT_BALANCE` y `HAS_ACTIVE_DELIVERY` dejan de ser solo contrato de API: la app
los traduce a una frase que el repartidor entiende y la muestra como aviso flotante. Un interruptor que
no se mueve y no dice nada es indistinguible de una app rota.

## Reglas de negocio

- **RN-01**: Solo hay dos estados: en línea o fuera de línea. No existe "ocupado" — que un conductor
  tenga un envío activo se sabe consultando `pedidos`, no duplicando el dato aquí.
- **RN-02**: Para encenderse, el conductor necesita saldo disponible según la modalidad del tenant. Si
  no, se rechaza con `INSUFFICIENT_BALANCE` y el estado no cambia.
- **RN-03**: El conductor no puede apagarse con un envío en curso: debe entregarlo o pedir la
  cancelación al Panel. Se rechaza con `HAS_ACTIVE_DELIVERY`.
- **RN-04**: Si pasan más de 10 minutos sin señales (`ultima_actualizacion`), una tarea programada lo
  apaga y emite el evento al Panel.
- **RN-05**: Al cerrar sesión el conductor queda fuera de línea siempre, aunque tenga un envío en
  curso — única excepción a RN-03.
- **RN-06**: `conductor_estado.estado` y `conductores.disponibilidad` se escriben en una sola
  transacción. Nunca puede quedar una sin la otra.
- **RN-07**: El rechazo se le muestra al conductor en pantalla, con su motivo. No basta con devolverlo
  en la respuesta HTTP.
- **RN-08**: El Panel refleja el cambio en vivo en todas las vistas donde aparece la disponibilidad
  (menú lateral, tabla de conductores, mapa), sin que el usuario pierda su búsqueda ni su página.

## Backend (Laravel)

- **`App\Services\DisponibilidadService`** — `conectar()` / `desconectar($conductor, forzar: bool)`.
  Valida RN-02/RN-03, escribe en transacción (RN-06) y emite el evento post-commit protegido.
- **`Conductor\EstadoController@actualizar`** — valida `estado` contra `ONLINE|OFFLINE` y delega en el
  servicio.
- **`Conductor\AuthController@logout`** — llama a `desconectar(forzar: true)` (RN-05).
- **Evento** `ConductorDisponibilidadCambiada` (`conductor.disponibilidad-cambiada`), con `event_id` y
  `ShouldBroadcastNow` (spec `018`, RN-08).
- **Comando** `conductor:apagar-inactivos`, agendado cada minuto en `routes/console.php`: recorre los
  tenants activos y apaga a quien lleva más de 10 minutos sin `ultima_actualizacion` (RN-04). **Si el
  cron del servidor no ejecuta `schedule:run`, esta regla simplemente no ocurre** y un conductor sin
  batería queda marcado como disponible indefinidamente.

## Frontend (`panda_express`)

- Interruptor en la barra superior; cada sesión arranca fuera de línea (spec `013`): el estado no se
  hereda del login.
- El error del cambio de estado se guarda y se muestra como aviso flotante con el motivo traducido
  (RN-07). Nunca se descarta en silencio.
- Mientras la petición está en curso el interruptor queda deshabilitado, para no encadenar dos cambios.

## Frontend (`frontend/`, Panel)

- **Menú lateral de conductores activos** — se suscribe al evento, recarga su lista y muestra un toast
  cuando alguien se conecta.
- **Tabla de conductores** — se suscribe al mismo evento y actualiza la celda de disponibilidad de esa
  fila en memoria, sin recargar la página de resultados (RN-08).
- **Mapa de conductores** — se redibuja al recibir el evento.

## Requisito de despliegue

Igual que en el spec `018`: si `BROADCAST_CONNECTION` no apunta al broker real o Reverb no está
corriendo, el conductor se pone en línea correctamente y la operación responde `200`, pero el Panel
solo lo verá al recargar la página. **Eso no es un fallo de la app ni del servicio** — es configuración
de entorno, y así debe diagnosticarse.

## Fuera de alcance

- Ofrecer envíos a los conductores disponibles (spec `020`).
- Horarios, turnos programados y geocercas.
- Que el AdminCliente pueda encender o apagar a un conductor: la disponibilidad es decisión del
  conductor (spec `003`).
- Los estados `OCUPADO` y `DESCANSO` del enum: quedan sin uso en esta spec.

## Criterios de aceptación

- Con saldo cero, la respuesta es `INSUFFICIENT_BALANCE`, el estado sigue en fuera de línea y **la app
  muestra el motivo en pantalla**.
- Con una entrega en curso, apagarse responde `HAS_ACTIVE_DELIVERY` y la app explica que debe terminar
  o cancelar la entrega.
- Al encender, el Panel muestra al repartidor como disponible sin recargar la página, tanto en el menú
  lateral como en la tabla de conductores.
- Al actualizarse la tabla por un evento, el filtro de búsqueda y la página activa se conservan.
- Después de encender o apagar, `conductor_estado.estado` y `conductores.disponibilidad` siempre
  coinciden; no existe ninguna ruta que escriba una sin la otra.
- Con el broker de tiempo real apagado, encender responde `200`, el cambio queda guardado y el fallo
  del aviso aparece como `warning` en la bitácora.
- Un repartidor sin señales por 10 minutos aparece como no disponible en el Panel.
- Al cerrar sesión y volver a entrar, el repartidor aparece fuera de línea.

## Supuestos asumidos (registro completo)

1. `courier`/`delivery` de la redacción original se traducen a `Conductor`/`Pedido`; `live_mode`,
   `live_since` y `last_seen_at` **no** se implementan como columnas nuevas de `couriers`: se mapean a
   `conductor_estado.estado`, `ultima_conexion` y `ultima_actualizacion`, que ya existían desde la spec
   `013`.
2. `PUT /api/v1/live-mode` se traduce a `POST /t/{slug}/conductor/estado` con `ONLINE|OFFLINE`, anidado
   en el grupo de rutas real del conductor.
3. La respuesta de encendido no devuelve el saldo: la app ya lo consulta por su cuenta
   (`GET /conductor/saldo-viajes`) y lo refresca por sondeo y por evento (spec `018`).
4. Se conservan las dos representaciones de "en línea" en vez de eliminar una, porque ambas ya tenían
   consumidores desde la spec `013`; el precio de conservarlas es RN-06.
5. El apagado por inactividad usa `desconectar(forzar: true)`: si alguien perdió la señal con una
   entrega encima, dejarlo marcado como en línea sería peor que apagarlo.
6. `INSUFFICIENT_BALANCE` es el mismo código en las dos modalidades; la app no distingue entre "te
   faltan viajes" y "te falta dinero".
7. El Panel parchea la fila de la tabla en memoria en vez de recargar, asumiendo que el resto de la
   fila no cambió con el evento — el evento solo transporta disponibilidad.
