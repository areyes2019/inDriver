# Spec: Modo TEST / LIVE (el mismo sistema, con las coordenadas simuladas)

## Historia de usuario

Como AdminCliente, quiero un interruptor TEST/LIVE en mi Panel para poder probar el sistema completo
—crear un envío, que un conductor real lo acepte desde su app real, verlo moverse por calles reales
en el mapa, y que se le descuente su saldo— sin tener que salir a la calle ni pedirle a nadie que
maneje. En LIVE todo debe seguir funcionando exactamente como hoy, con el GPS del teléfono.

## Objetivo / Alcance

Probar el sistema de punta a punta exige hoy que alguien se suba a un vehículo y maneje: sin GPS
real no hay tracking, sin tracking no caen las geocercas, y sin geocercas el envío se queda clavado
en `TOMADO`. Eso hace imposible verificar el flujo completo —incluida la liquidación al conductor—
desde un escritorio.

Hubo un intento anterior (spec `tenant/025`, borrada en `dde24e2`) que resolvió esto con
**conductores ficticios** y un interruptor global de "modo prueba". Se retiró para salir a
producción porque duplicaba entidades: creaba una flotilla falsa que había que mantener, filtrar y
ocultar en cada listado. Esta spec ataca el mismo problema desde el ángulo contrario: **los actores
son todos reales; lo único simulado es de dónde salen las coordenadas.**

Deja funcionando:

- Un interruptor TEST/LIVE por tenant en la cabecera del Panel, al estilo Facturapi/Stripe.
- Cada envío sella su ambiente al nacer y lo conserva para siempre.
- En TEST, cuando el conductor acepta desde su app real, el servidor lo desplaza por una ruta real
  de calles a 60 km/h, desde donde está hasta la recogida y de la recogida a la entrega.
- Esas coordenadas entran por el mismo `TrackingService`, escriben en la misma tabla y emiten los
  mismos eventos Reverb que el GPS real. El Panel y la app no distinguen la diferencia.
- Los envíos de prueba avanzan solos por los dos hitos de llegada (`ARRIBADO`,
  `ARRIBADO_A_ENTREGA`), que en LIVE dispara la geocerca del teléfono.
- El Panel muestra solo los envíos del ambiente en el que está parado.
- Un conductor no se apaga solo por estar en un envío simulado, ni por acabar de conectarse
  arrastrando la marca de una sesión anterior (corrige un defecto de la spec `tenant/019`).

**No** incluye:

- Conductores, clientes o vehículos ficticios. Ninguna entidad nueva marcada como "de prueba".
- Una segunda aplicación, un segundo flujo de tracking o una segunda máquina de estados.
- Saldos separados por ambiente: el dinero de prueba es dinero real (ver "El dinero no se simula").
- Cualquier cambio en `panda_express`. La app del conductor no se entera de que existen ambientes.
- Tráfico, semáforos, paradas o variación de velocidad. La simulación es un móvil a 60 km/h.
- Un botón para limpiar o revertir los datos de prueba.

## Decisión técnica

### El ambiente vive en el envío, no en el mundo

`pedidos.ambiente` (`live` | `test`, por omisión `live`) se sella en `PedidoController@store`
copiando el switch del tenant en ese instante, y **nunca más cambia**: no entra en el `#[Fillable]`
del modelo y ningún `update` lo toca. Un envío es de prueba o no lo es desde que nace hasta que
muere.

Se descarta la alternativa evidente —resolver el ambiente en tiempo de consulta, mirando el switch
actual— porque el switch se mueve y los envíos no: un viaje en curso cuando alguien cambia el
interruptor se convertiría en otra cosa a mitad del recorrido, con el conductor ya en la calle.

### La única diferencia es de dónde entra la coordenada

`TrackingService::registrarPosicion()` ya era el único lugar del sistema con permiso de escribir una
posición: guarda el punto, actualiza `conductor_estado` y emite `UbicacionActualizada`. El simulador
llama **a ese mismo método, sin variantes ni parámetros nuevos**. Por eso el Panel, el mapa, los
eventos Reverb y `resumen_ruta` funcionan en TEST sin una sola línea de código propia.

