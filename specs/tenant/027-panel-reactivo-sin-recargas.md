# SPEC-027 — Panel reactivo: actualización por evento, sin recargas

**Metadatos**

| Campo | Valor |
|---|---|
| ID | SPEC-027 |
| Módulo | Panel / Tiempo real |
| Autor | A. Rivas |
| Versión | 1.0 |
| Estado | Borrador |
| Sprint | S-12 |
| Depende de | SPEC-012 (Datos reales servicios en turno), SPEC-014 (Datos reales conductores activos), SPEC-018 (Protocolo realtime), SPEC-023 (Rediseño panel flotilla), SPEC-024 (Publicación de envíos), SPEC-026 (Cola y polilínea) |
| Habilita a | — |
| Reemplaza | SPEC-012 §carga de datos, SPEC-014 §"sin tiempo real", SPEC-023 §"Qué eventos recarga el panel", SPEC-024 §"El Panel escucha el canal que ya tenía abierto" |

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

## 2. Alcance

**Incluye:** endpoint de lista en turno filtrado en el servidor, limitador propio para las lecturas
del Panel, payloads de evento enriquecidos para que el Panel pinte sin preguntar, un store único de
Pinia como fuente de verdad compartida por los componentes del `/panel`, actualización por evento
(reducers), deduplicación por `event_id`, reconciliación coalescida solo en los cuatro casos en que
hace falta, estados de pantalla no destructivos y la capa de animación de las listas.

**No incluye:** cambios de diseño visual de las tarjetas o de los ítems (manda SPEC-008 para la
tarjeta de viaje y SPEC-023 para el ítem de flotilla), filtros o búsqueda en los paneles,
virtualización de listas, paginación en el Panel, cambios a `panda_express`, ni un registro
persistente de eventos en base de datos (SPEC-018 ya descartó la bitácora).

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
| `pedido.disponible` | `PedidoResource` completo | — | Ya alcanza: inserta la fila tal cual |
| `pedido.tomado` | `id_pedido` | `estado`, `id_conductor`, `conductor_nombre`, `saldo_viajes`, `seguimiento` | Mueve el viaje a `TOMADO` y pone al conductor en "Ocupado" con su saldo ya descontado |
| `pedido.estado-cambiado` | `id_pedido`, `id_conductor`, `estado` | `seguimiento` | El mapa cambia de hito sin volver a pedir `/conductores/activos` |
| `pedido.entregado` | `id_pedido` | `id_conductor`, `saldo_viajes` | Saca el viaje y libera al conductor |
| `pedido.cancelado` | `id_pedido`, `cancelado_por`, `motivo`, … | `id_conductor`, `saldo_viajes` | Igual que entregado |
| `pedido.requiere-asignacion-manual` | `id_pedido` | `estado` | Devuelve el viaje a `PENDIENTE` en la lista |
| `conductor.disponibilidad-cambiada` | `id_conductor`, `disponibilidad` | `conductor` — el `ConductorActivoResource` completo, o `null` al desconectarse | Insertar a alguien que se conecta exige su nombre, placa, saldo, color y posición: sin esto es obligatorio recargar |
| `saldo.acreditado` | `id_conductor`, `viajes_acreditados` | `saldo_viajes` | El saldo resultante, no el delta: el Panel no tiene que sumar y no puede desincronizarse |
| `ubicacion.actualizada` | ya suficiente | — | Ya se aplica en memoria hoy (`MapaConductores.vue:142`) |

`saldo_viajes` es el mismo número que ya calcula `ConductorActivoResource` (vendidos menos
consumidos, SPEC-023 §"`saldo_viajes` se calcula en el índice"): se extrae a un helper reutilizable
para que el evento y el resource no puedan dar cifras distintas.

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
  no dispara peticiones. Las únicas excepciones son los cuatro casos de RN-11.
- **RN-06:** Se deduplica por `event_id` (SPEC-018 ya lo manda en todos los eventos). Se recuerdan
  los últimos 200; un `event_id` repetido se ignora en silencio. El mismo aviso puede llegar dos
  veces, por socket y por push.
