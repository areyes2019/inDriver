# Spec: Simulación de los hitos del viaje (modo prueba)

## Historia de usuario

Como Conductor conectado desde la app en modo prueba, quiero que el viaje que acepto avance solo por
sus cuatro hitos —con mi punto moviéndose por la calle— y poder adelantar cualquiera de ellos con un
botón, para probar el flujo completo sin salir a manejar.

## Objetivo / Alcance

Un viaje aceptado desde la app se quedaba clavado en `TOMADO` para siempre. Los cuatro hitos del
flujo son cinco estados en el backend, y dos de ellos —los dos de *llegada*— solo se alcanzaban
cruzando una geocerca con el GPS real del teléfono:

| Hito | Estado | Cómo avanzaba | Sentado en el escritorio |
| --- | --- | --- | --- |
| h1 Viaje aceptado | `TOMADO` | botón "Aceptar" | funciona |
| h2 Llegada a recogida | `ARRIBADO` | solo geocerca (<80 m) | **se atora** |
| (paquete recogido) | `EN_CAMINO` | botón "Ir al destino" | funciona |
| h3 Llegada a entrega | `ARRIBADO_A_ENTREGA` | solo geocerca (<80 m), botón deshabilitado | **se atora** |
| h4 Viaje terminado | `ENTREGADO` | botón "Completar entrega" | funciona |

En un pedido de prueba el atasco era además irreversible, por dos piezas que se mordían la cola:
`TrackingService::registrarPosicion()` descarta el GPS real de la app cuando el pedido es
`es_prueba` (solo acepta posiciones del simulador), y `SimuladorRutaService` **solo arrancaba en
`EN_CAMINO`** — un estado al que el viaje ya no podía llegar. El único que podía mover el punto no
se encendía nunca.

Deja funcionando:

- Un pedido de prueba recorre los cuatro hitos solo, de `TOMADO` a `ENTREGADO`, sin que nadie toque
  nada, con la posición avanzando por la ruta real.
- El simulador cubre también el tramo de acercamiento (posición del conductor → punto de recogida),
  que antes no existía.
- Una fila de cuatro hitos en la app, visible solo en modo prueba, para adelantar cualquiera sin
  esperar al simulador.
- La app se entera de los cambios de estado que ocurren en el servidor, que hoy no ve.

**No** incluye:

- Cualquier cambio de comportamiento en un pedido real. La geocerca, el GPS y los botones siguen
  exactamente como hoy.
- El paso de cobro en la entrega. "Completar entrega" cierra el viaje directo, como hoy, sin
  preguntar por `importe_cobro`.
- Cambios a la máquina de estados, a la elegibilidad de conductores o a la liquidación
  (specs `013`, `020`).
- El conductor virtual del Panel (`useConductorPrueba.ts`), que sigue existiendo aparte para la
  demo sin teléfono.
- Simular el trayecto con velocidades o tiempos realistas: el recorrido dura decenas de segundos.

## Decisión técnica

### La cadena la conduce el cambio de estado, no el simulador

La pieza central: `PedidoEstadoService` decide, **cada vez que un pedido de prueba entra en un
estado**, cuál es el siguiente paso simulado. No hay un "guion" que corra de principio a fin.

| Al entrar en | El sistema programa |
| --- | --- |
| `TOMADO` | recorrer del punto del conductor a la recogida, y al llegar pasar a `ARRIBADO` |
| `ARRIBADO` | una pausa breve y pasar a `EN_CAMINO` (el paquete se recoge) |
| `EN_CAMINO` | recorrer de la recogida a la entrega, y al llegar pasar a `ARRIBADO_A_ENTREGA` |
| `ARRIBADO_A_ENTREGA` | una pausa breve y pasar a `ENTREGADO` |

Esto resuelve gratis el conflicto entre el simulador y los botones (que era una decisión pendiente):
si el conductor pulsa "Llegué a la recogida" mientras el recorrido va a la mitad, el estado cambia,
el paso siguiente se programa solo desde el estado nuevo, y el recorrido viejo se apaga por su
propia comprobación (`SimularSiguientePunto` ya se detiene si el pedido no está en el estado que él
creía). No hace falta cancelar nada ni coordinar dos relojes: **un botón y el simulador son la misma
cosa vista desde el estado**.

