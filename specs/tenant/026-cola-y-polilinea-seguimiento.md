# SPEC-026 — Cola de pedidos al liberarse y polilínea de seguimiento

**Metadatos**

| Campo | Valor |
|---|---|
| ID | SPEC-026 |
| Módulo | Logística / Seguimiento |
| Autor | A. Rivas |
| Versión | 1.0 |
| Estado | Borrador |
| Sprint | S-11 |
| Depende de | SPEC-018 (Protocolo realtime), SPEC-019 (Disponibilidad), SPEC-020 (Oferta y aceptación), SPEC-021 (Live tracking) |
| Habilita a | — |

---

## 1. Objetivo

Dos correcciones sobre el envío en curso:

1. Que un conductor que acaba de entregar **vuelva a ver los pedidos que estaban en cola**. Hoy su
   bandeja queda vacía: mientras estuvo ocupado no era elegible para las ofertas (SPEC-020, RN-06),
   y al liberarse nadie vuelve a ofertarle nada.
2. Que la **línea del mapa cuente en qué va el envío**: a dónde se dirige el conductor, con qué
   estilo de trazo, y de qué color según quién sea.

## 2. Alcance

**Incluye:** reactivación de la cola cuando un conductor queda libre, rescate de los pedidos que
cayeron a asignación manual, orden del pool por antigüedad, los dos hitos de la polilínea, estilo de
trazo por hito y color estable por conductor en el Panel y en `panda_express`.

**No incluye:** ordenamiento del pool por cercanía, cálculo de ETA, elección manual del color desde
el Panel, historial del recorrido (`route_snapshot`, ya definido en SPEC-021), y asignación manual de
un pedido a un conductor concreto desde el Panel.

## 3. Actores y permisos

| Actor | Permisos |
|---|---|
| `conductor` | Recibe la cola reactivada al quedar libre; ve su propia línea |
| `admin_cliente` | Ve en el mapa a todos sus conductores con su color y su línea |
| `despachador` | Igual que `admin_cliente`, sobre sus conductores |
| `super_admin` | Solo lectura |

## 4. Modelo de datos

**No hay tablas nuevas.** Los dos bloques se resuelven con lo que ya existe.

**Bloque cola.** Se reutilizan `pedido_ofertas` y `pedidos.veces_ofertado` tal como los definió
SPEC-020. El único cambio de datos es de semántica: `veces_ofertado` **se reinicia a 0** cuando un
pedido vuelve a la cola por la aparición de un conductor libre (RN-04). El contador mide rondas
fallidas *con conductores disponibles*, no rondas históricas del pedido.

**Bloque polilínea.** El color **no se guarda**: se calcula siempre igual a partir de
`conductores.id_conductor` contra una paleta fija (RN-17). Una columna guardada se desincronizaría
entre el Panel y la app y no aporta nada que el cálculo no dé gratis.

### Paleta de conductores

Diez colores, en este orden, elegidos para distinguirse entre sí sobre el mapa claro de Google:

| # | Hex | # | Hex |
|---|---|---|---|
| 0 | `#2563EB` | 5 | `#DB2777` |
| 1 | `#DC2626` | 6 | `#0891B2` |
| 2 | `#059669` | 7 | `#65A30D` |
| 3 | `#D97706` | 8 | `#EA580C` |
| 4 | `#7C3AED` | 9 | `#4F46E5` |

### Bloque `seguimiento`

Es la pieza central del bloque de polilínea: **el servidor decide qué línea toca dibujar**, y el
Panel y `panda_express` solo la pintan. Sin esto, cada app deduce el hito por su cuenta a partir del
estado y las dos se desincronizan — que es exactamente lo que pasa hoy, donde la app cambia de tramo
en `EN_CAMINO` y el Panel en `ARRIBADO`.

Viaja dentro del pedido, en `PedidoResource` (app del conductor) y en el `pedido_asignado` de
`GET /t/{slug}/conductores/activos` (Panel):

```json
"seguimiento": {
  "hito": "H1",
  "estilo": "GUIONES",
  "color": "#2563EB",
  "origen": null,
  "destino": { "lat": 20.5231, "lng": -100.8154 }
}
```

- `hito` — `H1` o `H2`.
- `estilo` — `GUIONES` en H1, `SOLIDO` en H2.
- `color` — el color del conductor asignado al pedido.
- `origen` — `null` en H1: el origen es la **posición viva** del conductor, que la app ya tiene y el
  Panel recibe por `ubicacion.actualizada`. En H2 son las coordenadas fijas de recogida.
- `destino` — recogida en H1, entrega en H2.