- **RN-07:** Reducers, uno por evento:

  | Evento | Efecto en `viajes` | Efecto en `conductores` |
  |---|---|---|
  | `pedido.disponible` | Inserta o reemplaza la fila | — |
  | `pedido.tomado` | `estado = TOMADO`, asigna conductor | Marca "Ocupado", fija `saldo_viajes` y `pedido_asignado` |
  | `pedido.estado-cambiado` | Cambia `estado`; si es final, quita la fila | Actualiza `seguimiento` del pedido asignado |
  | `pedido.entregado` | Quita la fila | Marca "Disponible", fija `saldo_viajes`, limpia `pedido_asignado` |
  | `pedido.cancelado` | Quita la fila | Igual que entregado |
  | `pedido.requiere-asignacion-manual` | `estado = PENDIENTE`, sin conductor | Libera al conductor si lo tenía |
  | `conductor.disponibilidad-cambiada` | — | `DISPONIBLE` inserta desde `payload.conductor`; `FUERA_DE_SERVICIO` quita la fila |
  | `saldo.acreditado` | — | Fija `saldo_viajes` con el valor recibido |
  | `ubicacion.actualizada` | — | Mueve el marcador; no toca la lista |

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
  reordenamiento animado de 300 ms (FLIP).
- **RN-22:** La fila que cambió se resalta 600 ms con un fondo suave que se desvanece. Es la única
  señal de "esto acaba de cambiar" que reemplaza al parpadeo de la recarga completa.
- **RN-23:** Un viaje que se entrega no desaparece de golpe: se marca como entregado y sale 800 ms
  después. Que una fila se evapore justo cuando alguien iba a tocarla es peor que esperar un
  instante.
- **RN-24:** El contador "N en línea" transiciona entre valores en vez de saltar.
- **RN-25:** Mientras el puntero esté encima de una lista, los **reordenamientos** se difieren hasta
  3 s (los cambios de contenido no: esos se aplican siempre). Nadie debe perder el clic porque la
  fila se movió bajo el dedo.
- **RN-26:** El viaje abierto en `DetalleEnvioPanel` nunca se cierra solo por un evento. Si se
  entrega o se cancela, el detalle lo refleja y deja que la persona lo cierre.
- **RN-27:** Con `prefers-reduced-motion` activo no hay desplazamientos ni FLIP: los cambios se
  aplican al instante y el resaltado se reduce a un cambio de fondo sin transición.

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

  Esas consultas van `withoutGlobalScopes()` porque un evento nace lo mismo en una petición del
  Panel —con `AmbienteScope` encendido— que en una de la app del conductor, donde no lo está; sin
  quitarlo, la misma carga saldría completa o vacía según quién la disparó. El filtro por ambiente
  lo hace el Panel con el campo `ambiente` que viaja en la carga (RN-28).
- `app/Http/Resources/Tenant/ConductorActivoResource.php` y
  `app/Http/Controllers/Tenant/VentaViajeConductorController.php` — usan el helper de saldo; sin
  cambios de forma.
- `tests/Feature/Tenant/PanelReactivoTest.php` — endpoint, orden, límite de lectura y las cargas de
  los cuatro eventos que más cambian.

**Panel (`frontend`)**

- `src/stores/panel.ts` — **nuevo**: estado, reducers, suscripción, reconciliación.
- `src/components/panel/ServiciosEnTurno.vue` — deja de pedir datos, de paginar, de abortar y de
  suscribirse; lee del store y anima la lista. Se retira `defineExpose({ recargar })`.
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
- `src/composables/useOrdenEstable.ts` — **nuevo** (RN-25).
- `src/components/ui/UiEstadoConexion.vue` — **nuevo**: el punto del encabezado y la franja de
  reconexión, los dos en el mismo componente porque son la misma idea a dos volúmenes.
- `src/composables/useRealtime.ts` — **eliminado**. Solo servía para dejar la conexión abierta desde
  `PanelView` (spec tenant/018) y se quedó sin consumidores: ahora el store se suscribe él mismo.

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
  alguien recargaba la página.
