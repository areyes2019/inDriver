# Spec: Publicación de envíos (el viaje creado en el Panel llega a la app)

## Historia de usuario

Como Despachador (o dueño de tenant en ese papel), quiero que un envío que doy de alta en el Panel
le aparezca al instante a los conductores en línea, y que uno agendado para más tarde se les ofrezca
solo cuando esté por empezar — sin que yo tenga que hacer nada más que crearlo, y viendo en mi propia
pantalla que ya salió a la flotilla.

## Objetivo / Alcance

Un envío creado desde el Panel nacía `PENDIENTE` y se quedaba ahí para siempre: **nada en el
sistema lo pasaba nunca a `PUBLICADO`**. Como `PUBLICADO` es lo único que dispara
`OfertaPedidoService::ofertar()` (spec `tenant/020`), nunca se creaba una fila en `pedido_ofertas`,
y como el pool del conductor son sus propias ofertas vigentes y no "todo lo PUBLICADO del tenant",
`GET /conductor/pedidos/disponibles` devolvía `[]` siempre. La app del conductor no mostraba nada,
ni al instante ni tras el sondeo de 10s. Era un hueco entre specs: la `020` declara explícitamente
que la creación del envío queda fuera de su alcance, y las `006`/`007` nunca definieron quién
publica.

Deja funcionando:

- Un envío "lo antes posible" se publica dentro de la misma petición que lo crea.
- Un envío agendado se publica automáticamente 15 minutos antes de su horario de servicio.
- Los avisos del protocolo de tiempo real (spec `tenant/018`) salen de verdad, en el acto, en vez de
  quedarse encolados para siempre.
- La lista "Viajes en turno" del Panel se corrige sola por socket, sin recargar la página.
- Los jobs diferidos del sistema (expiración y reoferta de ofertas, aviso de "sin confirmar")
  corren también en producción, sin agregar ningún proceso supervisado.
- Documentación de qué backend y qué tenant usa cada build de `panda_express`.

**No** incluye:

- Un botón "Publicar" manual en el Panel. Un agendado que hoy quiera adelantarse sigue sin tener
  cómo, salvo esperar su ventana.
- Asignación manual de un envío a un conductor concreto desde el Panel.
- Cualquier cambio a la máquina de estados, a la ventana de 45s o al algoritmo de elegibilidad de
  la spec `tenant/020`.
- Cambios en `panda_express` más allá del README.

## Decisión técnica

### Publicar al crear, no un botón

`Tenant\PedidoController@store` publica el pedido recién creado cuando `lo_antes_posible` es
verdadero. Se descarta el botón "Publicar" como mecanismo principal: el despachador que llena el
formulario de "Nueva entrega" ya expresó su intención al darlo de alta; obligarlo a un segundo clic
solo agrega un estado intermedio en el que el envío existe pero nadie lo está buscando — que es
exactamente la situación que esta spec viene a eliminar.

La publicación va **fuera** de la transacción del alta y envuelta en `try/catch` (spec `tenant/018`,
RN-08): si Reverb está caído, el despachador no tiene por qué recibir un error por un aviso que no
salió. Como `PedidoEstadoService::transicionar()` deja el estado nuevo en el modelo en memoria
*antes* de avisar, el `finally` persiste `PUBLICADO` aunque el aviso haya fallado: dejar ofertas
abiertas sobre un pedido que en la base sigue `PENDIENTE` sería peor que perder el aviso, porque el
conductor vería la oferta en su pool y recibiría un 422 al aceptarla.

### Los agendados los publica un comando de `schedule:run`, no un job con `delay()`

`pedidos:publicar-agendados` corre cada minuto sobre todos los tenants activos, igual que
`conductor:apagar-inactivos` (spec `tenant/019`), y publica lo que ya entró en la anticipación de 15
minutos.

Se descarta programar un job con `->delay()` al crear el pedido, que sería lo natural, porque un
job es una promesa que alguien tiene que cumplir: la publicación es la operación central de esta
spec y no puede depender de que el worker esté vivo. El `schedule` sí es la infraestructura mínima
que el despliegue ya exige para tres tareas más (`deploy/README.md`), así que publicar cuelga de la
misma cuerda que apagar conductores inactivos. Los jobs diferidos que sí siguen existiendo tienen su
propia solución más abajo ("El worker de colas vive dentro del `schedule`").

