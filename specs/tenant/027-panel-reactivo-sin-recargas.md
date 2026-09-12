# SPEC-027 — Panel reactivo: actualización por evento, sin recargas

**Metadatos**

| Campo | Valor |
|---|---|
| ID | SPEC-027 |
| Módulo | Panel / Tiempo real |
| Autor | A. Rivas |
| Versión | 1.1 |
| Estado | Borrador |
| Sprint | S-12 |
| Depende de | SPEC-012 (Datos reales servicios en turno), SPEC-014 (Datos reales conductores activos), SPEC-018 (Protocolo realtime), SPEC-023 (Rediseño panel flotilla), SPEC-024 (Publicación de envíos), SPEC-026 (Cola y polilínea) |
| Habilita a | — |
| Reemplaza | SPEC-008 §"Lista scrolleable de tarjetas" (la lista única pasa a dos secciones), SPEC-012 §carga de datos, SPEC-014 §"sin tiempo real", SPEC-023 §"Qué eventos recarga el panel", SPEC-024 §"El Panel escucha el canal que ya tenía abierto" |

---

## 1. Objetivo

Que los dos paneles laterales del `/panel` —"Viajes en turno" y "Flotilla"— dejen de **recargarse** y
pasen a **actualizarse**: la fila que cambió cambia sola, en el acto, sin pedirle nada al servidor,
sin parpadeo, sin espacios en blanco y sin que la lista desaparezca.

Hoy no ocurre eso. Cada evento del canal dispara una recarga completa desde cero en los tres
componentes del panel a la vez, y esas recargas se estorban entre sí hasta romperse:

1. **`ServiciosEnTurno.vue` pagina el historial entero en cada evento.** `cargarViajes()`
   (`ServiciosEnTurno.vue:47-83`) recorre **todas** las páginas de `GET /t/{slug}/pedidos` —15 por
   página, sin filtro de estado, incluye entregados y cancelados de toda la vida del tenant— y
   descarta en el cliente lo que no está en turno. Con 60 envíos son 4 peticiones por evento.
2. **`ConductoresActivos.vue` y `MapaConductores.vue` piden lo mismo, por separado.** Los dos llaman
   a `GET /t/{slug}/conductores/activos` con su propia lista de eventos
   (`ConductoresActivos.vue:151-160`, `MapaConductores.vue:188-198`). Nadie comparte nada.
3. **Eso revienta el limitador.** `tenant-usuarios` permite **20 peticiones por minuto y por
   usuario** (`AppServiceProvider.php:44`) y es el mismo bucket para las dos rutas. Un viaje que se
   publica, se toma, camina sus estados y se entrega emite del orden de seis eventos; cada uno son
   ~6 peticiones entre los tres componentes. El **429** llega en segundos.
4. **Un 429 borra la pantalla.** `catch { error.value = true }` y el `v-if="error"` sustituyen la
   lista por "No se pudo cargar la lista de viajes / de conductores" con un botón "Reintentar" — que
   es exactamente el síntoma reportado, y explica por qué los dos paneles caen juntos: comparten el
   bucket.
5. **Cada recarga vacía la lista aunque salga bien.** `cargando = true` + `v-if="cargando"`
   reemplazan el contenido por "Cargando..." (`ServiciosEnTurno.vue:150`,
   `ConductoresActivos.vue:214`). Ese es el parpadeo.
6. **Las ráfagas se cancelan entre sí.** El `AbortController` de SPEC-012 aborta la recarga anterior
   en cada evento; en una ráfaga casi ninguna termina y la lista se queda colgada en el estado de
   carga.
7. **Los eventos ya traen datos y se tiran a la basura.** El canal se usa como campanita para
   refetch. `pedido.disponible` viaja con el `PedidoResource` completo y nadie lo lee.

