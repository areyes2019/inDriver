# SPEC-022 — Cambios sobre envío activo

**Metadatos**

| Campo | Valor |
|---|---|
| ID | SPEC-022 |
| Módulo | Logística / Incidencias |
| Autor | A. Rivas |
| Versión | 1.0 |
| Estado | Borrador |
| Sprint | S-10 |
| Depende de | SPEC-018 (Protocolo realtime), SPEC-020 (Oferta y aceptación) |
| Habilita a | — |

---

## 1. Objetivo
Notificar al repartidor cuando el cliente o el administrador cancelan un envío ya tomado, cambian su hora de entrega o cambian el punto de entrega, y dejar constancia de si el aviso llegó a tiempo.

## 2. Alcance

**Incluye:** cancelación de un envío en curso, cambio de horario programado, confirmación de recepción por parte de la App, compensación cuando el repartidor ya iba en camino.

**No incluye:** cancelación de envíos que nadie ha tomado (eso solo cambia estado, sin aviso), reembolso al cliente final, cálculo del monto de compensación (SPEC-023).

## 3. Actores y permisos

| Actor | Permisos |
|---|---|
| `admin_cliente` | Cancela y reprograma envíos de su tenant |
| `repartidor` | Recibe el aviso y lo confirma; no puede cancelar por su cuenta |
| `super_admin` | Solo lectura |

## 4. Modelo de datos

Se agrega a `deliveries`:

- cancelled_at — timestamp, nullable
- cancelled_by — enum: `CUSTOMER`, `ADMIN`, nullable
- cancellation_reason — string(120), nullable
- scheduled_at — timestamp, nullable
- courier_notified_at — timestamp, nullable
- dropoff_lat / dropoff_lng — decimal(10,7)
- dropoff_address — string(255)
- relocation_count — unsigned tinyint, default 0
- extra_charges — decimal(10,2), default 0

`courier_notified_at` se llena cuando la App confirma. Es el único `ack` del sistema, y existe por una razón concreta: si el repartidor recogió el paquete después de esa hora, es problema suyo; si lo recogió antes, la compensación es procedente.

Tabla `delivery_changes` (bitácora de incidencias):

- `id` — ulid, PK
- `delivery_id` — FK, indexado
- `type` — enum: `CANCELLED`, `RESCHEDULED`
- `previous_value` — json, nullable
- `new_value` — json, nullable
- `actor_type` — enum: `CUSTOMER`, `ADMIN`
- `actor_id` — ulid, nullable
- `created_at` — timestamp

## 5. Endpoints

| Método | Ruta | Descripción | Éxito |
|---|---|---|---|
| POST | `/api/v1/deliveries/{id}/cancel` | Panel cancela el envío | 200 |
| PATCH | `/api/v1/deliveries/{id}/schedule` | Panel cambia la hora de entrega | 200 |
| POST | `/api/v1/deliveries/{id}/notified` | App confirma que vio el cambio | 204 |
POST	/api/v1/deliveries/{id}/relocation/quote	Cotiza el cambio antes de aplicarlo	200
PATCH	/api/v1/deliveries/{id}/dropoff	Aplica el cambio de destino	200
**`DELIVERY_CANCELLED`** hacia `private-courier.{courier_id}`:

```json
{
  "event": "DELIVERY_CANCELLED",
  "event_id": "01JB2R4WM7",
  "emitted_at": "2026-09-04T11:05:00Z",
  "payload": {
    "delivery_id": "01JB2K3F9A",
    "cancelled_by": "CUSTOMER",
    "reason": "Ya no lo necesito",
    "compensation_eligible": true,
    "instruction": "RETURN_TO_PICKUP"
  }
}
```

`instruction` toma tres valores: `STOP` (no había recogido nada), `RETURN_TO_PICKUP` (ya tiene el paquete) y `CONTACT_SUPPORT`.

**`DELIVERY_SCHEDULE_UPDATED`**, mismo canal:

```json
{
  "event": "DELIVERY_SCHEDULE_UPDATED",
  "event_id": "01JB2R5XN1",
  "emitted_at": "2026-09-04T11:20:00Z",
  "payload": {
    "delivery_id": "01JB2K3F9A",
    "previous_scheduled_at": "2026-09-04T16:00:00Z",
    "scheduled_at": "2026-09-04T18:30:00Z",
    "requires_confirmation": true
  }
}
```
// POST /relocation/quote
{ "lat": 20.5402, "lng": -100.7988, "address": "Calle Roble 88, Col. Las Fuentes" }