El filtro fino (`fecha_servicio` + `hora_desde` contra el reloj) se hace en PHP y no en SQL:
`TIMESTAMP(fecha, hora)` es de MySQL y las pruebas corren sobre SQLite en memoria. La base solo hace
el recorte grueso por `fecha_servicio`, que es portable, y deja un puñado de filas por tenant para
comparar en memoria.

### La ventana de servicio pone el tope: un agendado vencido no se publica

El comando exige, además de `hora_desde <= ahora + 15min`, que `hora_hasta >= ahora`. Sin ese tope,
la primera corrida después de este cambio publicaría de golpe todos los envíos agendados que
quedaron `PENDIENTE` mientras el sistema no sabía publicarlos — incluidos los de hace días — y se
los ofrecería a la flotilla como si fueran viajes del momento. Con el tope, un agendado cuya ventana
ya cerró se queda `PENDIENTE` y a la vista en el Panel, para que una persona decida qué hacer con
él.

### Todos los eventos del protocolo pasan a `ShouldBroadcastNow`

La spec `tenant/018` (RN-08) ya exigía que ningún aviso del protocolo dependiera de un proceso en
segundo plano, pero solo tres eventos lo cumplían (`ConductorDisponibilidadCambiada`,
`SaldoAcreditado`, `SaldoCambiado`). Los otros nueve seguían siendo `ShouldBroadcast`, es decir
encolados, y por tanto a merced de que hubiera un worker vivo — que en producción no lo había (ver
más abajo). Un aviso de tiempo real no debe depender de eso ni cuando el worker existe: la promesa
de la spec `018` es "al instante".

Esto no era solo teórico: `ConductoresActivos.vue` (spec `tenant/023`) ya escucha `pedido.tomado`,
`pedido.cancelado` y `pedido.entregado` para recalcular el badge Ocupado/Disponible, y ninguno de
esos tres llegaba nunca. Pasan a `ShouldBroadcastNow` los nueve: `PedidoDisponible`, `PedidoYaTomado`,
`PedidoCanceladoParaConductor`, `PedidoEntregado`, `PedidoReprogramado`, `PedidoDireccionActualizada`,
`PedidoRequiereAsignacionManual`, `PedidoSinConfirmar` y `UbicacionActualizada`.

`UbicacionActualizada` es de alta frecuencia y ahora se emite dentro de la petición del conductor.
Es el costo asumido de que llegue: encolado no llegaba nunca, y RN-05 de la `018` ya acepta que una
posición perdida se reemplaza sola con la siguiente.

### El Panel escucha el canal que ya tenía abierto

`ServiciosEnTurno.vue` se suscribe al mismo canal del tenant que `ConductoresActivos.vue` ya usa
(`realtime.ts`, spec `tenant/018`) y recarga en silencio con `pedido.disponible`, `pedido.tomado`,
`pedido.cancelado`, `pedido.entregado` y `pedido.requiere-asignacion-manual`. Se recarga la lista
completa en vez de fusionar el payload del evento fila por fila: la lista ya se arma paginando
`GET /pedidos` y filtrando estados en turno, así que una sola fuente de verdad cuesta una petición y
evita que el Panel invente un estado que el servidor no tiene.

No se agrega sondeo periódico: si el socket está caído, la lista se queda como estaba hasta que
alguien recargue — el mismo comportamiento que hoy, no uno peor.

> **Reemplazado por `tenant/027-panel-reactivo-sin-recargas.md`.** "Se recarga la lista completa en
> vez de fusionar el payload del evento fila por fila" fue exactamente lo que rompió el Panel en
> operación real: la lista se armaba paginando `GET /pedidos` **entero** (sin filtro de estado), así
> que cada evento costaba varias peticiones, y con tres componentes haciendo lo mismo el limitador de
> 20/min devolvía 429 y borraba la lista. La 027 conserva la idea de una sola fuente de verdad —pero
> la pone en un store compartido en vez de en una petición por evento— y sí fusiona el payload fila
> por fila. También agrega el sondeo de respaldo que aquí se descartaba, acotado a mientras el socket
> esté caído (027, RN-14). Los cinco eventos listados arriba siguen siendo los correctos.