La diferencia se reduce a callar la otra fuente. En TEST el teléfono del conductor sigue mandando su
GPS aunque él esté sentado en su casa, y dos fuentes escribiendo la misma posición se pisan. Se
descarta en la entrada del GPS real, en `Conductor\UbicacionController`:

- `actualizar()`: si el envío activo del conductor es `test`, responde 204 sin guardar.
- `lote()`: si el envío es `test`, responde 204 sin guardar.

Son dos condiciones, no una, porque son dos puertas. Se descarta explícitamente reintroducir el
parámetro `desdeSimulador` en `TrackingService` —como hacía el simulador anterior— para no meterle
al servicio de tracking una rama que solo existe para el modo prueba: quien decide callar es la
puerta del teléfono, no el escritor.

Para poder preguntarlo, `TrackingService::pedidoActivo()` pasa de privado a público. Es la misma
consulta que el controlador tendría que duplicar.

### Se descarta la posición, no al conductor

`conductor_estado.ultima_actualizacion` cumple dos papeles a la vez: dice dónde está el conductor y
dice que sigue vivo. `conductor:apagar-inactivos` (spec `tenant/019`, RN-04) apaga cada minuto a
quien lleve diez sin dar señales.

Descartar el GPS de un envío TEST descartaría también esa señal, y el conductor quedaría apagado a
los diez minutos **en mitad de un envío simulado** — sin haber hecho nada mal, y sin que el Panel
pudiera explicar por qué desapareció. Por eso las dos puertas del GPS real llaman a
`TrackingService::registrarLatido()` antes de responder 204: se tira la coordenada, que la manda el
simulador, y se conserva la prueba de vida, que solo puede darla el teléfono.

Mientras un tramo avanza no haría falta —el simulador escribe posiciones y eso ya refresca el
latido—, pero entre tramos no corre ninguno: desde que el envío llega a `ARRIBADO` hasta que el
conductor pulsa "recogí" no hay simulación, y ese hueco lo pone él, no el sistema.

### Conectarse cuenta como señal de vida

`DisponibilidadService::conectar()` guardaba `estado = ONLINE` y `ultima_conexion`, pero no tocaba
`ultima_actualizacion`. Un conductor que vuelve al día siguiente arrastra entonces la marca de su
sesión anterior, y el barrido de inactivos lo apaga **al minuto de haberse conectado**.

Es un defecto de la spec `tenant/019` que esta spec destapa, no que crea: hasta ahora el barrido
dependía de un `schedule:run` que no siempre corría. Como el modo TEST sí lo exige —es lo que mueve
la simulación—, el defecto pasa de latente a permanente y se corrige aquí. Conectarse es, por
definición, dar señales de vida.

### El recorrido se calcula por reloj, no por una cadena de tareas

Producción no tiene un proceso permanente vigilado: el `schedule` levanta un `queue:work` acotado a
55 segundos por minuto (spec `tenant/024`). Una cadena de jobs donde cada punto agenda el siguiente
—como hacía `SimularSiguientePunto`— pierde eslabones en el hueco de ~5 segundos de cada minuto, y
un eslabón perdido deja el viaje congelado para siempre.

La simulación no lleva la cuenta de en qué punto va. La deduce del reloj:

```
metrosAhora = (ahora − iniciada_en) en segundos × 16.667 m/s
```

De ahí sale todo. El comando `simulacion:avanzar` corre cada minuto desde el `schedule`, y durante
sus ~55 segundos de vida escribe un punto cada 2 segundos (unos 33 metros) para que el movimiento se
vea fluido en el mapa. Si una corrida se pierde, si el servidor se reinicia o si el minuto se salta
entero, **la siguiente corrida se pone al día sola**: recalcula dónde debería estar el móvil y
escribe de golpe los puntos que faltaban. No hay estado que reparar porque el estado es la hora de
arranque.