// response 200
{
  "data": {
    "quote_id": "01JB2T3KM9",
    "extra_distance_km": 4.2,
    "extra_charge": 32.00,
    "new_eta_minutes": 18,
    "expires_at": "2026-09-04T11:35:00Z"
  }
}

// PATCH /dropoff
{ "quote_id": "01JB2T3KM9" }

// response 200
{
  "data": {
    "delivery_id": "01JB2K3F9A",
    "dropoff_address": "Calle Roble 88, Col. Las Fuentes",
    "extra_charges": 32.00,
    "relocation_count": 1
  }
}
Evento DELIVERY_ADDRESS_UPDATED hacia private-courier.{courier_id}:
{
  "event": "DELIVERY_ADDRESS_UPDATED",
  "event_id": "01JB2T5PW2",
  "emitted_at": "2026-09-04T11:31:00Z",
  "payload": {
    "delivery_id": "01JB2K3F9A",
    "previous_dropoff": { "label": "Col. Alameda", "lat": 20.5310, "lng": -100.8091 },
    "dropoff": {
      "label": "Col. Las Fuentes",
      "address": "Calle Roble 88",
      "lat": 20.5402,
      "lng": -100.7988
    },
    "extra_distance_km": 4.2,
    "extra_payout": 22.00,
    "requires_confirmation": true
  }
}

**`POST /notified`** — el ack:

```json
// request
{ "event_id": "01JB2R4WM7", "seen_at": "2026-09-04T11:05:04Z" }
// response 204
```

## 6. Reglas de negocio

- **RN-01:** Solo se avisa por evento si el envío tiene `courier_id`. Cancelar un envío sin repartidor solo cambia el estado.
- **RN-02:** Cancelación y reprogramación se emiten por socket **y** por push simultáneamente (SPEC-018, RN-04). Es el caso que justifica esa regla.
- **RN-03:** La App confirma con `POST /notified`. Si a los 60 segundos no hay confirmación, el Panel muestra el envío en rojo con la etiqueta "repartidor no confirmado" y habilita llamada telefónica. No hay reintentos automáticos; a partir de ahí es intervención humana.
- **RN-04:** Si el envío estaba en `IN_TRANSIT` al cancelarse, `compensation_eligible` es verdadero y se dispara el flujo de SPEC-023.
- **RN-05:** Un envío cancelado no acepta más `LOCATION_UPDATE`. El tracking se detiene con el evento (SPEC-021, RN-06).
- **RN-06:** Reprogramar a una hora ya pasada se rechaza. La nueva hora debe estar al menos 15 minutos en el futuro.
- **RN-07:** Un envío `IN_TRANSIT` no se puede reprogramar: el repartidor ya lleva el paquete. Se cancela o se entrega.
- **RN-08:** Cancelar un envío ya entregado devuelve 409. La devolución es otro flujo.
- **RN-09:** Al cancelar, el repartidor queda disponible de inmediato y puede recibir ofertas nuevas (SPEC-020, RN-06).
RN-10: La cotización se calcula desde la posición actual del repartidor (SPEC-021), no desde el origen. Si ya pasó el punto viejo, el desvío real es mayor.
RN-11: La cotización vence a los 5 minutos. Después de eso el repartidor se movió y el precio ya no aplica: hay que cotizar de nuevo.
RN-12: El cambio de destino sí se permite con el envío en IN_TRANSIT, a diferencia de la reprogramación (RN-07). Es justo el caso de uso: el paquete ya va en camino.
RN-13: Máximo 2 reubicaciones por envío. A la tercera se rechaza y se sugiere cancelar y crear un envío nuevo.
RN-14: El cargo extra se cobra al cliente y el extra_payout se abona al repartidor como movimiento ADJUSTMENT al entregar (SPEC-023).
RN-15: Si el nuevo destino queda fuera de las zonas de cobertura del tenant, se rechaza la cotización.
RN-16: El repartidor no puede rechazar la reubicación dentro del rango cotizado; el sistema ya la validó. Solo confirma que la vio, con el mismo POST /notified.
## 7. Errores