## Reglas de negocio

- **RN-01**: Un pedido `lo_antes_posible` se publica en la misma petición que lo crea.
- **RN-02**: Un pedido agendado se publica cuando faltan 15 minutos o menos para `hora_desde`,
  siempre que `hora_hasta` no haya pasado ya. Si su ventana cerró sin publicarse, se queda
  `PENDIENTE`; no se publica tarde.
- **RN-03**: Publicar no puede fallarle al despachador. Si el aviso no sale, el pedido queda
  `PUBLICADO` igual y el fallo se anota como `warning` en la bitácora (hereda RN-08 de la `018`).
- **RN-04**: Ningún aviso del protocolo depende de un worker de colas: todos son
  `ShouldBroadcastNow` (hereda RN-08 de la `018`).
- **RN-05**: Publicar no cambia a quién se le ofrece ni por cuánto tiempo — eso sigue siendo
  íntegramente `OfertaPedidoService` (spec `tenant/020`).
- **RN-06**: Los jobs diferidos del sistema corren sin ningún proceso permanente supervisado: el
  `schedule` levanta un `queue:work` acotado cada minuto. Los jobs se encolan siempre en la base
  central (`DB_QUEUE_CONNECTION`), nunca en la del tenant.

## Backend (Laravel)

- **`Tenant\PedidoController@store`**: publica el pedido si `lo_antes_posible`, con el método privado
  `publicar()` (fuera de transacción, `try/catch` + `Log::warning`, persistencia en `finally`).
- **Comando nuevo** `App\Console\Commands\PublicarPedidosAgendados` (`pedidos:publicar-agendados`):
  recorre los tenants activos y publica los agendados dentro de la anticipación de 15 minutos
  (`ANTICIPACION_MINUTOS`).
- **`routes/console.php`**: `Schedule::command('pedidos:publicar-agendados')->everyMinute()`.
- **Eventos**: los nueve que faltaban pasan de `ShouldBroadcast` a `ShouldBroadcastNow`.

## Frontend (`frontend/`, Panel)

- **`ServiciosEnTurno.vue`**: se suscribe al canal del tenant y recarga con los cinco eventos de
  pedido listados arriba; se desuscribe al desmontarse.

## Frontend (`panda_express`)

- **`README.md`**: tabla de qué `.env` usa cada comando, contra qué backend apunta y con qué
  `VITE_TENANT_SLUG` compila. Los slugs de local (`moto-express-celaya`) y producción
  (`moto-celaya`) no coinciden, y apuntar al backend correcto con el slug del otro entorno da una
  app vacía sin ningún error visible.

### El worker de colas vive dentro del `schedule`, no bajo supervisor

Quedan dos jobs diferidos que no son sustituibles por un barrido periódico sin perder precisión:
`ExpirarOfertaPedido` (cierra la ventana de 45s de la spec `tenant/020` y reoferta) y
`AvisarSinConfirmar` (los 60s del ACK de la spec `tenant/022`). Ambos necesitan un `queue:work`.

En producción no había ninguno, y además faltaba `DB_QUEUE_CONNECTION`: sin esa variable,
`database.default` durante una petición de tenant apunta a la conexión `tenant` (Stancl), así que el
job se guardaba en la tabla `jobs` de la base **del tenant** mientras cualquier worker —que corre sin
tenancy— miraría la **central**. Dos fallas encadenadas: la variable ya estaba puesta en el `.env`
local (por eso ahí los jobs sí funcionan), pero no en `.env.example` ni en el ejemplo de producción.

Se descarta supervisor/systemd con un `queue:work` permanente: mete un proceso nuevo que hay que
vigilar y reiniciar en cada despliegue, en un VPS que hoy no supervisa ninguno. En su lugar el propio
`schedule` levanta un worker acotado, en segundo plano y sin solaparse:

```php
Schedule::command('queue:work --max-time=55 --sleep=1 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();
```

