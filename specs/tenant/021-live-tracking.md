# Spec — Live tracking

**Metadatos**

| Campo | Valor |
|---|---|
| ID | SPEC-021 |
| Módulo | Logística / Seguimiento |
| Autor | A. Rivas |
| Versión | 1.0 |
| Estado | Borrador |
| Sprint | S-09 |
| Depende de | SPEC-018 (Protocolo realtime), SPEC-020 (Oferta y aceptación) |
| Habilita a | — |

---

## 1. Objetivo

Transmitir la posición del repartidor mientras tiene un envío activo, para que el Panel la vea en el mapa en tiempo real. Hereda el protocolo de SPEC-018.

## 2. Alcance

**Incluye:** captura de coordenadas en la App, frecuencia de envío, difusión al Panel, historial mínimo del recorrido, comportamiento en segundo plano.

**No incluye:** cálculo de ETA, ruta óptima, geocercas de llegada automática, mapa para el cliente final.

## 3. Actores y permisos

| Actor | Permisos |
|---|---|
| `repartidor` | Emite su propia posición |
| `admin_cliente` | Ve en el mapa a los repartidores de su tenant con envío activo |
| `super_admin` | Solo lectura |

## 4. Modelo de datos

Se agrega a `couriers` (posición actual, se sobrescribe):

- `last_lat` — decimal(10,7), nullable
- `last_lng` — decimal(10,7), nullable
- `last_seen_at` — timestamp, nullable *(ya definido en SPEC-019)*
- `ultima_posicion_en` — timestamp, nullable. Cuándo se **capturó** la coordenada que está guardada, que no es lo mismo que `last_seen_at`/`ultima_actualizacion` —esa dice cuándo se supo algo del conductor, la mueva o no la mueva de sitio—. Es el denominador de RN-03d. Se escribe solo junto con la coordenada.
- `rechazos_consecutivos` — entero sin signo, default 0. Cuántas lecturas seguidas lleva rechazadas RN-03 contra esta misma posición. Vuelve a cero en cuanto una entra. Es lo que permite a RN-03b distinguir una lectura mala suelta de una base mala (implementado sobre `conductor_estado`).

Tabla `delivery_tracks`, solo para el recorrido de envíos activos:

- `id` — bigint autoincremental, PK
- `delivery_id` — FK a `deliveries`, indexado
- `lat` — decimal(10,7)
- `lng` — decimal(10,7)
- `recorded_at` — timestamp

Índice: `(delivery_id, recorded_at)`. Sin `tenant_id`: se llega a él por el envío, y esta tabla crece rápido.

**Retención:** los puntos se conservan 7 días después de entregar y luego se borran con un job diario. Lo que sobrevive es un resumen en `deliveries`: `distance_traveled_km` y `route_snapshot` (json con máximo 50 puntos simplificados) para reclamaciones.

## 5. Endpoints

| Método | Ruta | Descripción | Éxito |
|---|---|---|---|
| POST | `/api/v1/deliveries/{id}/locations` | Sube un lote de posiciones acumuladas | 204 |
| GET | `/api/v1/deliveries/{id}/track` | Panel: recorrido del envío | 200 |

El endpoint HTTP existe solo para el respaldo por lotes de RN-05. El flujo normal va por socket.

**Evento App → servidor**, canal `private-courier.{courier_id}`:

```json
{
  "event": "LOCATION_UPDATE",
  "event_id": "01JB2P8ZK4",
  "emitted_at": "2026-09-04T10:14:22Z",
  "payload": {
    "delivery_id": "01JB2K3F9A",
    "lat": 20.5248,
    "lng": -100.8132,
    "accuracy_m": 12,
    "speed_kmh": 34.5,
    "heading": 118
  }
}
```

**Evento servidor → Panel**, canal `private-tenant.{tenant_id}`:

```json
{
  "event": "COURIER_LOCATION_CHANGED",
  "event_id": "01JB2P8ZK5",
  "emitted_at": "2026-09-04T10:14:22Z",
  "payload": {
    "courier_id": "01JB2C8N",
    "delivery_id": "01JB2K3F9A",
    "lat": 20.5248,
    "lng": -100.8132,
    "heading": 118
  }
}
```

El evento que sale al Panel no lleva `accuracy_m` ni `speed_kmh`. El mapa no los usa y son el 30% del payload en el evento de mayor volumen del sistema.

## 6. Reglas de negocio