`seguimiento` es `null` cuando el pedido está en un estado final o todavía no tiene conductor.

## 5. Endpoints y eventos

| Método | Ruta | Cambio | Éxito |
|---|---|---|---|
| GET | `/conductor/pedidos/disponibles` | Se ordena por antigüedad del pedido (RN-08) | 200 |
| GET | `/conductor/pedidos/activo` | Suma el bloque `seguimiento` | 200 |
| GET | `/t/{slug}/conductores/activos` | Suma `color` por conductor y `seguimiento` en `pedido_asignado` | 200 |

No se agregan rutas. La reactivación de la cola no es una acción que alguien pida: es una
consecuencia de entregar o cancelar, y ocurre dentro de ese mismo flujo.

**Evento nuevo `conductor.cola-reactivada`**, canal `private-tenant.{slug}.conductores`:

```json
{
  "event": "conductor.cola-reactivada",
  "event_id": "01JC4M2QX8",
  "emitted_at": "2026-09-07T14:31:02Z",
  "payload": {
    "id_conductor": 12,
    "total": 3
  }
}
```

Un solo evento con el total, **no** un `pedido.disponible` por cada pedido (RN-09). El canal es por
tenant, así que la app compara `id_conductor` con el suyo antes de reaccionar (SPEC-018, RN-09) y
recarga su pool una sola vez.

## 6. Reglas de negocio

### Cola al quedar libre

- **RN-01:** Un conductor "queda libre" al llegar su pedido activo a `ENTREGADO` o `CANCELADO`, y
  también al pasar a `DISPONIBLE` sin pedido activo (conectarse). Los tres casos disparan la
  reactivación.
- **RN-02:** La reactivación corre **después** de confirmar la transición, fuera de su transacción.
  Si falla, la entrega no se revierte: queda un `warning` en bitácora y el sondeo de 10 s de la app
  sigue siendo la red de seguridad.
- **RN-03:** Entran a la cola los pedidos `PUBLICADO` sin conductor y los `PENDIENTE` **que ya
  habían sido publicados antes** (`fecha_publicacion` no nula). Un `PENDIENTE` que nunca se publicó
  es un borrador del despachador y no se toca: publicarlo solo porque apareció un conductor sería
  decidir por él.
- **RN-04:** Un `PENDIENTE` rescatado vuelve a `PUBLICADO` y su `veces_ofertado` se reinicia a 0. Un
  pedido no se descarta por rondas gastadas cuando esas rondas se agotaron sin nadie en línea.
- **RN-05:** Se crea una oferta al conductor liberado por **cada** pedido en cola, todas con la misma
  ventana de 45 s de SPEC-020. Él elige cuál acepta; aceptar uno cierra las demás ofertas de *ese*
  pedido como `PERDIDA`, y los otros pedidos siguen su curso normal.
- **RN-06:** Se respeta el rechazo explícito: un pedido que ese conductor marcó `RECHAZADA` no se le
  vuelve a ofrecer (SPEC-020, RN-05). Las ofertas `PERDIDA` y `EXPIRADA` sí se reabren, porque
  ninguna de las dos fue una decisión suya.
- **RN-07:** Solo se le ofrece si sigue `DISPONIBLE`, sin pedido activo y con saldo suficiente
  (SPEC-019 RN-02, SPEC-023 RN-03). Si entregó y se desconectó, no recibe nada.
- **RN-08:** El pool `GET /conductor/pedidos/disponibles` se ordena por antigüedad del **pedido**
  (`pedidos.created_at` ascendente), no por la hora de la oferta: en una reactivación todas las
  ofertas nacen en el mismo instante y ese orden no diría nada.
- **RN-09:** La reactivación emite un único `conductor.cola-reactivada`. Tres pedidos en cola no
  pueden significar tres sonidos, tres vibraciones y tres notificaciones del navegador.
- **RN-10:** Si dos conductores quedan libres a la vez, ambos reciben las mismas ofertas. La carrera
  se resuelve como siempre, con `lockForUpdate` (SPEC-020, RN-02).

### Hitos y polilínea

- **RN-11:** El hito se deriva del estado del pedido: `TOMADO` → **H1**; `ARRIBADO`, `EN_CAMINO` y
  `ARRIBADO_A_ENTREGA` → **H2**; estados finales → sin línea.
- **RN-12:** El cambio a H2 ocurre **al llegar al punto de recogida** (`ARRIBADO`), no al presionar
  "iniciar viaje" (`EN_CAMINO`). El conductor que ya está en el negocio quiere ver a dónde va
  después, no la línea del tramo que acaba de terminar.