Sin `--stop-when-empty` a propósito: los tres jobs son diferidos, y un worker que se apaga en cuanto
la cola está vacía se iría justo antes de que el job venza. Se queda los 55 segundos sondeando cada
segundo. `runInBackground()` no es opcional: en primer plano bloquearía a `conductor:apagar-inactivos`
y `pedidos:publicar-agendados`, que corren en ese mismo minuto.

Se descarta también un barrido `pedidos:expirar-ofertas` cada minuto: convertiría la ventana de 45
segundos de la spec `tenant/020` en una de 45 a 105 segundos, y no arreglaría los otros dos jobs.

Consecuencia asumida: hay un hueco de unos 5 segundos al final de cada minuto en el que no hay worker
vivo. Un job que venza ahí se atiende en el siguiente arranque, unos segundos después.

## Criterios de aceptación

- Crear un envío "lo antes posible" en el Panel lo deja `PUBLICADO` en la respuesta del `POST`, con
  una oferta `PENDIENTE` por cada conductor elegible.
- Crear un envío agendado lo deja `PENDIENTE` y sin ninguna oferta.
- `pedidos:publicar-agendados` publica un agendado que empieza en 10 minutos.
- `pedidos:publicar-agendados` no toca un agendado que empieza en 45 minutos.
- `pedidos:publicar-agendados` no toca un agendado cuya `hora_hasta` ya pasó.
- Con Reverb apagado, crear un envío "lo antes posible" responde 201 con estado `PUBLICADO` y el
  fallo del aviso aparece como `warning` en la bitácora.
- Un conductor en línea ve el envío en su pool sin recargar la app y sin esperar el sondeo de 10s.
- La lista "Viajes en turno" del Panel muestra el envío recién creado como `PUBLICADO` y lo pasa a
  `TOMADO` cuando un conductor lo acepta, sin recargar la página.
- Un evento del protocolo llega al cliente sin ningún `queue:work` corriendo.
- Una oferta que nadie contesta pasa a `EXPIRADA` y el pedido se reoferta, sin ningún proceso
  permanente en el servidor: solo con el `crontab` de `schedule:run`.
- Un job encolado dentro de una petición de tenant aparece en la tabla `jobs` de la base **central**,
  no en la del tenant.
- Agotados los 3 intentos, el pedido vuelve a `PENDIENTE` y el Panel lo refleja sin recargar.
- Pint y ESLint/Prettier corren sin errores sobre el código nuevo; `php artisan test` pasa.

## Supuestos asumidos (registro completo)

1. "Publicar" es una consecuencia de crear, no una acción aparte del despachador: por eso A1
   (auto-publicar) y no un botón.
2. La anticipación para los agendados es de 15 minutos, fija en el código (`ANTICIPACION_MINUTOS`),
   no configurable por tenant. Se puede promover a `configuraciones_tenant` si algún tenant lo pide.
3. Un agendado cuya ventana de servicio ya cerró **no** se publica: se prefiere dejarlo visible y
   detenido en el Panel antes que ofrecer un viaje vencido.
4. Un agendado que el despachador quiera adelantar no tiene hoy cómo publicarse antes de su ventana.
   Si hace falta, es el botón "Publicar" (A2) como spec aparte.
5. `hora_desde`/`hora_hasta` son obligatorias para un agendado (ya lo valida
   `PedidoController::validarDatos`), así que el comando no necesita una regla para el caso nulo más
   allá de ignorarlo.
6. El Panel recarga la lista completa al recibir un evento en vez de aplicar el payload: una sola
   fuente de verdad, al costo de una petición por evento.
7. `UbicacionActualizada` pasa a emitirse dentro de la petición del conductor pese a ser de alta
   frecuencia. Si el volumen lo justifica, la salida es un worker supervisado, no volver a encolarlo
   sin él.
8. El worker de colas se acota a 55 segundos por minuto en vez de correr permanente: se prefiere un
   hueco de ~5 segundos por minuto antes que un proceso supervisado que mantener. Si algún día el
   volumen lo exige, la salida es supervisor/systemd, no acortar el hueco a mano.
9. Reactivar la cola despierta un comportamiento que llevaba tiempo escrito pero dormido en
   producción: el aviso de "sin confirmar" de la spec `tenant/022`. Es intencional — es lo que su
   spec ya describe.