SPEC-023 ya había registrado esto como deuda consciente (supuesto 22: *"El panel recarga la lista
completa ante cada evento, en vez de actualizar el ítem afectado en memoria"*). Esta spec la paga.

### Versión 1.1 — la lista deja de ser una sola

Resuelto el parpadeo, quedó a la vista el problema que tapaba: **"Viajes en turno" es una sola lista
indiferenciada**. Los envíos que todavía no tienen conductor y los que ya van en camino se mezclan,
ordenados solo por horario, y la única forma de distinguirlos es leer la línea pequeña del pie de
cada tarjeta ("Buscando conductor" contra "En camino · Polo Panterea").

Eso invierte la prioridad de la operación. Lo que un despachador necesita atender es justamente lo
que **nadie tomó todavía**: un envío sin conductor es trabajo suyo, uno en curso es trabajo de otro.
Con las dos cosas revueltas, la pila de pendientes no se ve como pila; hay que reconstruirla con la
vista cada vez.

La 1.1 parte el panel en dos secciones —**"Viajes pendientes"** y **"Viajes en curso"**— y hace que
un envío cruce de la primera a la segunda **solo, en el acto, sin recargar**, en el momento en que un
conductor lo toma. Es la misma promesa de la 1.0 llevada a la estructura de la lista: no se recarga,
se acomoda.

## 2. Alcance

**Incluye (1.1):** la partición de "Viajes en turno" en dos secciones derivadas del estado, el
cruce animado de una a otra por evento, los encabezados pegajosos con contador y el comportamiento
del congelador de orden ante un cambio de sección.

**Incluye (1.0):** endpoint de lista en turno filtrado en el servidor, limitador propio para las lecturas
del Panel, payloads de evento enriquecidos para que el Panel pinte sin preguntar, un store único de
Pinia como fuente de verdad compartida por los componentes del `/panel`, actualización por evento
(reducers), deduplicación por `event_id`, reconciliación coalescida solo en los cuatro casos en que
hace falta, estados de pantalla no destructivos y la capa de animación de las listas.

**No incluye:** cambios de diseño visual **de la tarjeta** ni del ítem de flotilla —manda SPEC-008
para la tarjeta de viaje y SPEC-023 para el ítem de flotilla; la 1.1 cambia cómo se **agrupan** las
tarjetas, no cómo se ven—, filtros o búsqueda en los paneles, virtualización de listas, paginación en
el Panel, cambios a `panda_express`, ni un registro persistente de eventos en base de datos
(SPEC-018 ya descartó la bitácora).

Tampoco incluye **notificaciones, sonidos ni contadores nuevos** por el hecho de que un viaje cruce
de sección: el cruce se ve, no se anuncia. Ni la capacidad de **quitarle un viaje a un conductor**
para devolverlo a pendientes; hoy el servidor no admite esa transición (§4, "El cruce solo va en un
sentido") y agregarla es una función propia, con su permiso, su aviso al conductor y su spec.

## 3. Actores y permisos

| Actor | Permisos |
|---|---|
| `admin_cliente` | Ve los dos paneles actualizándose en vivo, sobre su tenant |
| `despachador` | Igual que `admin_cliente` |
| `conductor` | No participa: no usa el Panel |
| `super_admin` | Solo lectura |

Sin cambios de autorización. La ruta nueva vive en el mismo grupo
`rol.tenant:AdminCliente,Despachador` que ya usan `/pedidos` y `/conductores/activos`.

## 4. Modelo de datos

**No hay tablas nuevas ni columnas nuevas.** Todo lo que el Panel necesita ya está en `pedidos`,
`conductores`, `conductor_estado`, `vehiculos` y `ventas_viajes_conductor`. Lo que cambia es **quién
lo manda y cuándo**.

### El estado del Panel vive en memoria, en un solo lugar

Hoy hay tres copias del mismo estado: la lista de `ServiciosEnTurno`, la de `ConductoresActivos` y el
arreglo privado `conductores` de `MapaConductores` (`MapaConductores.vue:52`). Se sustituyen por un
store de Pinia, `usePanelStore`:

```
viajes          Map<id_pedido, Viaje>          — indexado por id, no arreglo: los reducers
                                                 mutan por id sin recorrer nada
conductores     Map<id_conductor, Conductor>
eventosVistos   Set<event_id>                  — últimos 200, para deduplicar (RN-06)
conexion        'conectando' | 'vivo' | 'caido'
primeraCarga    boolean                        — el único estado que muestra skeleton
```

Los componentes se vuelven de solo lectura sobre este store. Ninguno hace `http.get` nunca más.

### Estados considerados "en turno"

`PENDIENTE`, `PUBLICADO`, `TOMADO`, `ARRIBADO`, `EN_CAMINO`, `ARRIBADO_A_ENTREGA` — los mismos seis
que hoy filtra el cliente (`ServiciosEnTurno.vue:27-34`). El complemento son los `ESTADOS_FINALES` de
`PedidoEstadoService` (`ENTREGADO`, `CANCELADO`, `RECHAZADO`). La lista pasa a definirse **una sola
vez, en el backend**, para que el filtro no pueda discrepar entre las dos capas.

### Las dos secciones (1.1)

Los seis estados en turno se reparten en dos secciones según una única pregunta: **¿este envío ya
tiene conductor?**

| Sección | Estados | Qué significa para el despachador |
|---|---|---|
| **Viajes pendientes** | `PENDIENTE`, `PUBLICADO` | Nadie lo tomó. Es trabajo suyo |
| **Viajes en curso** | `TOMADO`, `ARRIBADO`, `EN_CAMINO`, `ARRIBADO_A_ENTREGA` | Ya tiene conductor. Es trabajo de otro |

Tres consecuencias que importan más de lo que parece:

**La sección es derivada, no almacenada.** No hay columna nueva, ni campo nuevo en el evento, ni
bandera en el store: la sección **se calcula del estado del viaje, cada vez que se pinta**. Esa es la
razón por la que el cruce automático no necesita nada especial —ni servidor, ni evento propio, ni
código de sincronización—: el aviso `pedido.tomado` ya escribe `estado: TOMADO` sobre la fila
(RN-07), y con ese estado escrito la fila **ya está** en la otra sección. El cruce no se programa; es
lo que ocurre por sí solo cuando la sección se deriva en vez de guardarse.

**El servidor no cambia.** `GET /pedidos/en-turno` sigue devolviendo una sola colección con los seis
estados, y `pedido.tomado` sigue mandando `estado`, `id_conductor` y `conductor_nombre` como en la
1.0. Todo lo que agrega la 1.1 vive en la pantalla.

**El cruce solo va en un sentido.** Las transiciones de `PedidoEstadoService::TRANSICIONES` no
permiten volver de "en curso" a "pendientes": desde `TOMADO` los únicos caminos son avanzar
(`ARRIBADO`) o cancelarse, y el regreso que sí existe —`PUBLICADO → PENDIENTE`, cuando se agotan las
rondas de oferta de SPEC-020— ocurre **dentro** de pendientes, sin cruzar nada. Aun así la regla se
escribe en los dos sentidos (RN-29): al ser derivada, el día que exista una función de desasignar la
tarjeta regresará sola a pendientes sin tocar una línea de esta capa.

## 5. Endpoints y eventos

### Endpoints

| Método | Ruta | Cambio | Éxito |
|---|---|---|---|
| GET | `/t/{slug}/pedidos/en-turno` | **Nueva.** Los pedidos en turno, filtrados en SQL, sin paginar | 200 |
| GET | `/t/{slug}/conductores/activos` | Sin cambios de forma; cambia de limitador | 200 |
| GET | `/t/{slug}/pedidos` | Sin cambios. Deja de usarla el Panel; la siguen usando las vistas de listado | 200 |

`GET /t/{slug}/pedidos/en-turno` devuelve la colección completa de `PedidoResource`, ordenada como la
pinta el Panel (RN-03), más un sello de tiempo del servidor:

```json
{
  "data": [ /* PedidoResource */ ],
  "meta": { "snapshot_en": "2026-09-07T14:31:02Z" }
}
```

Sin paginación **a propósito**: son los envíos vivos de un tenant, decenas como mucho, y paginarlos
es justo el error que esta spec corrige. Respeta el scope de ambiente de SPEC-025 sin hacer nada
especial: el global scope del modelo ya lo aplica.

### Limitador de lectura del Panel

Se agrega `RateLimiter::for('tenant-panel-lectura')` con **120 por minuto y por usuario**, y las dos
rutas de lectura del Panel (`pedidos/en-turno` y `conductores/activos`) se mueven a él. El bucket de
20/min de `tenant-usuarios` se diseñó para escrituras de CRUD hechas por una persona tecleando; una
pantalla de operación que se reconcilia sola no cabe ahí. Con esta spec el Panel gasta 2 peticiones
al abrir y prácticamente ninguna después, así que 120 es techo de seguridad, no presupuesto de uso.

### Eventos: mismos nombres, carga suficiente

No se crea ningún evento nuevo y **no se renombra ninguno**: se agregan campos a los que hoy mandan
solo un id. Agregar campos es compatible hacia atrás — `panda_express` los ignora (SPEC-018, RN-09:
el destinatario ya filtra en el cliente).

| Evento | Hoy manda | Se agrega | Para qué |
|---|---|---|---|
| `pedido.creado` | *(evento nuevo)* | el `PedidoResource` del Panel completo | Es el único aviso que sale **siempre** al dar de alta un envío: inserta la fila |
| `pedido.disponible` | el `PedidoResource` de la app | `fecha_servicio`, `hora_desde`, `hora_hasta`, `lo_antes_posible`, `id_conductor`, `conductor_nombre`, `ambiente` | La forma que consume `panda_express` no trae agenda ni ambiente: sin ellos la fila se pinta sin fecha, se ordena mal y un envío TEST se cuela en un Panel LIVE |
| `pedido.tomado` | `id_pedido` | `estado`, `id_conductor`, `conductor_nombre`, `saldo_viajes`, `seguimiento` | Mueve el viaje a `TOMADO` y pone al conductor en "Ocupado" con su saldo ya descontado |
| `pedido.estado-cambiado` | `id_pedido`, `id_conductor`, `estado` | `seguimiento` | El mapa cambia de hito sin volver a pedir `/conductores/activos` |
| `pedido.entregado` | `id_pedido` | `id_conductor`, `saldo_viajes` | Saca el viaje y libera al conductor |
| `pedido.cancelado` | `id_pedido`, `cancelado_por`, `motivo`, … | `id_conductor`, `saldo_viajes` | Igual que entregado |
| `pedido.requiere-asignacion-manual` | `id_pedido` | `estado` | Devuelve el viaje a `PENDIENTE` en la lista |
| `conductor.disponibilidad-cambiada` | `id_conductor`, `disponibilidad` | `conductor` — el `ConductorActivoResource` completo, o `null` al desconectarse | Insertar a alguien que se conecta exige su nombre, placa, saldo, color y posición: sin esto es obligatorio recargar |
| `saldo.acreditado` | `id_conductor`, `viajes_acreditados` | `saldo_viajes` | El saldo resultante, no el delta: el Panel no tiene que sumar y no puede desincronizarse |
| `ubicacion.actualizada` | ya suficiente | — | Ya se aplica en memoria hoy (`MapaConductores.vue:142`) |

**Enmienda (posterior a la implementación).** Este apartado daba por hecho que `pedido.disponible`
bastaba para que una fila nueva apareciera sola, y no bastaba por dos razones. La primera es que ese
evento solo sale cuando el envío se **publica** y además hay algún conductor elegible en ese
instante (`OfertaPedidoService::ofertar`): un envío agendado —que nace `PENDIENTE` y no se publica
hasta 15 minutos antes de su horario, SPEC-024— o uno creado con la flotilla desconectada no
producía ninguno, y el despachador tenía que recargar la página para ver la entrega que él mismo
acababa de dar de alta. De ahí `pedido.creado`, que se dispara en el alta pase lo que pase. La
segunda es que `pedido.disponible` no manda el `PedidoResource` del Panel sino el de la app, que no
tiene agenda ni `ambiente`; por eso se le agregan esos campos.

`saldo_viajes` es el mismo número que ya calcula `ConductorActivoResource` (vendidos menos
consumidos, SPEC-023 §"`saldo_viajes` se calcula en el índice"): se extrae a un helper reutilizable
para que el evento y el resource no puedan dar cifras distintas.

**Enmienda 2 — cuándo sale el aviso (regresión corregida).** Que la carga se arme releyendo el
pedido (`DatosDeEventoPanel`) obliga a que el aviso salga **con la fila ya escrita**, y no salía:
`PedidoEstadoService::transicionar()` no guarda —deja que el llamador decida cuándo, para que pueda
envolverlo en su transacción y su auditoría— pero dispara los eventos al final de sí mismo, es
decir **antes** del `save()` de todos sus llamadores. El resultado era que cada evento del Panel
llevaba el estado anterior. En el caso peor, aceptar un viaje: `pedido.tomado` salía con
`estado: PUBLICADO`, `id_conductor: null` y, por tanto, `seguimiento: null` —el reductor
`ocuparConductor` se salía en la primera línea al no venir conductor, el mapa no llegaba a tener
`pedido_asignado` y la línea en guiones del tramo H1 no se dibujaba nunca. El marcador sí se movía,
porque `ubicacion.actualizada` no depende de esto.

Los tests no lo vieron porque construían la carga a mano (`(new PedidoYaTomado($id, $slug))->broadcastWith()`)
sobre un pedido ya persistido: comprobaban que la carga es correcta *dado* un renglón guardado, que
es precisamente la condición que en producción no se cumplía.

Corrección: los avisos cuya carga se relee —`pedido.estado-cambiado`, `pedido.tomado`,
`pedido.entregado` y `pedido.cancelado`— se encolan en `Pedido::$avisosDiferidos` en vez de
dispararse, y `PedidoObserver::saved()` los suelta. Como el observador ya es `afterCommit` (SPEC-026,
RN-02), salen también después de confirmar la transacción cuando el llamador abrió una, y no salen
en absoluto si la transición nunca llega a guardarse. Fuera del mecanismo se queda `ofertar()`
(PUBLICADO): sus eventos llevan el modelo en memoria, no un id que haya que releer, y
`Tenant\PedidoController::publicar()` depende de que las ofertas existan antes de persistir.

## 6. Reglas de negocio

### Origen de los datos

- **RN-01:** El Panel hace **dos** peticiones de lista al abrirse —`pedidos/en-turno` y
  `conductores/activos`— y ninguna más mientras el socket esté vivo. Toda actualización posterior
  entra por el canal.
- **RN-02:** Ningún componente del `/panel` llama al backend por su cuenta. `ServiciosEnTurno`,
  `ConductoresActivos`, `MapaConductores` y `DetalleEnvioPanel` leen del store. Tres componentes
  pidiendo lo mismo a la vez es la causa raíz del 429 y deja de ser posible por construcción.
- **RN-03:** El filtro de estados en turno y el orden viven en el servidor. El orden es el de hoy:
  primero los `lo_antes_posible`, después los agendados por `hora_desde` ascendente.
- **RN-04:** Solo el store se suscribe al canal. Los componentes no hacen `bind` de nada.

### Actualización por evento

- **RN-05:** Todo evento se aplica **en memoria, sobre la fila afectada, sin pedir nada**. Un evento
  no dispara peticiones. Las únicas excepciones son los cuatro casos de RN-11 y el de RN-11e.
- **RN-06:** Se deduplica por `event_id` (SPEC-018 ya lo manda en todos los eventos). Se recuerdan
  los últimos 200; un `event_id` repetido se ignora en silencio. El mismo aviso puede llegar dos
  veces, por socket y por push.
- **RN-07:** Reducers, uno por evento:

  | Evento | Efecto en `viajes` | Efecto en `conductores` |
  |---|---|---|
  | `pedido.creado` | Inserta o reemplaza la fila | — |
  | `pedido.disponible` | Inserta o reemplaza la fila | — |
  | `pedido.tomado` | `estado = TOMADO`, asigna conductor | Marca "Ocupado", fija `saldo_viajes` y `pedido_asignado` |
  | `pedido.estado-cambiado` | Cambia `estado`; si es final, quita la fila | Actualiza `seguimiento` del pedido asignado |
  | `pedido.entregado` | Quita la fila | Marca "Disponible", fija `saldo_viajes`, limpia `pedido_asignado` |
  | `pedido.cancelado` | Quita la fila | Igual que entregado |
  | `pedido.requiere-asignacion-manual` | `estado = PENDIENTE`, sin conductor | Libera al conductor si lo tenía |
  | `conductor.disponibilidad-cambiada` | — | `DISPONIBLE` inserta desde `payload.conductor`; `FUERA_DE_SERVICIO` quita la fila |
  | `saldo.acreditado` | — | Fija `saldo_viajes` con el valor recibido |
  | `ubicacion.actualizada` | — | Mueve el marcador; no toca la lista. Conductor desconocido: una sola reconciliación (RN-11e) |

- **RN-08:** Un evento sobre una fila que no está en memoria **no es un error**. Si debería estar
  (por ejemplo un `pedido.tomado` de un id desconocido), se agenda una reconciliación diferida
  (RN-11); si es una baja de algo que ya no está, se ignora.
- **RN-09:** El Panel nunca inventa un estado que el servidor no mandó. Los reducers solo escriben
  campos que vienen en la carga del evento; lo demás se queda como estaba.
- **RN-10:** Ante conflicto **gana el servidor**: lo que llegue de una reconciliación reemplaza lo
  aplicado por evento.

### Reconciliación

- **RN-11:** Se vuelve a pedir la lista completa **solo** en cuatro casos: (a) al abrir el Panel,
  (b) al reconectar el socket tras una caída, (c) al volver la pestaña al foco después de más de 60 s
  oculta, (d) al llegar un evento sobre una fila desconocida que debería existir.
- **RN-11e:** `ubicacion.actualizada` también entra en el caso (d) —un conductor que se mueve pero
  que el Panel no tiene es una desincronización, no un evento que sobre—, pero **se pregunta una sola
  vez por conductor**. Es el evento de mayor frecuencia del sistema: pedir la lista en cada posición
  sería una petición cada 15 s, indefinidamente, cuando el conductor legítimamente no es de este
  Panel (otro ambiente, o ya salió de turno). Si la reconciliación lo trae, queda en la lista y el
  contador de ese conductor se olvida; si no lo trae, es que no es nuestro y no se vuelve a preguntar.
  Sin esto, un conductor ausente de la lista se quedaba invisible en el mapa para siempre: sus
  posiciones se descartaban en silencio y nada forzaba la recuperación.
- **RN-12:** Las reconciliaciones se agrupan: debounce de 500 ms y nunca dos en vuelo a la vez. Diez
  disparos seguidos son una sola petición.
- **RN-13:** Una reconciliación **fusiona por id**: actualiza las filas que siguen, agrega las nuevas
  y quita las ausentes. No se vacía la lista para volver a llenarla; sin esto la reconciliación
  traería de vuelta el parpadeo que esta spec elimina.
- **RN-14:** Mientras el socket esté caído, y solo mientras lo esté, se sondea cada 30 s como red de
  seguridad. Al reconectar, el sondeo se apaga. Con el socket vivo no hay sondeo de ningún tipo.
- **RN-15:** Se elimina el `AbortController` que cancela la recarga anterior (SPEC-012). Con una sola
  petición coalescida no hay carrera que cortar, y abortar era lo que dejaba la lista colgada en
  "Cargando" durante las ráfagas.

### Estados de pantalla

- **RN-16:** El skeleton se muestra **solo** en la primera carga de la sesión del Panel. Después,
  nunca.
- **RN-17:** Una vez que hay datos, la lista **no se vacía jamás**: ni durante una reconciliación, ni
  ante un error, ni al perder el socket. Se muestran los últimos datos buenos.
- **RN-18:** El error deja de ser destructivo. En vez de sustituir la lista por "No se pudo cargar" +
  "Reintentar", aparece una franja discreta arriba del contenido ("Sin conexión — reconectando…") y
  la lista sigue en pantalla, atenuada. Se conserva una acción manual de actualizar dentro de esa
  franja, pero deja de ser la única salida.
- **RN-19:** El vacío real ("No hay viajes en turno" / "No hay conductores activos") se muestra solo
  cuando el servidor confirmó cero, nunca como consecuencia de un fallo.
- **RN-20:** El estado de conexión es visible sin ser ruidoso: un punto junto al título de cada panel
  — vivo, reconectando, caído. La operación tiene derecho a saber si lo que ve es de hace un segundo
  o de hace diez minutos.

### Capa visual

- **RN-21:** Las dos listas se renderizan con `<TransitionGroup>` con clave estable (`id_pedido` /
  `id_conductor`): entrada de 200 ms con desvanecido y desplazamiento corto, salida de 200 ms, y
  reordenamiento animado de 300 ms (FLIP). En "Viajes en turno" ese `<TransitionGroup>` es **uno
  solo para las dos secciones**, con los encabezados dentro como elementos propios (RN-31).
- **RN-22:** La fila que cambió se resalta 600 ms con un fondo suave que se desvanece. Es la única
  señal de "esto acaba de cambiar" que reemplaza al parpadeo de la recarga completa.
- **RN-23:** Un viaje que se entrega no desaparece de golpe: se marca como entregado y sale 800 ms
  después. Que una fila se evapore justo cuando alguien iba a tocarla es peor que esperar un
  instante.
- **RN-24:** El contador "N en línea" transiciona entre valores en vez de saltar.
- **RN-25:** Mientras el puntero esté encima de una lista, los **reordenamientos** se difieren hasta
  3 s (los cambios de contenido no: esos se aplican siempre). Nadie debe perder el clic porque la
  fila se movió bajo el dedo. Desde la 1.1 eso incluye los **cambios de sección** (RN-34): lo que se
  congela no es solo la posición, es el sitio.
- **RN-26:** El viaje abierto en `DetalleEnvioPanel` nunca se cierra solo por un evento. Si se
  entrega o se cancela, el detalle lo refleja y deja que la persona lo cierre.
- **RN-27:** Con `prefers-reduced-motion` activo no hay desplazamientos ni FLIP: los cambios se
  aplican al instante y el resaltado se reduce a un cambio de fondo sin transición.

### Las dos secciones de "Viajes en turno" (1.1)

- **RN-29:** La sección de un viaje **se deriva de su estado**, en el momento de pintarlo, y nunca se
  guarda: `PENDIENTE` y `PUBLICADO` van a "Viajes pendientes"; `TOMADO`, `ARRIBADO`, `EN_CAMINO` y
  `ARRIBADO_A_ENTREGA` van a "Viajes en curso". La regla vale en los dos sentidos: si un viaje
  volviera a un estado sin conductor, volvería a pendientes por el mismo camino. Los estados finales
  no están en ninguna de las dos: salen del panel (RN-07, RN-23).
- **RN-30:** El corte lo hace **la pantalla**, no el store. `usePanelStore` sigue exponiendo una sola
  `viajesOrdenados` (RN-03) y `ServiciosEnTurno.vue` la parte al renderizar. El store no gana ninguna
  lectura nueva y ningún otro componente cambia.
- **RN-31:** Las dos secciones son **una sola lista** en el DOM: un `<TransitionGroup>` cuyos
  elementos son los dos encabezados y las tarjetas, en orden. No son dos listas hermanas. La razón es
  la del cruce: `<TransitionGroup>` sabe **deslizar** un elemento que cambia de posición dentro de sí
  mismo (FLIP, RN-21), pero entre dos listas distintas no ve un elemento que se movió sino uno que se
  fue y otro que llegó. Con los encabezados adentro, el viaje que cruza se desliza de verdad desde
  arriba del separador hasta su sitio en "Viajes en curso", con la animación que ya existe y sin
  código de medición a mano.
- **RN-32:** Cada encabezado muestra su título y **su contador** ("Viajes pendientes · 3"), y ambos
  están **siempre presentes**, incluso con la sección vacía. Una sección vacía muestra su propio
  mensaje debajo del título ("No hay viajes pendientes" / "No hay viajes en curso"), no el vacío
  global de RN-19: que la sección desaparezca borra la referencia de dónde va a aparecer lo próximo.
  El vacío global ("No hay viajes en turno") queda para el único caso en que las dos están vacías, y
  sigue sujeto a RN-19: solo cuando el servidor confirmó cero, nunca por un fallo.
- **RN-33:** Los encabezados son **pegajosos** dentro del scroll de la lista: el título de la sección
  que se está recorriendo se queda fijo arriba y el siguiente lo empuja al llegar. Quedan
  **excluidos del reacomodo animado** de RN-21 —el FLIP recalcula la posición de todos los elementos
  de la lista, y a un elemento pegajoso ese empujón momentáneo le provoca un parpadeo—. Las tarjetas
  se deslizan; los encabezados se quedan quietos.
- **RN-34:** El congelado de RN-25 retiene también la **pertenencia a la sección**. Al congelar se
  guarda, por viaje, su sección y su posición; mientras dure, un viaje que cambió de estado se sigue
  mostrando donde estaba, con su contenido ya actualizado. Sale de ahí al retirar el puntero o al
  vencer el tope de 3 s. El precio, aceptado a conciencia: durante esos segundos una tarjeta puede
  decir "Asignado · Pedro Lamas" bajo el título "Viajes pendientes". Se prefiere una contradicción de
  tres segundos a que la tarjeta que alguien iba a tocar se vaya de debajo del cursor, que es
  exactamente lo que RN-25 existe para impedir.
- **RN-35:** El viaje que cruza se resalta en su nueva sección con el mismo destello de RN-22, y la
  **selección sobrevive al cruce**: si el viaje que cruzó era el abierto en `DetalleEnvioPanel` o el
  dibujado en el mapa, sigue seleccionado después de moverse (coherente con RN-26). Solo hay un viaje
  seleccionado a la vez entre las dos secciones.
- **RN-36:** Los encabezados son **títulos reales** (`<h3>` bajo el `<h2>` "Viajes en turno") y
  llevan la cantidad **en su propio texto**, para que un lector de pantalla pueda saltar de sección a
  sección y oiga "Viajes pendientes, 3" al llegar a cada una.

  No se marcan como dos listas: RN-31 las unió en un solo contenedor animado y ahí no caben dos
  `<ul>`. Se probó a dejar el contenedor como lista con los encabezados intercalados y es peor —el
  lector anuncia una sola lista de N elementos y los títulos cuentan como ítems—, así que el recuento
  viaja en el título, que es donde de todos modos lo iba a buscar quien navega por encabezados.

  **No se anuncia el cruce por voz** (`aria-live`): en una operación con movimiento, un aviso hablado
  cada pocos segundos interrumpe sin parar justo a quien depende del lector. La información queda
  disponible cuando se la pide, no impuesta.
- **RN-37:** Dentro de cada sección el orden es el de RN-03 —primero los `lo_antes_posible`, después
  por `hora_desde`, y a igualdad el `id_pedido` mayor primero—. La partición **no reordena nada**:
  toma la lista ya ordenada y la corta en dos.

### Tenencia y ambiente

- **RN-28:** Todo lo anterior respeta el ambiente sellado de SPEC-025. El snapshot y los eventos
  hablan del mismo ambiente; el Panel no filtra por ambiente en el cliente.

## 7. Errores

No se agregan códigos de error. Cambia **cómo se manejan** los que ya existen:

| Situación | HTTP | Comportamiento nuevo |
|---|---|---|
| Límite de peticiones excedido | 429 | Se conservan los datos en pantalla; franja de reconexión; reintento con espera creciente (2 s, 4 s, 8 s, tope 30 s). Con RN-01 y el limitador propio deja de ser alcanzable en operación normal |
| Fallo de red o 5xx en la reconciliación | 5xx | Igual que el 429: nunca vacía la lista |
| Sesión expirada | 401 | Único caso que sí interrumpe: redirige al login, como hoy |
| Socket caído | — | `conexion = 'caido'`, sondeo de respaldo de RN-14, franja visible |

Un fallo de reconciliación no le devuelve error a nadie: no hay una persona esperándolo. Se registra
en consola y se reintenta.

## 8. Criterios de aceptación

- [ ] Publicar un envío nuevo lo agrega a "Viajes en turno" sin que la lista parpadee ni muestre
      "Cargando...".
- [ ] Que un conductor tome ese envío cambia la fila a `TOMADO` y pone al conductor en "Ocupado" con
      su saldo ya descontado, sin ninguna petición al backend (verificable en la pestaña Red).
- [ ] Un viaje completo —publicado, tomado, arribado, en camino, entregado— genera **cero**
      peticiones de lista después de la carga inicial.
- [ ] Con dos viajes tomándose y entregándose a la vez, ningún panel muestra "No se pudo cargar".
- [ ] Diez eventos en dos segundos no producen ninguna petición ni ningún parpadeo.
- [ ] Al entregar, la fila se marca como entregada y sale animada; no se evapora de golpe.
- [ ] Un conductor que se conecta aparece en la flotilla con nombre, saldo, vehículo y color
      correctos sin recargar la lista.
- [ ] Un conductor que se desconecta sale de la lista y el contador baja con transición.
- [ ] Vender viajes a un conductor conectado sube su saldo en la fila, sin tocar el resto.
- [ ] Con Reverb apagado, el Panel abre normalmente, muestra "sin conexión" y se refresca cada 30 s
      sin vaciarse.
- [ ] Al volver Reverb, el Panel se reconcilia una vez, el sondeo se apaga y la lista no parpadea.
- [ ] Cortar la red durante un minuto y restaurarla deja la lista idéntica a la del servidor.
- [ ] Un `event_id` repetido no duplica filas ni las quita dos veces.
- [ ] Un conductor en movimiento que falta en la lista provoca **una** reconciliación y aparece en el
      mapa; si la reconciliación no lo trae, sus siguientes posiciones no generan ninguna petición
      más (RN-11e).
- [ ] Dejar la pestaña en segundo plano cinco minutos y volver reconstruye el estado con una sola
      petición.
- [ ] `GET /t/{slug}/pedidos/en-turno` devuelve solo los seis estados en turno y no pagina.
- [ ] Un tenant con 200 pedidos históricos y 3 en turno abre el Panel con una respuesta de 3
      elementos.
- [ ] Con el ambiente en TEST, el Panel solo ve envíos TEST, igual que hoy.
- [ ] Con `prefers-reduced-motion`, ninguna fila se desplaza ni se reordena animadamente.
- [ ] Mantener el puntero sobre la lista mientras llega un reordenamiento no mueve la fila bajo el
      cursor.
- [ ] El detalle de un envío abierto no se cierra solo cuando ese envío se entrega.

### Las dos secciones (1.1)

Se verifican **a mano**: el proyecto no tiene hoy marco de pruebas del navegador, y montarlo es una
decisión propia que no se cuela en esta spec (§9, adición 19).

- [ ] Un envío recién dado de alta aparece bajo "Viajes pendientes", nunca bajo "Viajes en curso".
- [ ] Que un conductor lo tome desde la app mueve la tarjeta a "Viajes en curso" **sola**, sin
      recargar la página y sin ninguna petición al backend (verificable en la pestaña Red).
- [ ] La tarjeta que cruza se **desliza** entre secciones; no desaparece de un lado para aparecer del
      otro de golpe.
- [ ] Los dos contadores de los encabezados cambian en el mismo momento del cruce.
- [ ] Con la sección de pendientes vacía, el encabezado "Viajes pendientes · 0" sigue visible con su
      mensaje propio debajo.
- [ ] Con las dos secciones vacías se muestra el vacío global, y solo cuando el servidor confirmó
      cero (RN-19).
- [ ] Al bajar el scroll con muchos pendientes, el encabezado de la sección se queda fijo arriba y el
      siguiente lo empuja al llegar.
- [ ] Un cruce mientras el encabezado está pegado arriba no le provoca ningún parpadeo.
- [ ] Con el puntero encima de la lista, un viaje que se toma **no** cambia de sección; el contenido
      de su tarjeta sí se actualiza. Al retirar el puntero, cruza.
- [ ] El viaje seleccionado sigue seleccionado —y el mapa sigue dibujándolo— después de cruzar.
- [ ] Con `prefers-reduced-motion`, el cruce ocurre sin desplazamiento (RN-27).
- [ ] Un lector de pantalla anuncia los dos encabezados como títulos, con su cantidad, y no
      interrumpe cuando un viaje cruza.
- [ ] Un viaje que se entrega se despide desde "Viajes en curso" (RN-23) y no reaparece en
      pendientes.

## 9. Adiciones técnicas aceptadas

| # | Adición | Dónde vive |
|---|---|---|
| 1 | Endpoint de lista en turno filtrado en SQL, sin paginar | `Tenant\PedidoController@enTurno` + ruta nueva |
| 2 | Limitador propio para las lecturas del Panel (120/min) | `RateLimiter::for('tenant-panel-lectura')` en `AppServiceProvider` |
| 3 | Payloads de evento con lo suficiente para pintar sin preguntar | `broadcastWith()` de los siete eventos de §5 |
| 4 | Un solo lugar que arma la carga de esos eventos | `App\Support\DatosDeEventoPanel` |
| 5 | Saldo de viajes calculado en un solo lugar | Helper `App\Support\SaldoViajes`, usado por el resource, por el controlador de ventas y por los eventos |
| 6 | Una sola consulta que define la fila de la flotilla | `Conductor::scopeConDatosDePanel`, compartido por `GET /conductores/activos` y por el evento de conexión |
| 7 | Store único de Pinia como fuente de verdad del Panel | `frontend/src/stores/panel.ts` |
| 8 | Suscripción única al canal, con reducers por evento | El propio store; los componentes dejan de hacer `bind` |
| 9 | Deduplicación por `event_id` con ventana de 200 | `eventosVistos` en el store |
| 10 | Reconciliación coalescida con debounce y sin concurrencia | Acción `sincronizar()` del store |
| 11 | Estado del socket observable | `realtimeService.alCambiarEstado()`, que antes no exponía nada |
| 12 | Sondeo de respaldo solo con el socket caído | Reacción a `conexion` dentro del store |
| 13 | Listas animadas con FLIP y resaltado de cambio | `<TransitionGroup>` en los dos paneles |
| 14 | Orden congelado bajo el puntero | `frontend/src/composables/useOrdenEstable.ts` |
| 15 | Estado de error no destructivo | `UiEstadoConexion.vue`, compartido por los dos paneles |
| 16 | Partición por estado hecha en la pantalla, no en el store (RN-30) | `ServiciosEnTurno.vue` |
| 17 | Un solo `<TransitionGroup>` con los encabezados como elementos, para que el cruce sea un movimiento y no un alta más una baja (RN-31) | `ServiciosEnTurno.vue` |
| 18 | El orden congelado retiene también la sección (RN-34) | `useOrdenEstable.ts`, ampliado con la sección de cada elemento |
| 19 | Verificación manual en vez de pruebas automáticas de pantalla | §8. El navegador no tiene hoy marco de pruebas; montarlo es una decisión aparte, no un efecto colateral de esta función |

## 10. Impacto en el código existente

**Backend**

- `app/Http/Controllers/Tenant/PedidoController.php` — método `enTurno()`.
- `routes/api.php` — ruta `GET /pedidos/en-turno`; las dos rutas de lectura del Panel pasan a
  `throttle:tenant-panel-lectura`.
- `app/Providers/AppServiceProvider.php` — limitador nuevo.
- `app/Services/PedidoEstadoService.php` — constante `ESTADOS_EN_TURNO`, complemento exacto de
  `ESTADOS_FINALES`. El filtro deja de vivir duplicado en el navegador.
- `app/Support/SaldoViajes.php` y `app/Support/DatosDeEventoPanel.php` — helpers nuevos.
- `app/Models/Tenant/Conductor.php` — scope `conDatosDePanel`.
- `app/Events/Tenant/PedidoYaTomado.php`, `PedidoEntregado.php`, `PedidoCanceladoParaConductor.php`,
  `PedidoEstadoCambiado.php`, `PedidoRequiereAsignacionManual.php`,
  `ConductorDisponibilidadCambiada.php`, `SaldoAcreditado.php` — cargas enriquecidas.

  Los **constructores no cambian**: siguen recibiendo ids y `broadcastWith()` resuelve el resto
  contra la base. Es lo contrario de lo que planteaba el borrador (pasarles el modelo), y se decidió
  así por dos razones: los eventos son `ShouldBroadcastNow`, así que la consulta corre en la misma
  petición y con la tenencia viva; y cambiar las firmas obligaba a tocar los seis puntos que los
  disparan y las pruebas que leen `$event->idPedido`, sin ganar nada a cambio.

  El precio de esa decisión —que hay que despachar con la fila ya escrita— no se pagó al
  implementarla y produjo la regresión descrita en la Enmienda 2 de §5. El mecanismo que lo cierra
  es `Pedido::$avisosDiferidos` + `PedidoObserver::saved()`.

  Esas consultas van `withoutGlobalScopes()` porque un evento nace lo mismo en una petición del
  Panel —con `AmbienteScope` encendido— que en una de la app del conductor, donde no lo está; sin
  quitarlo, la misma carga saldría completa o vacía según quién la disparó. El filtro por ambiente
  lo hace el Panel con el campo `ambiente` que viaja en la carga (RN-28).
- `app/Http/Resources/Tenant/ConductorActivoResource.php` y
  `app/Http/Controllers/Tenant/VentaViajeConductorController.php` — usan el helper de saldo; sin
  cambios de forma.
- `app/Models/Tenant/Pedido.php` — propiedad `$avisosDiferidos` (Enmienda 2 de §5).
- `app/Observers/PedidoObserver.php` — `saved()` suelta los avisos diferidos antes de su tarea de
  SPEC-026; deja de ser un observador de un solo asunto.
- `tests/Feature/Tenant/PanelReactivoTest.php` — endpoint, orden, límite de lectura, las cargas de
  los cuatro eventos que más cambian y la regresión de la Enmienda 2: el aviso no sale antes del
  `save()` y, cuando sale, trae `TOMADO` con su tramo H1.

**Panel (`frontend`)**

- `src/stores/panel.ts` — **nuevo**: estado, reducers, suscripción, reconciliación.
- `src/components/panel/ServiciosEnTurno.vue` — deja de pedir datos, de paginar, de abortar y de
  suscribirse; lee del store y anima la lista. Se retira `defineExpose({ recargar })`. **1.1:** parte
  la lista en las dos secciones (RN-29, RN-30) y las pinta como un solo `<TransitionGroup>` con los
  encabezados dentro (RN-31), pegajosos y fuera del reacomodo (RN-33), con contador y vacío propios
  (RN-32) y marcados semánticamente (RN-36).
- `src/components/panel/ConductoresActivos.vue` — igual. Conserva el colapso y los toasts de
  SPEC-023.
- `src/components/panel/MapaConductores.vue` — deja de pedir `/conductores/activos` y de escuchar
  cinco eventos; lee el mismo store y sigue dibujando con `seguimiento` (SPEC-026).
- `src/components/panel/DetalleEnvioPanel.vue` — lee el viaje del store para reflejar cambios de
  estado en vivo (RN-26).
- `src/views/tenant/panel/PanelView.vue` — inicia y detiene el store; el manejador de `@agendado`
  deja de llamar a `recargar()` (el envío entra solo por `pedido.disponible`).
- `src/services/realtime.ts` — expone el estado del socket (`alCambiarEstado`), que antes solo
  guardaba puertas adentro.
- `src/composables/useOrdenEstable.ts` — **nuevo** (RN-25). **1.1:** la instantánea que toma al
  congelar deja de ser solo el orden y pasa a ser *sección + posición*, para que un viaje no cruce
  bajo el puntero (RN-34). Como el área que dispara el congelado es el contenedor con scroll —una
  sola, compartida por las dos secciones desde que RN-31 las unió en una lista—, congelar congela de
  hecho las dos a la vez; no hay forma observable de congelar una sola, y tampoco haría falta.
- `src/components/ui/UiEstadoConexion.vue` — **nuevo**: el punto del encabezado y la franja de
  reconexión, los dos en el mismo componente porque son la misma idea a dos volúmenes.
- `src/composables/useRealtime.ts` — **eliminado**. Solo servía para dejar la conexión abierta desde
  `PanelView` (spec tenant/018) y se quedó sin consumidores: ahora el store se suscribe él mismo.

**Sin cambios por la 1.1**

- **Backend:** ninguno. La sección se deriva del estado que los eventos ya mandan desde la 1.0 (§4,
  "El servidor no cambia").
- `src/stores/panel.ts` — ninguno. Sigue exponiendo una sola `viajesOrdenados` (RN-30).
- `DetalleEnvioPanel.vue`, `ConductoresActivos.vue`, `MapaConductores.vue` — ninguno.

**App del conductor (`panda_express`)**

- Sin cambios. Los campos agregados a los eventos se ignoran (SPEC-018, RN-09).

## 11. Riesgos

- **Los reducers pueden divergir del servidor.** Mitigado por RN-10 (gana el servidor), RN-11 (b) y
  (d) y el sondeo de respaldo. La divergencia se corrige sola; no queda pegada hasta que alguien
  recargue.
- **Enriquecer eventos amplía lo que viaja por el canal.** Son cargas de decenas de campos, no de
  megabytes, y sustituyen peticiones enteras. El canal es privado por tenant y no se agrega ningún
  dato que el Panel no pudiera pedir por HTTP.
- **Un evento perdido deja una fila desactualizada** hasta la siguiente reconciliación. Es el mismo
  riesgo que hoy, y RN-11 lo acota mejor que el comportamiento actual, que solo se corregía si
  alguien recargaba la página. Con la 1.1 ese retraso se vuelve **visible**: una fila desactualizada
  ya no solo dice el estado viejo, está en la sección equivocada. Es un empeoramiento aparente y una
  mejora real —el error deja de pasar inadvertido— y se corrige por el mismo camino de RN-10 y RN-11.
- **La tarjeta contradictoria de RN-34.** Hasta 3 s mostrando "Asignado · Pedro Lamas" bajo "Viajes
  pendientes" mientras el puntero está encima. Decisión consciente: proteger el clic pesa más que la
  coherencia momentánea, y el tope de 3 s la acota. Si en operación resulta molesta, el ajuste es un
  número —bajar el tope solo para el cambio de sección— y no un rediseño.
- **Los encabezados dentro del `<TransitionGroup>` (RN-31).** Meter elementos que no son tarjetas en
  una lista animada es lo que hace posible el cruce, pero también expone los encabezados al FLIP y a
  su parpadeo con `position: sticky`. RN-33 los excluye explícitamente del reacomodo; si aun así
  apareciera algún artefacto visual, la salida es sacarlos de la animación por completo, no volver a
  dos listas: eso devolvería el problema del cruce.
- **Nada de esto está cubierto por pruebas automáticas** (§9, adición 19). El navegador no tiene marco
  de pruebas y esta función es 100 % de navegador, así que la red de seguridad es la lista de
  verificación manual de §8. Es el riesgo aceptado a cambio de no meter la instalación de un sistema
  de pruebas dentro de una función de dos secciones.