Se descarta colgar la simulación de la cola por la misma razón que la spec `tenant/024` descartó
publicar con `->delay()`: el `schedule` es la infraestructura mínima que el despliegue ya exige, y un
job es una promesa que alguien tiene que estar vivo para cumplir.

### Velocidad constante de verdad, caminando la polilínea

El simulador anterior muestreaba la ruta a 40 puntos fijos y los emitía cada 2 segundos, con lo que
la velocidad real dependía de qué tan largo fuera el viaje: 40 puntos de un viaje de 1 km y 40 de
uno de 15 km tardaban lo mismo.

Aquí la ruta se guarda con la **distancia acumulada en cada vértice**, y la posición se interpola
linealmente sobre ese recorrido según los metros que dicta el reloj. Un envío de 2 km tarda 2
minutos y uno de 10 km tarda 10, que es lo que hace creíble la prueba.

### La ruta se pide una vez y se guarda

`RutaService` consulta Google Directions desde el servidor y decodifica la polilínea (el algoritmo
estándar de Google, recuperado de `SimuladorRutaService`). El resultado se guarda en
`simulaciones_envio.ruta`, así que **cada tramo se paga una sola vez** aunque el comando corra
sesenta veces durante el viaje.

Esto devuelve al servidor la `GOOGLE_MAPS_API_KEY` que `dde24e2` retiró por quedar sin uso. Se elige
Google, y no un servicio libre como OSRM, para que la ruta que recorre el conductor simulado sea la
misma que el Panel dibuja en el mapa con `GoogleProvider.ts`.

Si Directions no responde —sin llave, sin red, cuota agotada— se cae a **línea recta** entre los dos
puntos, con `Log::warning`. El viaje de prueba se ve feo pero no se queda muerto, que es el mismo
criterio del respaldo de `useMapController.js`.

### El filtro por ambiente se pone solo, y por omisión no filtra

`Pedido` recibe un global scope `AmbienteScope`. Lee de un contenedor de contexto que **está vacío
por omisión**: sin ambiente en el contexto, el scope no filtra nada.

Solo un middleware nuevo (`AplicarAmbientePanel`) lo llena, y va colgado exclusivamente del grupo
`auth:usuario` de `routes/api.php` — el Panel. Con eso las tres excepciones salen gratis, sin
enumerarlas: el comando de simulación, los jobs diferidos y la app del conductor
(`auth:conductor-token`) nunca pasan por ese middleware, así que siguen viendo todos los envíos.

Se descarta el filtro escrito a mano en cada consulta: son decenas de sitios y basta un olvido para
que un envío de prueba aparezca en la pantalla de operación real.

### El dinero no se simula

Un envío TEST entregado **descuenta el viaje prepago, calcula y guarda la comisión, mueve el saldo
del conductor y cobra su importe**, exactamente igual que uno LIVE, con los mismos movimientos y sin
ninguna marca que los distinga. `PedidoEstadoService::liquidarConductor()` y `SaldoService` no se
tocan.

Es una decisión explícita del dueño del producto, tomada sobre la base de que hoy los créditos son
ficticios y de que el objetivo de estas pruebas es precisamente **verificar que el descuento
funcione**. Los riesgos que asume están en "Riesgos aceptados".

La única separación que sí existe es de lectura: los listados y reportes del Panel ven solo su
ambiente, por el global scope. El saldo del conductor **no** se separa, porque es una columna única
en `conductores` y no cuelga del envío.

### La app del conductor no se entera de nada

`panda_express` no recibe el campo `ambiente` ni cambia una línea. En TEST su geocerca nunca dispara
—el teléfono está quieto en otra parte— así que el servidor transiciona los dos hitos de llegada y
la app se entera por `PedidoEstadoCambiado`, el mismo evento que ya la sincroniza hoy cuando un
despachador mueve un envío desde el Panel. El mecanismo ya estaba construido; solo cambia quién lo
dispara.