Se descarta un job único que ejecute los cuatro hitos en secuencia con esperas dentro (que es lo que
hace `useConductorPrueba.ts` en el Panel): un guion así no se entera de que alguien adelantó un
hito, y terminaría pisando el estado con transiciones ya inválidas.

### Todo paso simulado va con retraso, nunca inmediato

`PedidoEstadoService::transicionar()` avisa **antes** de que el llamador persista el pedido (lo dice
su propio contrato: "no llama a `save()`"). Un job encolado sin retraso leería de la base el estado
anterior y no haría nada. Todos los pasos simulados se programan con al menos un segundo de
retraso, que además es lo natural: nadie llega al punto de recogida en cero segundos.

### Dos tramos, no uno

`SimuladorRutaService` pasa de tener un solo recorrido (recogida → entrega) a dos:

- **Acercamiento**: de la última posición conocida del conductor (`conductor_estado.ultima_latitud`
  / `ultima_longitud`) al punto de recogida. Si no hay posición conocida —un conductor que se acaba
  de conectar y nunca reportó— el tramo arranca en el punto de recogida mismo: el recorrido dura un
  punto y `ARRIBADO` cae de inmediato. Es preferible a inventar una posición inicial arbitraria.
- **Entrega**: de la recogida a la entrega, que es el que ya existía.

Ambos usan el mismo cálculo de ruta real de Google Directions con el mismo respaldo a línea recta
cuando no hay API key o red.

### La app se entera del estado por el canal, no sondeando

Hoy la app solo conoce el estado que ella misma provocó: `trip.activeOrder.estado` se actualiza con
la respuesta de su propia petición. Un cambio hecho en el servidor —que es exactamente lo que hace
el simulador— era invisible: el conductor vería `TOMADO` en pantalla mientras el servidor ya llegó a
`ENTREGADO`.

Se agrega el evento `PedidoEstadoCambiado` (`pedido.estado-cambiado`) al canal del tenant, con
`id_pedido`, `id_conductor` y `estado`. La app descarta lo ajeno por `id_conductor` (RN-09 de la spec
`018`) y actualiza su viaje activo. Se descarta un sondeo periódico del pedido activo: el canal ya
existe, ya está autorizado y ya lo escucha la app.

El evento **no** es exclusivo del modo prueba: un cambio de estado hecho desde el Panel también
llegará ahora a la app, que es lo correcto y hoy no pasaba.

### La fila de hitos vive en la tarjeta del viaje, no en una pantalla aparte

`ActiveTripCard` gana una fila de cuatro pasos —aceptado, recogida, entrega, terminado— donde los
cumplidos se palomean y el siguiente es un botón pulsable. Se muestra **solo** si el pedido trae
`es_prueba`, campo que hasta ahora no viajaba en el `PedidoResource` del conductor y que se agrega.

En un pedido real la fila no se renderiza y la tarjeta queda idéntica a hoy, botón de `EN_CAMINO`
deshabilitado incluido. Se descarta un "modo simulación" global en la app: la marca vive en el
pedido (`es_prueba` se congela al crearlo), no en una preferencia del teléfono que podría
desincronizarse del pedido que se está atendiendo.

## Reglas de negocio

- **RN-01**: La simulación solo ocurre en pedidos `es_prueba`. Un pedido real no cambia en nada.
- **RN-02**: Un pedido de prueba avanza solo por los cuatro hitos, en orden, sin intervención.
- **RN-03**: El conductor puede adelantar el hito siguiente con un botón. Nunca puede saltarse uno:
  las transiciones permitidas siguen siendo las de `TRANSICIONES_CONDUCTOR` (spec `013`).
- **RN-04**: Si un hito se adelanta a mano, el recorrido en curso se apaga solo y la cadena continúa
  desde el estado nuevo. No hay dos simulaciones vivas sobre el mismo pedido.
- **RN-05**: Todo paso simulado se programa con retraso; ninguno se ejecuta dentro de la misma
  petición que provocó el cambio de estado.
- **RN-06**: La app refleja los cambios de estado del servidor sin recargar y sin sondear, por el
  canal del tenant.
- **RN-07**: Un fallo de la simulación no puede tumbar la operación: se registra en la bitácora y el
  viaje se queda donde estaba, adelantable a mano (hereda RN-08 de la spec `018`).

