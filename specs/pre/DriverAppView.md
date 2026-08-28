# Especificación: Pantalla del conductor (DriverAppView)

Archivo original: C:\laragon\www\delivery\frontend\src\views\DriverAppView.vue

## Qué es esta pantalla

Es la pantalla principal de la aplicación del conductor. Ocupa toda la
pantalla del teléfono y tiene un mapa de fondo.

## Lo principal que hace

1. Muestra un mapa con la ubicación actual del conductor.
2. Permite ponerse "En línea" u "Offline" para recibir o no recibir viajes.
3. Muestra los viajes disponibles con su información básica.
4. Permite aceptar un viaje con un botón.
5. Guía al conductor al punto de recogida y después al punto de entrega.
6. Avisa cuando el conductor llega a cada punto.
7. Permite completar la entrega y actualiza las ganancias del día.
8. Tiene un modo simulador para probar el recorrido sin moverse de lugar.

## Datos que maneja

### Del conductor
- Estado: en línea u offline.
- Ganancias del día (dinero ganado).
- Viajes hechos hoy.
- Saldo de garantía: si es cero, no puede aceptar viajes nuevos.
- Viajes disponibles según su plan (si aplica).

### De los viajes disponibles
- Lista de pedidos que el conductor puede aceptar.
- Cada pedido muestra: origen, destino, distancia, tarifa y forma de pago
  (prepago o efectivo).

### Del viaje activo
- El viaje que el conductor ya aceptó.
- Guarda: origen, destino, distancia y los datos del receptor
  (nombre y teléfono).

### Comunicación con el servidor
La pantalla pide y envía datos al servidor en varios momentos:
- Al abrir: recupera el estado en línea/offline y el viaje a medias si lo hay.
- Cada 30 segundos: actualiza las ganancias del día.
- Al aceptar un viaje, al cambiar de paso y al completar: avisa al servidor.
- De forma constante: envía la ubicación del conductor para que se vea en el mapa.

## Los pasos de un viaje

1. En camino al origen: el conductor va al punto de recogida. No hay botones.
   La aplicación detecta sola cuando llegó (por GPS o por simulador).
2. En punto de recogida: aparece el botón "Iniciar viaje al destino" para
   confirmar que ya recogió el pedido.
3. En camino al destino: el conductor va al punto de entrega. Otra vez, la
   aplicación detecta sola cuando llegó.
4. En punto de entrega: aparece el botón "Completar Entrega" para terminar
   el viaje.

Al completar, se limpia la pantalla, se actualizan las ganancias y vuelven a
mostrarse los viajes disponibles.

## Cómo se entera la pantalla de los cambios

- Avisos instantáneos: cuando llega un viaje nuevo, otro conductor lo toma o
  se cancela un viaje, la pantalla se entera al momento, sin recargar.
  - Viaje nuevo: recarga la lista (si el conductor está en línea y sin viaje activo).
  - Viaje tomado por otro: lo quita de la lista.
  - Viaje cancelado: avisa con un mensaje y limpia el viaje en curso.
  - Billetera actualizada: refresca las ganancias del día.
- Refresco de respaldo: cada 8 segundos vuelve a pedir la lista de viajes por
  si algo no llegó por la vía rápida. Es solo una red de seguridad.
- Al volver a la aplicación: si el conductor dejó un viaje a medias, la
  pantalla lo recupera automáticamente.

## Avisos al conductor

- Nuevo viaje en la lista: vibra el teléfono, suena un tono y, si el
  navegador lo permite, muestra una notificación.
- Llegó a la recogida: mensaje verde "Llegaste al punto de recogida".
- Llegó a la entrega: mensaje morado "Llegaste al punto de entrega".
- Viaje cancelado: mensaje rojo "Viaje cancelado".
- Sin saldo de garantía: la tarjeta avisa que debe recargar saldo para seguir
  recibiendo viajes.

## Cómo encuentra el camino

- Con el GPS real: la aplicación lee la posición del teléfono, dibuja la ruta
  en el mapa y avisa cuando el conductor está a menos de 80 metros del punto
  de recogida o de entrega.
- Con el simulador: la aplicación mueve la posición por la ruta dibujada a la
  velocidad elegida (de 30 a 120 km/h) para imitar el movimiento real. Esto
  sirve para probar sin moverse de lugar.
- El conductor puede llamar o escribir por WhatsApp al receptor directamente
  desde la pantalla del viaje.

## Lo que muestra la pantalla (de arriba a abajo)

- Arriba: la foto del conductor, el interruptor "En línea/Offline" y el
  interruptor "Live/Simulador".
- Flotando sobre el mapa: las ganancias del día y los viajes disponibles.
- Mensajes temporales: avisos de llegada, cancelación, etc. (aparecen y
  desaparecen solos).
- Al fondo, una tarjeta que sube desde la parte baja:
  - Sin viaje activo: muestra el siguiente viaje disponible (origen, destino,
    distancia, tarifa y forma de pago) con el botón "Aceptar Viaje". Si no hay
    viajes, muestra "Sin viajes disponibles" o "Estás offline".
  - Con viaje activo: muestra el estado del viaje, los datos de la ruta, los
    datos del receptor y el botón del paso que toca según el momento.

## Reglas importantes

- Solo se puede tener un viaje activo a la vez.
- Si el saldo de garantía es cero, no se pueden aceptar viajes nuevos.
- Si el conductor está offline, la lista de viajes se vacía.
- Aceptar un viaje es inmediato: el viaje sale de la lista al instante para
  dar sensación de rapidez. Si algo falla, vuelve a aparecer.
- Si dos conductores aceptan el mismo viaje, se lo queda el primero que
  logre confirmarlo; al otro se le avisa.

## Puntos a revisar o mejorar

1. El aviso de nuevo viaje no suena cuando es el primer viaje de la lista;
   solo suena cuando llega uno nuevo y ya había otro esperando.
2. El enlace de WhatsApp asume que el teléfono del receptor es de México
   (prefijo 52).
3. Si el mapa no puede calcular la ruta, se dibuja una línea recta entre el
   origen y el destino.
4. Hay algunas variables guardadas que no se usan en ningún lado (sobran).
5. La animación de las monedas al completar una entrega depende de la
   posición de los elementos en pantalla; si la pantalla cambia justo en ese
   momento, puede verse rara.