| Código | HTTP | Cuándo |
|---|---|---|
| `DELIVERY_ALREADY_COMPLETED` | 409 | Cancelar o reprogramar algo ya entregado |
| `CANNOT_RESCHEDULE_IN_TRANSIT` | 409 | Reprogramar con el paquete en camino |
| `SCHEDULE_IN_PAST` | 422 | Nueva hora a menos de 15 minutos |
| `DELIVERY_NOT_FOUND` | 404 | Envío de otro tenant |
QUOTE_EXPIRED	409	Se aplica una cotización de más de 5 minutos
RELOCATION_LIMIT_REACHED	409	Tercer intento de reubicación
DROPOFF_OUT_OF_COVERAGE	422	Nuevo destino fuera de zona
QUOTE_NOT_FOUND	404	quote_id inexistente o de otro envío
## 8. Criterios de aceptación

- [ ] Cancelar un envío `IN_TRANSIT` hace que la App muestre pantalla completa de cancelación, no una notificación pequeña.
- [ ] El mismo evento llega por socket y por push; la App lo procesa una sola vez.
- [ ] Con la app cerrada, la cancelación llega como push y al abrirla la pantalla ya está en estado cancelado.
- [ ] Sin confirmación a los 61 segundos, el Panel marca el envío en rojo con botón de llamada.
- [ ] Un envío cancelado deja de generar registros en `delivery_tracks`.
- [ ] Reprogramar a 10 minutos en el futuro devuelve 422.
- [ ] Reprogramar un envío `IN_TRANSIT` devuelve 409.
- [ ] Cancelar un envío `IN_TRANSIT` marca `compensation_eligible: true` y crea el registro en `delivery_changes`.
- [ ] Tras la cancelación, el repartidor recibe la siguiente oferta disponible.
Cotizar con el repartidor a 2 km del destino viejo da un extra_distance_km distinto que cotizar con él ya en el punto.
Aplicar una cotización de hace 6 minutos devuelve 409 con QUOTE_EXPIRED.
Con el envío en IN_TRANSIT, cambiar destino devuelve 200 y la App actualiza elmapa al punto nuevo.
La App muestra el destino anterior tachado junto al nuevo, no solo el nuevo.
Un tercer cambio de destino devuelve 409 con RELOCATION_LIMIT_REACHED.
Un destino fuera de zona de cobertura devuelve 422 sin crear cotización.
Al entregar, el repartidor tiene un movimiento ADJUSTMENT por el extra_payout.
Sin confirmación del repartidor a los 61 segundos, el Panel marca el envío en rojo(RN-03)


# SPEC-023 — Saldo del repartidor

**Metadatos**

| Campo | Valor |
|---|---|
| ID | SPEC-023 |
| Módulo | Facturación / Repartidores |
| Autor | A. Rivas |
| Versión | 1.0 |
| Estado | Borrador |
| Sprint | S-10 |
| Depende de | SPEC-018 (Protocolo realtime), SPEC-019 (Disponibilidad) |
| Habilita a | — |

---

## 1. Objetivo

Llevar el saldo de cada repartidor y avisarle en tiempo real cuando cambia, ya sea por una recarga, por la comisión de un envío o por una compensación. Hereda el protocolo de SPEC-018.

## 2. Alcance

**Incluye:** movimientos de saldo, evento de notificación, consulta de saldo e historial.

**No incluye:** pasarela de pago para recargas, retiros a cuenta bancaria, facturación fiscal, definición del porcentaje de comisión.

## 3. Actores y permisos

| Actor | Permisos |
|---|---|
| `repartidor` | Consulta su saldo e historial |
| `admin_cliente` | Acredita y ajusta saldo de sus repartidores |
| `super_admin` | Lectura de todos; sin poder de ajuste |

## 4. Modelo de datos

`couriers.balance` — decimal(10,2), default 0. Es el saldo vigente, calculado y almacenado.

`balance_movements` — libro de movimientos, solo inserción:

- `id` — ulid, PK
- `courier_id` — FK, indexado
- `tenant_id` — FK, indexado
- `type` — enum: `CREDIT`, `COMMISSION`, `COMPENSATION`, `ADJUSTMENT`
- `amount` — decimal(10,2), positivo en abonos, negativo en cargos
- `balance_after` — decimal(10,2)
- `delivery_id` — FK, nullable
- `reference` — string(120), nullable
- `created_by` — ulid, nullable
- `created_at` — timestamp