`EN_CAMINO` (recogí el paquete) y `ENTREGADO` los sigue disparando el conductor con su botón, igual
que en LIVE: son acciones humanas, no llegadas geográficas.

## Reglas de negocio

- **RN-01**: El ambiente es por tenant, vive en `configuraciones_tenant` bajo la clave `ambiente`, y
  por omisión es `live`. Es persistente entre sesiones.
- **RN-02**: Solo el AdminCliente puede cambiarlo. El Despachador lo ve en la cabecera y no puede
  moverlo.
- **RN-03**: Un envío sella `pedidos.ambiente` en el momento de crearse, copiando el switch de ese
  instante. Es inmutable: no existe forma de convertir un TEST en LIVE ni al revés.
- **RN-04**: Cambiar el switch no altera ningún envío ya creado, ni siquiera los que están en curso.
- **RN-05**: Los listados, reportes y estadísticas del Panel muestran únicamente los envíos del
  ambiente activo. El saldo del conductor es la excepción conocida: es uno solo y mezcla ambos.
- **RN-06**: El dinero se comporta idéntico en ambos ambientes. Un envío TEST descuenta prepago,
  genera comisión, mueve saldo y cobra importe como uno LIVE, sin marca distintiva.
- **RN-07**: En TEST, la simulación arranca en dos momentos: al pasar a `TOMADO` (tramo de
  acercamiento, desde la última posición conocida del conductor hasta la recogida) y al pasar a
  `EN_CAMINO` (tramo de entrega, de la recogida a la entrega).
- **RN-08**: Si el conductor nunca reportó posición, el tramo de acercamiento arranca en el punto de
  recogida mismo: dura un instante y `ARRIBADO` cae de inmediato.
- **RN-09**: La velocidad simulada es 60 km/h constante (16.667 m/s). No hay tráfico, semáforos ni
  paradas. Se escribe un punto cada 2 segundos, unos 33 metros.
- **RN-10**: Las coordenadas simuladas entran por `TrackingService::registrarPosicion()`, el mismo
  método que usa el GPS real, y emiten los mismos eventos Reverb.
- **RN-11**: Mientras un envío TEST está activo, el GPS real que manda el teléfono se descarta con
  204, tanto en el flujo normal como en el lote de reconexión. Se descarta la **posición**, no la
  señal de vida: ambas puertas refrescan `ultima_actualizacion` antes de responder, o el barrido de
  inactivos apagaría al conductor en mitad del recorrido simulado.
- **RN-12**: Conectarse refresca `ultima_actualizacion`. Un conductor recién puesto en línea nunca
  puede apagarse por inactividad heredada de una sesión anterior (corrige la `tenant/019`).
- **RN-13**: Al completarse cada tramo, el servidor transiciona el envío: acercamiento → `ARRIBADO`,
  entrega → `ARRIBADO_A_ENTREGA`. `EN_CAMINO` y `ENTREGADO` los sigue disparando el conductor.
- **RN-14**: El avance se deduce del reloj, no de un contador. Una corrida perdida del comando se
  recupera sola en la siguiente, escribiendo de golpe los puntos atrasados.
- **RN-15**: Cancelar el envío detiene la simulación. Un envío en estado final no avanza más.
- **RN-16**: La ruta se calcula una vez por tramo y se guarda. Si el proveedor falla, se usa línea
  recta y se anota `warning` en la bitácora.
- **RN-17**: `panda_express` no conoce el ambiente. No recibe el campo ni cambia su comportamiento.
- **RN-18**: Las posiciones de un envío TEST se purgan igual que las de uno LIVE: 7 días después de
  concluido (`TrackingService::RETENCION_DIAS`).

## Backend (Laravel)

- **Migración tenant** `add_ambiente_to_pedidos_table`: `enum('ambiente', ['live','test'])` con
  omisión `live` e índice. Los envíos existentes quedan `live`.