## Backend (Laravel)

- **`Conductor\PedidoResource`**: agrega `es_prueba`.
- **Evento nuevo** `PedidoEstadoCambiado` (`pedido.estado-cambiado`, `ShouldBroadcastNow` +
  `event_id`): se emite en cada transición de un pedido con conductor asignado.
- **`SimuladorRutaService`**: `iniciarAcercamiento()` (posición del conductor → recogida) e
  `iniciarEntrega()` (recogida → entrega, el de antes). Cada uno programa `SimularSiguientePunto`
  con el estado que está recorriendo y el estado al que pasar al terminar.
- **`SimularSiguientePunto`**: recibe `estadoEsperado` y `estadoAlLlegar`; se detiene si el pedido ya
  no está en `estadoEsperado`, y al escribir el último punto transiciona a `estadoAlLlegar`.
- **Job nuevo** `AvanzarEstadoSimulado`: los dos pasos sin movimiento (`ARRIBADO` → `EN_CAMINO`,
  `ARRIBADO_A_ENTREGA` → `ENTREGADO`), con la misma comprobación de estado esperado.
- **`PedidoEstadoService`**: `simularSiguientePaso()` reemplaza al `if ($nuevoEstado === 'EN_CAMINO'
  && $pedido->es_prueba)` de hoy y cubre los cuatro estados de la tabla de arriba.

## Frontend (`panda_express`)

- **`ActiveTripCard.vue`**: fila de cuatro hitos cuando `order.es_prueba`; el botón de `EN_CAMINO`
  deja de estar deshabilitado en ese caso.
- **`useRealtime.js`**: escucha `pedido.estado-cambiado` y actualiza `trip.activeOrder.estado` si el
  evento es de este conductor y de su pedido activo.

## Frontend (`frontend/`, Panel)

- **`ServiciosEnTurno.vue`**: agrega `pedido.estado-cambiado` a sus eventos de recarga, para ver el
  viaje caminando por sus estados sin recargar.

## Criterios de aceptación

- Un pedido de prueba aceptado desde la app llega a `ENTREGADO` sin que nadie toque un botón, con
  posiciones escritas a lo largo de las dos rutas.
- El conductor ve la fila de hitos avanzar sola en su pantalla, sin recargar la app.
- Pulsar el hito siguiente lo adelanta, y el recorrido que estaba en curso deja de escribir
  posiciones.
- Un pedido real no muestra la fila de hitos y su botón de `EN_CAMINO` sigue deshabilitado.
- Un conductor sin posición previa igual llega a `ARRIBADO`: el tramo de acercamiento no lo bloquea.
- El Panel refleja el avance de estados del viaje sin recargar la página.
- Un conductor no ve en su pantalla el cambio de estado del pedido de otro conductor.
- Pint y ESLint/Prettier corren sin errores sobre el código nuevo; `php artisan test` pasa.

## Supuestos asumidos (registro completo)

1. Los cuatro hitos son `TOMADO`, `ARRIBADO`, `ARRIBADO_A_ENTREGA` y `ENTREGADO`. `EN_CAMINO` no es
   un hito propio: es el momento intermedio de "recogí el paquete y salgo", y la simulación lo cruza
   con una pausa breve.
2. La simulación es exclusiva de `es_prueba`; un pedido real no cambia en absoluto.
3. Quien simula es un conductor real conectado desde la app, no el conductor virtual del Panel.
4. El cobro queda fuera: "Completar entrega" cierra el viaje directo, como hoy.
5. Sin posición previa del conductor, el tramo de acercamiento arranca en el punto de recogida
   —recorrido de un punto, `ARRIBADO` inmediato— en vez de inventar un origen.
6. Las pausas de los dos pasos sin movimiento son fijas en el código, no configurables.
7. `pedido.estado-cambiado` se emite para todos los pedidos con conductor, no solo los de prueba: la
   app tenía que enterarse igual de un cambio hecho desde el Panel.
8. La duración del recorrido no busca parecerse a la real: es la que ya usaba el simulador
   (`MAX_PUNTOS` puntos, unos segundos entre cada uno).
9. La simulación depende del worker de colas que la spec `024` (RN-06) dejó corriendo dentro del
   `schedule`. Sin ese worker, el viaje no avanza solo — pero los botones lo siguen sacando adelante.
