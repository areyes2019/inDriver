# Spec - Oferta y aceptacion de envios

Depende de	SPEC-018 (Protocolo realtime), SPEC-019 (Disponibilidad)
Habilita a	SPEC-021 (Tracking), SPEC-022 (Cambios sobre envío activo)

# Objetivo
Ofrecer un envío nuevo a los repartidores en línea y asignarlo al primero que lo acepte, garantizando que dos repartidores nunca se queden con el mismo envío. Hereda el protocolo de SPEC-018.

# Alcance

Incluye: emisión de la oferta, ventana de aceptación, resolución de la carrera entre repartidores, rechazo explícito, expiración.

No incluye: creación del envío por parte del cliente, asignación manual desde el Panel, cálculo de tarifa, algoritmo de ordenamiento por cercanía.

# Actores y permisos
repartidor	    Recibe ofertas si está en línea y sin envío activo; acepta o rechaza
admin_cliente	Ve el estado de la oferta en tiempo real
despachador     Ve el estado de la oferta en timepo real

# Modelo de datos

Se agrega a deliveries:

courier_id — FK a couriers, nullable, indexado
offered_at — timestamp, nullable
accepted_at — timestamp, nullable

Estados relevantes de deliveries.status: PENDING → OFFERED → ASSIGNED. Si nadie acepta, vuelve a PENDING.

Tabla nueva delivery_offers, para saber a quién ya se le ofreció y no repetírselo:

id — ulid, PK
delivery_id — FK a deliveries, indexado
courier_id — FK a couriers, indexado
outcome — enum: PENDING, ACCEPTED, REJECTED, EXPIRED, LOST
expires_at — timestamp
created_at — timestamp

Único: (delivery_id, courier_id). Índice: (outcome, expires_at) para el job de expiración.

LOST es para los que no alcanzaron: recibieron la oferta pero otro aceptó primero. Se distingue de REJECTED porque no es culpa del repartidor y no debería contar en su historial.

# Endpoints
POST	/api/v1/{id}/accept	El repartidor toma el envío	200
POST	/api/v1/{id}/reject	El repartidor lo rechaza	204
GET	    /api/v1/me/offers	Ofertas vigentes para el repartidor	200

Evento hacia private-courier.{courier_id} — la oferta:
{
  "event": "NEW_DELIVERY_AVAILABLE",
  "event_id": "01JB2N5TY9",
  "emitted_at": "2026-09-04T10:02:00Z",
  "payload": {
    "delivery_id": "01JB2K3F9A",
    "expires_at": "2026-09-04T10:02:45Z",
    "pickup": { "label": "Farmacia San Juan", "lat": 20.5231, "lng": -100.8154 },
    "dropoff": { "label": "Col. Alameda", "lat": 20.5310, "lng": -100.8091 },
    "distance_km": 3.4,
    "payout": 48.00
  }
}

Evento a los que perdieron la tarea 

{
  "event": "DELIVERY_OFFER_CLOSED",
  "event_id": "01JB2N6BQ2",
  "emitted_at": "2026-09-04T10:02:12Z",
  "payload": { "delivery_id": "01JB2K3F9A", "outcome": "LOST" }
}

Respuesta de acepatcion exitosa 
// POST /api/v1/01JB2K3F9A/accept → 200
{
  "data": {
    "delivery_id": "01JB2K3F9A",
    "status": "ASSIGNED",
    "accepted_at": "2026-09-04T10:02:12Z",
    "customer": { "name": "Marta Ochoa", "phone": "+52 461 123 4567" },
    "pickup": { "label": "Farmacia San Juan", "address": "Av. Tecnológico 210" },
    "dropoff": { "label": "Col. Alameda", "address": "Calle Olmo 45" }
  }
}

El teléfono y la dirección exacta solo aparecen después de aceptar. En la oferta van la etiqueta y las coordenadas aproximadas, para que el repartidor decida sin exponer datos del cliente a quien no va a llevar el envío.

# Reglas del negocio

RN-01: La oferta se emite en paralelo a todos los repartidores en línea y sin envío activo del tenant. El primero que llegue al servidor gana.
RN-02: La aceptación se resuelve por HTTP, dentro de una transacción con lockForUpdate sobre la fila del envío. El socket solo difunde el resultado.
RN-03: La ventana de aceptación es de 45 segundos. Al vencer, las ofertas PENDING pasan a EXPIRED y el envío regresa a PENDING.
RN-04: Si un envío expira sin aceptación, se reofrece hasta 3 veces. A la tercera queda en PENDING y se notifica al admin_cliente para asignación manual.
RN-05: Un repartidor no recibe dos veces la misma oferta si ya la rechazó o la perdió, salvo que sea el único disponible en la reoferta final.
RN-06: Un repartidor con envío activo no recibe ofertas. Un solo envío a la vez.
RN-07: Rechazar es explícito y no penaliza. Dejar expirar sin responder tres veces seguidas apaga el modo en línea con reason: TIMEOUT (SPEC-019, RN-05).
RN-08: Aceptar un envío ya asignado devuelve 409 con el motivo, no un error genérico. La App cierra la tarjeta de oferta sin mostrar error rojo: perder una carrera es normal, no es una falla.

# Errores 
DELIVERY_ALREADY_TAKEN	409	Otro repartidor aceptó primero
OFFER_EXPIRED	409	Venció la ventana de 45 segundos
OFFER_NOT_FOUND	404	No se le ofreció ese envío, o es de otro tenant
COURIER_NOT_AVAILABLE	409	Está fuera de línea o ya tiene envío activo

# Criterios de aceptación
- Dos repartidores aceptan el mismo envío con 50 ms de diferencia: uno recibe 200, el otro 409 con DELIVERY_ALREADY_TAKEN, y deliveries.courier_id queda con un solo valor.
- El que pierde recibe DELIVERY_OFFER_CLOSED y la tarjeta desaparece de su pantalla sin mensaje de error.
- Un repartidor fuera de línea no recibe NEW_DELIVERY_AVAILABLE.
- Un repartidor con envío en IN_TRANSIT no recibe ofertas nuevas.
- Aceptar a los 46 segundos devuelve 409 con OFFER_EXPIRED.
- Un envío que expira tres veces queda en PENDING y aparece en el Panel para asignación manual.
- El payload de NEW_DELIVERY_AVAILABLE no contiene teléfono ni dirección exacta del cliente.
- Con la app cerrada, la oferta llega como push (SPEC-018, RN-04) y al abrir la app la tarjeta sigue vigente si no ha expirado.
- Un repartidor del tenant A recibe 404 al aceptar un envío del tenant B.