- **RN-01:** La App emite posición **solo** con un envío en `ASSIGNED` o `IN_TRANSIT`. Estar en línea sin envío no genera tracking.
- **RN-01b:** Un conductor en línea **sin** envío sí conserva su última posición conocida (`conductor_estado`), y solo esa: ni historia de recorrido ni difusión al Panel. Sin ella, quien nunca hizo un envío no tiene posición —y en TEST no la tendría nunca, porque ahí el GPS real se descarta siempre—, con lo que el tramo de acercamiento simulado de SPEC-025 medía cero metros. Si el conductor está fuera de línea, la lectura se descarta sin crear nada.
- **RN-02:** Frecuencia: cada 15 segundos, o antes si el repartidor se movió más de 50 metros desde el último envío. Detenido más de 2 minutos, baja a un envío por minuto.
- **RN-03:** Se descarta en la App, sin enviar, toda lectura con `accuracy_m` mayor a 100 o con velocidad implícita superior a 150 km/h respecto al punto anterior. El servidor repite el filtro de velocidad sobre `conductor_estado`, en un único punto compartido por las dos puertas de entrada —el POST directo y el worker del buzón del microservicio GPS (SPEC-028)—, porque el filtro no puede depender de por dónde entró el punto.
- **RN-03b:** No se rechazan más de **3 lecturas seguidas** contra la misma posición anterior. Al cuarto rechazo se adopta la lectura nueva como base. RN-03 compara siempre contra la posición anterior, así que una base equivocada se defiende sola: rechaza justo las lecturas buenas que la contradicen, y el conductor queda clavado ahí hasta que pase el tiempo que la velocidad implícita necesita para volverse plausible —horas, si la base cayó en otro país—. Pasó en producción (SPEC-028, §16). Varias lecturas que coinciden en contradecir a la base pesan más que la base sola.
- **RN-03d:** La velocidad implícita se mide entre las horas de **captura** de las dos coordenadas, nunca entre las de proceso. `ultima_actualizacion` no sirve como denominador: significa "cuándo supimos algo del conductor" y la refrescan también el latido, el ponerse en línea y cada `/conductor/sync`, ninguno de los cuales mueve la coordenada. Medir contra ella dejaba el denominador en segundos mientras el conductor llevaba minutos recorriendo distancia real, y esa distancia partida por casi nada siempre parece teletransportación. Golpeaba de forma sistemática en dos escenarios: en todo envío TEST —donde el GPS real se sustituye por un latido cada 15 s (SPEC-025, RN-11) mientras el simulador mueve al conductor— y cada vez que el worker del buzón vaciaba una cola atrasada, porque posiciones capturadas con minutos de diferencia se evaluaban como llegadas en el mismo segundo.
- **RN-03c:** Todo descarte por RN-03 se registra en el log con conductor, tenant, coordenada rechazada y cuántos rechazos seguidos lleva. Una coordenada que se descarta en silencio es indistinguible de un GPS apagado cuando hay que diagnosticar, que es lo que costó días de rastreo en el incidente de SPEC-028, §16.
- **RN-04:** El servidor guarda en `delivery_tracks` y actualiza `couriers.last_lat/lng/last_seen_at` en la misma operación. Ese `last_seen_at` es el que alimenta el timeout de SPEC-019.
- **RN-05:** Sin conexión, la App acumula hasta 200 puntos en SQLite local y los sube por `POST /api/v1/deliveries/{id}/locations` al reconectar. Pasados los 200, descarta los más antiguos.
- **RN-06:** El tracking corre en segundo plano con foreground service de Android y notificación persistente. Se detiene al entregar, cancelar o salir de línea.
- **RN-07:** Los puntos subidos por lote se guardan en `delivery_tracks` pero **no** se difunden al Panel: son historia, no posición actual.
- **RN-08:** Al entregar el envío se calcula `distance_traveled_km` y se guarda `route_snapshot`.

## 7. Errores

| Código | HTTP | Cuándo |
|---|---|---|
| `DELIVERY_NOT_ACTIVE` | 409 | El envío ya se entregó o canceló. `POST /conductor/ubicacion` no lo devuelve: sin envío en curso responde 204 aplicando RN-01b |
| `DELIVERY_NOT_ASSIGNED` | 403 | El envío no es de ese repartidor |
| `BATCH_TOO_LARGE` | 422 | Lote con más de 200 puntos |

Un `LOCATION_UPDATE` inválido por socket se descarta en silencio. No hay a quién devolverle el error y reintentar sería peor que perder un punto.

## 8. Criterios de aceptación

- [ ] Un repartidor en línea sin envío no genera registros en `delivery_tracks`.
- [ ] Con un envío `IN_TRANSIT`, el marcador del Panel se mueve sin recargar la página.
- [ ] Una lectura con `accuracy_m: 250` no sale de la App.
- [ ] Con la posición anterior envenenada en otro país, cuatro lecturas reales seguidas bastan para que el marcador vuelva a la posición correcta: no se queda clavado el resto del envío (RN-03b).
- [ ] Dos lecturas malas sueltas con una buena en medio no suman entre sí: la base correcta sobrevive (RN-03b).
- [ ] Cada descarte por RN-03 deja una línea en el log con conductor, tenant y coordenada (RN-03c).
- [ ] En un envío TEST completo, con el teléfono mandando latidos cada 15 s, ninguna posición del simulador se descarta por RN-03 (RN-03d).
- [ ] Un conductor que recorre 2 km en 5 minutos no se descarta aunque acabe de mandar un latido hace un segundo (RN-03d).
- [ ] Al vaciar un buzón con varios minutos de posiciones acumuladas, no se descarta ninguna por llegar todas en la misma ráfaga (RN-03d).
- [ ] Detenido 3 minutos, la App emite una posición por minuto, no cuatro.
- [ ] Con avión activado 2 minutos y luego apagado, los puntos acumulados aparecen en `GET /track` y el marcador del Panel no dio saltos hacia atrás.
- [ ] Con la app en segundo plano y la pantalla apagada, el tracking sigue emitiendo.
- [ ] Al marcar entregado, la App deja de emitir y la notificación persistente desaparece.
- [ ] Un repartidor recibe 403 al subir posiciones de un envío que no es suyo.
- [ ] Un envío entregado hace 8 días ya no tiene filas en `delivery_tracks` pero sí `route_snapshot`.