- **Migración tenant** `create_simulaciones_envio_table`: `id_simulacion`, `id_pedido`, `tramo`
  (`ACERCAMIENTO` | `ENTREGA`), `ruta` (json de `{lat, lng, m}` con metros acumulados),
  `distancia_m`, `iniciada_en`, `avanzada_hasta_m`, `terminada_en`, único por `(id_pedido, tramo)`.
- **`ConfiguracionTenant`**: constante `AMBIENTE = 'ambiente'`.
- **`Tenant\ConfiguracionController`**: `show()` devuelve `ambiente`; método nuevo `ambiente()` para
  el `PUT /configuracion/ambiente` del interruptor, sin pasar por el formulario completo.
- **`Tenant\PedidoController@store`**: sella `ambiente` desde la configuración del tenant.
- **`Models\Tenant\Pedido`**: global scope `AmbienteScope`; `ambiente` **fuera** del `#[Fillable]`.
- **`Scopes\AmbienteScope`** y **`Support\ContextoAmbiente`** (contenedor de contexto, vacío por
  omisión): el scope no filtra si nadie lo llenó.
- **`Http\Middleware\AplicarAmbientePanel`**: llena el contexto desde la configuración del tenant.
  Colgado solo del grupo `auth:usuario`.
- **`Services\RutaService`** (nuevo): Google Directions + decodificador de polilínea + acumulado de
  metros; línea recta como respaldo.
- **`Services\SimulacionEnvioService`** (nuevo): abre el tramo (calcula y guarda la ruta), avanza por
  reloj interpolando sobre la polilínea, escribe vía `TrackingService::registrarPosicion()`, y
  transiciona al terminar.
- **`Console\Commands\AvanzarSimulaciones`** (`simulacion:avanzar`): recorre los tenants activos y
  avanza cada 2 segundos durante su ventana de vida.
- **`routes/console.php`**: `Schedule::command('simulacion:avanzar')->everyMinute()
  ->withoutOverlapping(2)->runInBackground()`.
- **`Services\PedidoEstadoService`**: en `TOMADO` y en `EN_CAMINO`, si el envío es `test`, abre el
  tramo correspondiente. Es el único punto de contacto con la máquina de estados y no altera ninguna
  transición existente.
- **`Services\TrackingService`**: `pedidoActivo()` pasa a público y se agrega `registrarLatido()`,
  que solo toca `ultima_actualizacion`. El resto del servicio no cambia.
- **`Services\DisponibilidadService`**: `guardar()` fija `ultima_actualizacion` al pasar a `ONLINE`.
- **`Conductor\UbicacionController`**: descarta con 204 el GPS real de un envío `test`, en
  `actualizar()` y en `lote()`, refrescando el latido en ambas.
- **`Resources\Tenant\PedidoResource`**: expone `ambiente`, que el Panel usa para el chip "TEST".
  La app del conductor recibe el mismo resource y lo ignora.
- **`config/services.php` y `.env.example`**: vuelve `GOOGLE_MAPS_API_KEY` del lado del servidor.

## Frontend (`frontend/`, Panel)

- **`stores/ambiente.ts`** (nuevo): estado del switch, carga desde `GET /configuracion` y lo cambia
  con `PUT /configuracion/ambiente`.
- **`layouts/TenantLayout.vue`**: interruptor TEST/LIVE en la cabecera, siempre visible. En TEST,
  banda de color a lo ancho del Panel para que nadie confunda la pantalla con la de operación real.
  Deshabilitado si el rol no es AdminCliente.
- **Listados de envíos**: sin cambios. El filtrado ocurre en el servidor por el global scope.

## Frontend (`panda_express`)

Sin cambios de ningún tipo.

## Criterios de aceptación

- Con el switch en TEST, un envío creado en el Panel nace con `ambiente = test`; con el switch en
  LIVE, nace `live`.
