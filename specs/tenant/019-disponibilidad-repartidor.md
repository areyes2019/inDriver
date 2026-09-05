# Spec: Disponibilidad del repartidor (admin_cliente,despachador y conductor)

Depende de: SPEC tenant/018
Habilita: a SPEC tenatn/020

# Objetivo
Permiri que el repartidor se ponga en lina o fuera de linea en cualquier momento desde la app Vue + capacitor, y que el panel sepa entodo momento y de forma instantanea a aquien ofrecerle un envío. Tambien, que el despachador sepa de una manera 100% visual, que repartidores estan en linea.

# Alcance
Incluye: encender y apagar el modo en lina, reflejarlo en el panel de manera inmediata y mediante una notificacion tipo tosat, hacer visible al despachador o al admin_cliente su estado.

No inluye: ofrecer envios, horarios, turnos programados, geocercas, aceptacon de tareas. 

# Actores
- Admin_cliente
- Despachador
- Repartidor

# Modelo de datos

Se agregan columnas a couriers, no hay tabla nueva:

live_mode — boolean, default false
live_since — timestamp, nullable
last_seen_at — timestamp, nullable

last_seen_at se actualiza con cada LOCATION_UPDATE (SPEC-021) y con cada llamada a /api/v1/sync. Es lo que permite distinguir "en línea de verdad" de "se le acabó la batería".

# Endpoints
PUT /api/v1/live-mode (Enciende o apaga el modo en linea)
GET /api/v1/couriers (lista de repartidores con su estado)

# Proceso
1. El repartidor se pone en linea 
2. Al usuario en el panel le aparece una notificaicon tipo toast
3. El nombre y los datos del repartidor aparecen en menu lateral derecho del panel. 

# modelo de respuesta

Encendido correcto
{
  "data": {
    "courier_id": "01JB2C8N4KQZ",
    "live_mode": true,
    "live_since": "2026-09-04T08:15:00Z",
    "balance": 340.50
  }
}

Devuelve saldo por que es la condicion que acaba de ser evaluada; asi la App, refresca la cifra en la misma llamada y el repartidor puede saber si esta habilitado para hacer entregas

Saldo insuficiente
{
  "message": "No hay suficiente saldo",
  "errors": {
    "live_mode": ["INSUFFICIENT_BALANCE"]
  }
}

Envio en curso
{
  "error": {
    "code": "HAS_ACTIVE_DELIVERY",
    "message": "No puedes salir de línea con un envío en curso.",
    "details": { "delivery_id": "01JB2K3F9A" }
  }
}

El usuario no es repartidor
{
  "message": "El usuario no es repartidor"
}

# reglas de la spec
- RN-01: Solo hay dos estados: live_mode verdadero o falso. No existe "ocupado"; que un repartidor tenga envío activo se sabe consultando deliveries, no duplicando un estado aquí.

- RN-02: Para encender el modo en línea el repartidor necesita saldo mayor a cero. Si no, se rechaza con INSUFFICIENT_BALANCE.

- RN-03: El repartidor no puede apagarse si tiene un envío en curso. Debe entregarlo o pedir cancelación al panel.

- RN-04: Si last_seen_at supera 10 minutos, un job marca live_mode = false con reason: TIMEOUT y emite el evento al Panel.

- RN-05: Al cerrar sesión, live_mode pasa a false automáticamente.


# Criterios de aceptación
- Con saldo 0, la respuesta es INSUFFICIENT_BALANCE y el estado sigue en false.
- Al encender, el Panel muestra al repartidor como disponible sin recargar la página.
- Un repartidor sin last_seen_at por 10 minutos aparece como no disponible en elPanel.
- Al cerrar sesión y volver a entrar, el repartidor aparece fuera de línea.