- **RN-13:** El servidor es el único que decide hito, estilo, color y extremos, y los manda en el
  bloque `seguimiento`. El Panel y `panda_express` no vuelven a deducirlos del estado por su cuenta.
- **RN-14:** **H1** — línea en guiones, desde la posición viva del conductor hasta el punto de
  recogida. Se redibuja cuando el conductor se movió más de 50 m o cada 15 s, lo que ocurra primero
  (misma cadencia de SPEC-021, RN-02). Redibujar en cada punto sería una llamada a Directions cada
  pocos segundos por conductor.
- **RN-15:** **H2** — línea sólida, desde el punto de recogida registrado hasta el punto de entrega.
  Se dibuja una vez al entrar al hito y no se recalcula aunque el conductor se mueva. Solo se
  redibuja si cambia el destino (SPEC-022, `DELIVERY_ADDRESS_UPDATED`).
- **RN-16:** Una sola línea por conductor a la vez. Entrar a H2 borra la de H1.
- **RN-17:** El color es `PALETA[id_conductor % 10]`. Determinista, sin columna en base de datos: el
  mismo conductor conserva su color entre recargas, entre sesiones y entre las dos apps.
- **RN-18:** El marcador del conductor usa **ese mismo color**, para poder ligar de un vistazo la
  moto con su línea en un mapa con varios conductores.
- **RN-19:** Con más de diez conductores activos los colores se repiten. Dos conductores del mismo
  color en zonas distintas es aceptable; inventar cincuenta colores distinguibles, no.
- **RN-20:** La línea sigue calles reales (Directions). Si Directions falla, se traza una recta entre
  los extremos **con el mismo color y el mismo estilo**: degradar el trazo escondería que la ruta es
  aproximada.
- **RN-21:** La línea desaparece del Panel y de la app en cuanto el pedido llega a `ENTREGADO` o
  `CANCELADO`.
- **RN-22:** `panda_express` aplica las mismas reglas de color y estilo aunque el conductor solo se
  vea a sí mismo, para que lo que él ve y lo que ve el despachador sean la misma imagen.

## 7. Errores

No se agregan códigos. Los que aplican son los de SPEC-020, sobre las ofertas que crea la
reactivación:

| Código | HTTP | Cuándo |
|---|---|---|
| `Este pedido ya no está disponible.` | 422 | Otro conductor aceptó primero uno de los pedidos reactivados |
| `Ya tienes un pedido activo, no puedes aceptar otro.` | 422 | Aceptar un segundo pedido de la cola |
| `OFFER_NOT_FOUND` | 422 | Rechazar un pedido cuya oferta ya se resolvió |

Una reactivación que falla no devuelve error a nadie: no hay una petición humana esperándola. Se
registra como `warning` (RN-02).

## 8. Criterios de aceptación

- [ ] Con dos pedidos en cola y un conductor entregando el suyo, al marcar `ENTREGADO` los dos
      pedidos aparecen en su bandeja sin recargar y sin esperar los 10 s del sondeo.
- [ ] Ese mismo caso, pero cancelando el pedido en vez de entregarlo: mismo resultado.
- [ ] Un conductor que entrega y se desconecta en el mismo movimiento no recibe ofertas.
- [ ] Un conductor sin saldo que entrega no recibe ofertas.
- [ ] Un pedido que cayó a `PENDIENTE` por agotar sus 3 rondas vuelve a `PUBLICADO` y se le ofrece al
      conductor que acaba de liberarse, con `veces_ofertado` en 0.
- [ ] Un pedido `PENDIENTE` que nunca fue publicado sigue en `PENDIENTE` después de la reactivación.
- [ ] Un pedido que ese conductor había rechazado explícitamente **no** reaparece en su bandeja.
- [ ] Un pedido que ese conductor había perdido contra otro sí reaparece.
- [ ] Con tres pedidos reactivados, la app reproduce **un** sonido y muestra **un** aviso, no tres.
- [ ] La bandeja lista los pedidos del más viejo al más nuevo.
- [ ] Dos conductores que entregan con 50 ms de diferencia reciben los mismos pedidos; el primero en
      aceptar se lo queda y el segundo recibe el 422 de siempre.
- [ ] Con el pedido en `TOMADO`, la línea va de la moto al punto de recogida y está **punteada**.
- [ ] Al marcar llegada a recogida (`ARRIBADO`), la línea punteada desaparece y aparece una línea
      **sólida** de recogida a entrega, sin esperar a "iniciar viaje".