- Cambiar el switch a LIVE con un envío TEST en curso no altera su ambiente ni detiene su simulación.
- En LIVE, el listado de envíos no muestra ninguno de los creados en TEST, y viceversa.
- Un Despachador ve el indicador de ambiente y recibe 403 al intentar cambiarlo.
- En TEST, cuando el conductor acepta desde su app, se crea la simulación del tramo de acercamiento
  con su ruta guardada.
- Adelantando el reloj, las posiciones aparecen en `conductor_posiciones` con el envío correcto y se
  emite `UbicacionActualizada` por cada una.
- Adelantando el reloj lo suficiente para completar el tramo de acercamiento, el envío queda
  `ARRIBADO` sin que la app haya mandado nada.
- Al pasar a `EN_CAMINO`, se abre el tramo de entrega; completado, el envío queda
  `ARRIBADO_A_ENTREGA`.
- Un envío de 10 km tarda diez veces más en completarse que uno de 1 km.
- Saltarse una corrida entera del comando no rompe nada: la siguiente escribe los puntos atrasados y
  el móvil queda donde le corresponde por reloj.
- Con un envío TEST activo, `POST /conductor/ubicacion` responde 204 y **no** escribe en
  `conductor_posiciones`. Con uno LIVE, sigue escribiendo como hoy.
- Ese mismo 204 **sí** refresca `ultima_actualizacion`: un conductor con la marca de hace nueve
  horas sobrevive a `conductor:apagar-inactivos` en cuanto su teléfono reporta una vez.
- Un conductor que se pone en línea con `ultima_actualizacion` de hace nueve horas sigue `ONLINE`
  después de correr `conductor:apagar-inactivos`.
- Con Directions caído (`Http::fake` de error), el tramo se crea igual con ruta en línea recta y
  queda un `warning` en la bitácora.
- Cancelar un envío TEST detiene la simulación: no se escriben más posiciones.
- Un envío TEST entregado descuenta el viaje prepago, escribe su movimiento de saldo y guarda su
  comisión, exactamente igual que uno LIVE.
- El comando de simulación y la app del conductor ven los envíos TEST pese al global scope.
- La suite corre sin salir a internet y sin esperas reales: `Http::fake()` para Directions y
  `travel()` para el reloj.
- Pint y ESLint/Prettier corren sin errores sobre el código nuevo; `php artisan test` pasa.

## Riesgos aceptados

1. **Un conductor real puede quedarse sin poder trabajar.** El saldo autoriza a conectarse
   (`DisponibilidadService`, `INSUFFICIENT_BALANCE`). Diez viajes de prueba consumen diez viajes
   reales de ese conductor. La reposición es manual, con el `POST /conductores/{id}/saldo` que ya
   existe. No se construye ningún botón de limpieza.
2. **El conductor no puede distinguir una prueba de un envío real.** Al quedar la app ciega, puede
   salir a la calle por un envío que no existe. Se mitiga solo por acuerdo humano: avisarle antes de
   probar.
3. **Los movimientos de saldo de prueba son indistinguibles de los reales.** Quedan rastreables de
   forma indirecta, porque `movimientos_saldo` guarda el `id_pedido` y el envío sí lleva su
   ambiente, pero ninguna pantalla los separa.
4. **Vuelve una llave de Google al servidor**, con su costo por consulta. Se acota guardando la ruta:
   dos consultas por envío de prueba, no dos por minuto.

## Supuestos asumidos (registro completo)

1. El switch es por tenant, no global del sistema: cada empresa cliente decide su ambiente sin
   afectar a las demás.
2. Vive en la cabecera del Panel, siempre visible, con banda de color en TEST — modelo
   Facturapi/Stripe.
3. Solo el AdminCliente lo cambia; el Despachador lo ve.
4. Es persistente: no se reinicia al cerrar sesión.
5. Cambiar el switch no toca ningún envío ya creado.
6. El ambiente se sella al crear el envío.
7. El ambiente es inmutable.
8. En LIVE solo se ven envíos LIVE; en TEST solo TEST. No se mezclan.
9. Los envíos TEST se muestran con chip "TEST" en el Panel.
10. Reportes y estadísticas muestran el ambiente activo. **Ajustado en revisión**: la propuesta
    original era que ignoraran siempre lo TEST; se cambió al modelo por ambiente activo porque el
    dueño decidió que la comisión TEST sí sume en sus reportes.