Índices: `(courier_id, created_at)`, `(delivery_id)`.

Nunca se edita ni se borra un movimiento. Un error se corrige con un `ADJUSTMENT` en sentido contrario, y así el historial siempre cuadra con el saldo.

## 5. Endpoints

| Método | Ruta | Descripción | Éxito |
|---|---|---|---|
| POST | `/api/v1/couriers/{id}/balance` | Panel acredita o ajusta saldo | 201 |
| GET | `/api/v1/me/balance` | Saldo actual del repartidor | 200 |
| GET | `/api/v1/me/movements` | Historial paginado | 200 |

```json
// POST /api/v1/couriers/01JB2C8N/balance
{ "type": "CREDIT", "amount": 200.00, "reference": "Depósito OXXO 4471" }

// response 201
{
  "data": {
    "movement_id": "01JB2S7FQ3",
    "type": "CREDIT",
    "amount": 200.00,
    "balance_after": 540.50,
    "created_at": "2026-09-04T12:00:00Z"
  }
}
```

**`BALANCE_CREDITED`** hacia `private-courier.{courier_id}`:

```json
{
  "event": "BALANCE_CREDITED",
  "event_id": "01JB2S8GT5",
  "emitted_at": "2026-09-04T12:00:00Z",
  "payload": {
    "movement_id": "01JB2S7FQ3",
    "type": "CREDIT",
    "amount": 200.00,
    "balance_after": 540.50,
    "reference": "Depósito OXXO 4471"
  }
}
```

El mismo evento sirve para los cuatro tipos de movimiento. El nombre quedó de la propuesta original; si prefieres, `BALANCE_CHANGED` describe mejor lo que hace, y cambiarlo ahora cuesta nada.

## 6. Reglas de negocio

- **RN-01:** Todo cambio de saldo inserta un movimiento y actualiza `couriers.balance` en la misma transacción, con `lockForUpdate` sobre el repartidor.
- **RN-02:** `balance_after` se guarda en cada movimiento. Permite auditar sin recalcular toda la serie.
- **RN-03:** El saldo puede quedar negativo por comisiones, pero con saldo en cero o menos el repartidor no puede ponerse en línea (SPEC-019, RN-02).
- **RN-04:** Un `COMMISSION` se registra al **entregar**, no al aceptar. Un envío cancelado no cobra comisión.
- **RN-05:** Un `COMPENSATION` se registra cuando SPEC-022 marca `compensation_eligible: true`. El monto lo define el `admin_cliente`; el sistema solo abre el pendiente.
- **RN-06:** Cruzar de cero a positivo, o de positivo a cero o negativo, emite además `COURIER_AVAILABILITY_CHANGED` si eso cambió su elegibilidad para estar en línea.
- **RN-07:** Un movimiento con `amount` cero se rechaza.
- **RN-08:** Solo `super_admin` ve movimientos de todos los tenants; un `admin_cliente` que consulte un repartidor ajeno recibe 404.

## 7. Errores

| Código | HTTP | Cuándo |
|---|---|---|
| `INVALID_AMOUNT` | 422 | Monto en cero, o negativo en un `CREDIT` |
| `COURIER_NOT_FOUND` | 404 | Repartidor de otro tenant |
| `FORBIDDEN_MOVEMENT_TYPE` | 403 | Un `admin_cliente` intenta registrar `COMMISSION` a mano |

## 8. Criterios de aceptación

- [ ] Acreditar 200 con saldo 340.50 deja `balance` en 540.50 y un movimiento con ese mismo `balance_after`.
- [ ] La App muestra el saldo nuevo sin recargar tras recibir `BALANCE_CREDITED`.
- [ ] Con la app cerrada, la acreditación llega como push.
- [ ] Dos acreditaciones simultáneas de 100 sobre saldo 0 dejan el saldo en 200, nunca en 100.
- [ ] Un envío cancelado antes de entregar no genera movimiento `COMMISSION`.
- [ ] Un repartidor cuyo saldo baja a 0 estando en línea es marcado fuera de línea y recibe el aviso.
- [ ] Un movimiento de 0 devuelve 422.
- [ ] Un `admin_cliente` del tenant A recibe 404 al acreditar a un repartidor del tenant B.
- [ ] La suma de `amount` de todos los movimientos de un repartidor coincide con su `balance`.