- [ ] La línea de H2 no se mueve mientras el conductor avanza hacia la entrega.
- [ ] Con dos conductores activos en el Panel, cada uno tiene su color y su marcador va del mismo
      color que su línea.
- [ ] Recargar el Panel no cambia el color de ningún conductor.
- [ ] El color que ve el conductor en `panda_express` es el mismo que el Panel le dibuja.
- [ ] Al marcar `ENTREGADO`, la línea desaparece del Panel y de la app.
- [ ] Con Directions fallando, la línea se dibuja recta, del color correcto y con el estilo correcto.

## 9. Adiciones técnicas aceptadas

| # | Adición | Dónde vive |
|---|---|---|
| 1 | Reoferta automática al liberarse el conductor | `OfertaPedidoService::reactivarColaPara()`, invocado desde `PedidoEstadoService` tras confirmar `ENTREGADO`/`CANCELADO` y desde `DisponibilidadService` al pasar a `DISPONIBLE` |
| 2 | Aviso en tiempo real para que la cola aparezca sola | Evento `ConductorColaReactivada` → `conductor.cola-reactivada`; en la app, un watcher que llama `orders.loadOrders()` una sola vez |
| 3 | Rescate de los pedidos en asignación manual | Filtro de RN-03 dentro de `reactivarColaPara()`, con la transición `PENDIENTE → PUBLICADO` que ya existe |
| 4 | Color estable por conductor sin guardarlo | Helper compartido en el backend (`PALETA[id % 10]`), expuesto en los Resources |
| 5 | Soporte de línea punteada | `GoogleProvider.drawRoute()` acepta `estilo`; los guiones se hacen con `strokeOpacity: 0` más `icons[]` repetidos, que es como Google dibuja trazos discontinuos |
| 5b | La polilínea se dibuja a mano, no con `DirectionsRenderer` | `DirectionsRenderer` solo traslada a su línea las propiedades de trazo —color, opacidad, grosor— y descarta `icons`, así que con él el tramo H1 quedaba en la línea base invisible y sin los símbolos encima: no se veía nada. `GoogleProvider.drawRoute()` crea su propia `google.maps.Polyline` sobre el `overview_path` que devuelve Directions —igual que la rama de respaldo de RN-20 y que `panda_express`, que por eso sí pintaban los guiones— y el encuadre que hacía el renderer con `preserveViewport: false` pasa a un `map.fitBounds(route.bounds)` explícito |
| 6 | Adelantar el cambio de línea a `ARRIBADO` | Consecuencia directa de RN-11: al derivarlo el servidor, las dos apps cambian a la vez |
| 7 | Un solo lugar que decida qué línea dibujar | El bloque `seguimiento` de §4, calculado en el backend y consumido igual por el Panel y por `panda_express` |

## 10. Impacto en el código existente

**Backend**

- `app/Services/OfertaPedidoService.php` — método nuevo `reactivarColaPara(Conductor)`.
- `app/Services/PedidoEstadoService.php` — engancha la reactivación tras `ENTREGADO` y `CANCELADO`.
- `app/Services/DisponibilidadService.php` — la engancha también al pasar a `DISPONIBLE`.
- `app/Events/Tenant/ConductorColaReactivada.php` — evento nuevo.
- `app/Http/Controllers/Tenant/Conductor/PedidoController.php` — orden del pool (RN-08).
- Resources de pedido y de conductor activo — bloque `seguimiento` y `color`.

**Panel (`frontend`)**

- `src/services/maps/types.ts` y `GoogleProvider.ts` — opción `estilo` en `drawRoute`, color en el
  marcador, y la ruta trazada con `Polyline` propia en lugar de `DirectionsRenderer` (§9, adición 5b).
- `src/components/panel/MapaConductores.vue` — dibuja según `seguimiento` en vez de deducir el
  destino a partir del estado.

**App del conductor (`panda_express`)**

- `src/composables/useMapController.js` — deja de decidir la fase por su cuenta; lee `seguimiento`.
  Además recuerda la orden que le toca pintar y la redibuja cuando el mapa termina de inicializarse
  —`Dashboard.vue` restaura el viaje activo **antes** de crear el mapa, así que el primer trazado se
  descartaba y el conductor se quedaba sin la línea de H1 hasta el siguiente cambio de estado— y
  rehace el tramo H1 con la cadencia de RN-14 conforme llega la posición del GPS, que hasta ahora
  solo movía el marcador de la moto.
- `src/services/maps/MapService.js` — trazo punteado y color por conductor.
- `src/views/Dashboard.vue` — watcher del evento de cola reactivada.