11. Un envío TEST entregado **descuenta viajes prepago del saldo real**. **Ajustado en revisión**:
    la propuesta original era no descontar. Motivo del dueño: los créditos son ficticios hoy y el
    objetivo es verificar que el descuento funcione. Revisar este punto cuando los créditos sean
    dinero real.
12. Un envío TEST **calcula, guarda y suma su comisión**. **Ajustado en revisión**.
13. Un envío TEST **mueve el saldo real, sin marca**. **Ajustado en revisión**.
14. Un envío TEST **calcula y cobra su importe igual que uno LIVE**. **Ajustado en revisión**.
15. Se usan los mismos conductores, clientes, vehículos y despachadores reales.
16. El estado online/offline del conductor es uno solo, compartido entre ambientes.
17. El conductor acepta el envío TEST desde su app real, con su login real.
18. El conductor recibe la notificación push de un envío TEST igual que de uno LIVE.
19. La simulación arranca al aceptar (`TOMADO`), no antes.
20. Son dos tramos: acercamiento y entrega.
21. Mientras la simulación corre, el GPS real del teléfono se descarta.
22. La velocidad es 60 km/h constante, sin tráfico ni paradas.
23. La ruta se calcula con Google Directions; línea recta si falla.
24. Al terminar cada tramo el envío avanza solo de estado, sin que el conductor toque nada.
25. El paso a `ENTREGADO` lo confirma el conductor. Por derivación, `EN_CAMINO` también: son
    acciones humanas, no llegadas geográficas.
26. La simulación corre en el servidor y sigue aunque se cierren la app y el Panel.
27. Si el envío se cancela, la simulación se detiene.
28. Los datos de tracking TEST se purgan igual que los LIVE, a los 7 días.
29. El avance se calcula por reloj y no por una cadena de tareas, para que sobreviva al worker
    acotado de 55 segundos de producción.
30. El paso de 2 segundos entre puntos es fijo en el código, no configurable por tenant. Se puede
    promover a `configuraciones_tenant` si algún tenant lo pide, igual que la velocidad.
31. Los puntos atrasados de una corrida perdida se escriben todos, no se saltan: `resumen_ruta` y
    `distancia_recorrida_km` dependen de ellos. El costo es una ráfaga de eventos Reverb que el
    Panel resuelve dejando el marcador donde toca.
32. El global scope no filtra cuando nadie llenó el contexto. Es lo que hace que el comando, los
    jobs y la app del conductor vean todo sin necesidad de excepciones enumeradas.
33. `ultima_actualizacion` sigue sirviendo para dos cosas a la vez —dónde está el conductor y si
    sigue vivo— en vez de separarlas en dos columnas. Se prefiere refrescarla desde las dos puertas
    del GPS descartado antes que agregar una columna de latido que el resto del sistema tendría que
    aprender a mantener.
34. El envío TEST no lleva ningún latido propio: si el teléfono del conductor se apaga de verdad
    durante un recorrido simulado, el barrido lo apaga a los diez minutos, igual que en LIVE. Es
    intencional — el conductor de una prueba es un conductor real y su disponibilidad es una sola.
35. Corregir `DisponibilidadService::conectar()` cambia el comportamiento de la spec `tenant/019`
    también en LIVE. Se asume a propósito: era un defecto en ambos ambientes, latente solo porque el
    `schedule:run` no siempre corría, y el modo TEST lo vuelve permanente al exigirlo.
36. El correlativo `numero_pedido` se calcula con `withoutGlobalScopes()`: es del tenant entero, no
    del ambiente. Sin eso, el primer envío TEST reciclaría el número de uno LIVE.
