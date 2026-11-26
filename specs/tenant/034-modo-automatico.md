# SPEC-034 — Modo automático (la flotilla trabaja sin nadie en el Panel)

**Metadatos**

| Campo | Valor |
|---|---|
| ID | SPEC-034 |
| Módulo | Despacho — operación sin despachador |
| Versión | 1.0 |
| Estado | Borrador del 2026-11-25 |
| Depende de | SPEC-020 (Oferta y aceptación, RN-04), SPEC-022 (Cambios sobre envío activo), SPEC-024 (Publicación de envíos), SPEC-025 (Modo TEST/LIVE), SPEC-026 (Cola), SPEC-031 (Pedidos por WhatsApp) |

---

## 1. Historia de usuario

Como dueño de flotilla quiero que el sistema atienda la operación en segundo plano, sin tener el
Panel abierto y sin estar conectado como despachador, para que la flotilla siga funcionando sin que
yo intervenga.

## 2. Objetivo

Hoy la oferta a conductores ya es automática (spec 020 y 024). Lo que todavía necesita a una
persona son tres huecos:

1. Un pedido que nadie acepta en 3 rondas regresa a `PENDIENTE` y espera una asignación manual
   (spec 020, RN-04).
2. El bot de WhatsApp pausa la conversación y espera a una persona cuando el cliente pide hablar con
   alguien (spec 031, RN-59).
3. Nadie se entera de lo que pasa si el Panel está cerrado: incidencias, cambios sin confirmar,
   pedidos atorados.

Esta spec agrega un interruptor, **Modo automático**, que cubre esos huecos con **reglas fijas** y
le avisa al dueño por WhatsApp de lo que no puede resolver.

**Con el interruptor apagado, el sistema se comporta exactamente igual que hoy** (RN-01).

## 3. Alcance

**Incluye:**

- El interruptor por flotilla y su pausa automática de seguridad.
- La reoferta cada 5 minutos y la cancelación a los 30 minutos de un pedido sin conductor.
- El cambio en el bot de WhatsApp cuando el cliente pide a una persona.
- Los avisos al dueño y el resumen diario, con plantillas aprobadas por Meta.
- La detección de pedidos atorados (solo aviso).
- La bitácora de lo que hizo el sistema, visible en el Panel.
- La señal de vida hacia un vigilante externo.

**No incluye:**

- Que la IA decida algo. La IA (Claude) sigue haciendo solo lo que ya hace en la spec 031: leer
  lo que escribe el cliente. Todo lo de esta spec son reglas fijas (D1).
- Elegir a un conductor en particular, cambiar la ventana de 45 s, las 3 rondas o la elegibilidad
  (spec 020).
- Crear pedidos por cuenta propia (D5). Siguen entrando por el Panel, por WhatsApp y por los
  agendados.
- Resolver incidencias, reprogramar, reubicar o cancelar un envío que ya tiene conductor (D6).
- Quitarle un pedido a un conductor (D12).
- Tocar precios, comisiones, paquetes o el estado de clientes y conductores (D11).
- Un horario para el modo automático (D10).
- Cambios en `panda_express`.

## 4. Decisiones

| # | Decisión |
|---|---|
| D1 | Las decisiones son reglas fijas en Laravel, no llamadas a la IA. Es gratis, predecible y se puede auditar. La IA sigue limitada a la extracción de la spec 031 (RN-22) |
| D2 | El interruptor es por flotilla. Solo lo cambia el AdminCliente; el Despachador lo ve y no puede moverlo, igual que el ambiente (spec 025) |
| D3 | Un pedido sin conductor se reofrece cada 5 minutos. A los 30 minutos de haber caído a `PENDIENTE` por primera vez, se cancela |
| D4 | El bot de WhatsApp no pausa la conversación esperando a una persona: contesta que no puede atender eso y da el teléfono de la flotilla |
| D5 | El sistema no crea pedidos por su cuenta |
| D6 | Las incidencias y los cambios sobre envíos con conductor quedan para el dueño, que recibe un aviso |
| D7 | Los avisos al dueño llegan por WhatsApp desde el número de la flotilla, con plantillas de utilidad aprobadas por Meta |
| D8 | El dueño recibe un resumen al final del día |
| D9 | Convive con despachadores: lo que haga una persona gana. Las reglas solo actúan sobre lo que siga en el estado esperado al momento de actuar |
| D10 | Funciona las 24 horas mientras esté prendido |
| D11 | No toca precios, comisiones ni altas y bajas |
| D12 | Un pedido atorado solo genera un aviso; no se le quita al conductor |
| D13 | Todo lo que hace el sistema queda en una bitácora visible en el Panel, con la marca "Lo hizo la IA" |
| D14 | Con la flotilla en TEST, el modo automático no hace nada |
| D15 | Los pedidos que atiende cobran viajes del paquete igual que hoy. No hay cargo extra |
| D16 | Un vigilante externo al servidor recibe una señal de vida cada minuto. Si pasan 5 minutos sin señal, avisa por WhatsApp |
| D17 | Si el sistema cancela 5 pedidos por falta de conductor en una hora, el modo automático se pausa solo y avisa al dueño |

## 5. Actores y permisos

| Actor | Permisos |
|---|---|
| `admin_cliente` | Prende, apaga y reactiva el modo automático. Recibe los avisos y el resumen. Ve la bitácora |
| `despachador` | Ve el estado del modo automático y la bitácora. No lo cambia |
| `conductor` | Sin cambios. Recibe ofertas igual que hoy |
| Cliente por WhatsApp | Sin cambios, salvo RN-12 |

## 6. Modelo de datos

### `configuraciones_tenant` (sin tabla nueva, claves nuevas)

| Clave | Valores | Default |
|---|---|---|
| `modo_automatico` | `No`, `Si`, `Pausado` | `No` |
| `modo_automatico_desde` | timestamp de la última vez que pasó a `Si` | nulo |

Constantes nuevas en `ConfiguracionTenant`: `MODO_AUTOMATICO` y `MODO_AUTOMATICO_DESDE`.

### `pedidos` (columnas nuevas, todas nulables)

- `sin_conductor_desde` — timestamp. Se llena la primera vez que el pedido cae a `PENDIENTE` por
  agotar sus rondas, y se vacía cuando un conductor lo acepta.
- `ultima_reoferta_automatica` — timestamp de la última reoferta hecha por esta spec.
- `aviso_atorado_en` — timestamp. Evita avisar dos veces del mismo pedido atorado.

`cancelado_por` agrega el valor `SISTEMA` a su enum (`CLIENTE`, `ADMIN`, `SISTEMA`). Agregar un valor
no cambia el significado de los que ya existen.

### Tabla nueva `automatico_bitacora` (por tenant)

- `id_bitacora` — PK
- `id_pedido` — FK a `pedidos`, nulable, indexado
- `accion` — enum: `REOFERTA`, `CANCELACION`, `AVISO_DUENO`, `PAUSA`, `PERSONA`, `ATORADO`, `RESUMEN`
- `detalle` — string(255)
- `created_at` — timestamp, indexado

Se conservan 90 días, igual que los textos de WhatsApp (spec 031, RN-30). La purga va en el mismo
comando diario que agrega esta spec (RN-27).

## 7. Endpoints

- `PUT /t/{slug}/configuracion/modo-automatico` (`auth:usuario`, solo AdminCliente, junto a
  `/configuracion/ambiente`). Cuerpo: `{ "modo_automatico": "Si" | "No" }`. `Pausado` no se puede
  mandar; lo pone solo el sistema (RN-24). Mandar `Si` desde `Pausado` lo reactiva.
- `GET /t/{slug}/configuracion` agrega `modo_automatico` a su respuesta. Lo ven AdminCliente y
  Despachador.
- `GET /t/{slug}/automatico/bitacora` (`auth:usuario`, AdminCliente y Despachador). Devuelve las
  últimas 100 entradas, de la más nueva a la más vieja.

## 8. Reglas de negocio

### Interruptor

- **RN-01.** Con `modo_automatico` en `No` o en `Pausado`, ninguna regla de esta spec actúa y el
  sistema se comporta exactamente como hoy. Las columnas nuevas pueden llenarse igual (RN-05), pero
  nadie las lee.
- **RN-02.** Las reglas solo actúan con `modo_automatico = Si` **y** `ambiente = live` (D14). Con la
  flotilla en TEST, el interruptor se puede guardar, pero no hace nada. El Panel lo explica junto
  al interruptor.
- **RN-03.** Solo actúan sobre pedidos LIVE (`Pedido::esTest()` falso), aunque la flotilla esté en
  LIVE.
- **RN-04.** Prender el modo automático no toca los pedidos que ya están en `PENDIENTE`. Las
  reglas empiezan a contar para ellos desde que se prende (RN-07).

### Pedidos sin conductor

- **RN-05.** Cuando `OfertaPedidoService::expirar()` regresa un pedido a `PENDIENTE` por agotar las
  3 rondas, llena `sin_conductor_desde` si estaba vacío (`??=`). Lo hace siempre, con el modo
  prendido o apagado. Todo lo demás de ese método se queda igual, incluido el evento
  `PedidoRequiereAsignacionManual`.
- **RN-06.** Cuando un conductor acepta el pedido, `sin_conductor_desde` y
  `ultima_reoferta_automatica` se vacían. Si después el conductor lo devuelve (spec 020, RN-09) y
  vuelve a caer, el conteo de 30 minutos empieza de nuevo.
- **RN-07.** Un comando nuevo, `automatico:atender-sin-conductor`, corre cada minuto con
  `schedule:run`, igual que `pedidos:publicar-agendados`. Por cada flotilla que cumple RN-02,
  revisa los pedidos LIVE en `PENDIENTE` sin conductor, con `fecha_publicacion` y con
  `sin_conductor_desde`. El momento de inicio es lo más reciente entre `sin_conductor_desde` y
  `modo_automatico_desde` (RN-04).
- **RN-08.** Si pasaron 30 minutos o más desde el momento de inicio, el pedido se cancela (RN-10).
- **RN-09.** Si no, y pasaron 5 minutos o más desde la última reoferta (o desde el momento de inicio,
  si no ha habido ninguna), se reofrece con el mismo rescate que ya usa la cola de la spec 026:
  `veces_ofertado` en cero, transición a `PUBLICADO` y una ronda nueva para todos los elegibles. Se
  anota `ultima_reoferta_automatica` y una entrada `REOFERTA` en la bitácora. El método
  `rescatarDeAsignacionManual()` de `OfertaPedidoService` pasa de privado a público; su
  comportamiento no cambia.
- **RN-10.** Cancelar por falta de conductor usa la misma transición `PENDIENTE → CANCELADO` de
  `PedidoEstadoService` que usa el Panel, con `cancelado_por = SISTEMA` y `motivo_cancelacion =
  "Sin conductor disponible"`. El historial de estados lo guarda con `origen = SISTEMA`. Como es la
  misma transición, salen los mismos avisos que hoy:
  - `PedidoCanceladoSinConductor` hacia los conductores y el Panel.
  - El aviso `cancelado` al cliente de un pedido de WhatsApp (spec 031, RN-47), con sus mismas
    reglas: ventana de 24 horas y respeto a "BAJA".
  - Un pedido del Panel no le avisa al cliente su cancelación, porque RN-49 de la spec 031 solo
    tiene plantillas para "asignado" y "entregado". Esta spec no cambia eso.
- **RN-11.** Antes de reofrecer o cancelar, el pedido se relee de la base y la acción solo sigue si
  todavía está en `PENDIENTE`, sin conductor. Si una persona lo canceló, lo editó o lo publicó, o si
  la cola de la spec 026 ya lo rescató porque se liberó un conductor, el comando no hace nada (D9).
  Si el pedido está en `PUBLICADO` con una ronda en curso, se espera a que termine. Si al terminar
  ya pasaron los 30 minutos, se cancela en la siguiente vuelta del comando.

### Bot de WhatsApp

- **RN-12.** Con el modo automático activo (RN-02), cuando el cliente pide hablar con alguien
  (`INTENCION_PERSONA`), el bot:
  - Marca la conversación con `pide_persona_en`, como hoy, para que se vea en la bandeja.
  - **No pausa el bot** (no llama a `pausarBot()`).
  - Contesta con frases nuevas, `persona_automatico`, de 2 o 3 versiones (spec 031, D17). Dicen que
    por ahora no hay alguien disponible en el chat y dan el teléfono de la flotilla con
    `FrasesBot::contacto()`. Si no hay teléfono, dicen que pueden seguir pidiendo su envío por aquí.
  - Anota `PERSONA` en la bitácora y avisa al dueño (RN-17).

  Con el modo apagado, RN-59 de la spec 031 se queda igual.
- **RN-13.** RN-58 de la spec 031 no cambia: si una persona contesta desde la bandeja, el bot se
  pausa 2 horas en esa conversación, con el modo automático prendido o apagado (D9).
- **RN-14.** El resto del bot no cambia. El cobro mixto (RN-06 de la spec 031) ya vuelve a preguntar
  quién paga sin pasar a nadie, y un fallo de la IA ya contesta con `ia_fallo` (RN-24 de la spec
  031).

### Pedidos atorados

- **RN-15.** El comando de RN-07 también busca pedidos LIVE con conductor que lleven demasiado
  tiempo sin cambiar de estado, contando desde su última transición en `pedido_estados`:
  - En recogida (`TOMADO`, `ARRIBADO`): 30 minutos.
  - En entrega (`EN_CAMINO`, `ARRIBADO_A_ENTREGA`): 60 minutos.
- **RN-16.** Por cada uno, si `aviso_atorado_en` está vacío, avisa al dueño (RN-17), anota `ATORADO`
  en la bitácora y llena `aviso_atorado_en`. No cambia el estado ni el conductor (D12). Cualquier
  transición posterior del pedido vacía `aviso_atorado_en`, para que un nuevo atoro en otra fase se
  vuelva a avisar.

### Avisos al dueño

- **RN-17.** Se le avisa al dueño, con el modo automático activo (RN-02), de:

  | Hecho | Plantilla |
  |---|---|
  | Pedido cancelado por falta de conductor (RN-10) | `dueno_sin_conductor_v1` |
  | Pedido cerrado por `INCIDENCIA`, lo cierre quien lo cierre | `dueno_incidencia_v1` |
  | Un cambio que el conductor no confirmó en 60 s (`AvisarSinConfirmar`, spec 022, RN-03) | `dueno_sin_confirmar_v1` |
  | Un cliente pidió hablar con alguien (RN-12) | `dueno_pide_persona_v1` |
  | Pedido atorado (RN-16) | `dueno_atorado_v1` |
  | El modo automático se pausó (RN-24) | `dueno_pausado_v1` |

  Los dos últimos jobs (`AvisarSinConfirmar` y el cierre por incidencia) se quedan como están: solo
  se les agrega, al final, el envío del aviso. Si el aviso falla, el job no falla (RN-20).
- **RN-18.** "Dueño" es cada usuario `admin_cliente` activo de la flotilla con `telefono`. El
  teléfono se normaliza a E.164 con `TelefonoMexicano`, igual que los de clientes. Si ninguno tiene
  teléfono, no se envía nada, se anota en la bitácora y el Panel lo advierte junto al interruptor.
- **RN-19.** Los avisos salen del número de WhatsApp activo de la flotilla (`whatsapp_numeros`, el
  mismo que usa `AvisarEstadoPedido`), como plantillas de utilidad. Si la flotilla no tiene número
  activo, no se envía nada y se anota en la bitácora. Prender el modo automático no exige tener
  número, pero el Panel lo advierte.
- **RN-20.** Los avisos corren en la cola `whatsapp`, fuera de la acción que los provoca. Si Meta
  falla, el pedido no se entera (mismo criterio que RN-52 de la spec 031).
- **RN-21.** Las plantillas viven en `PlantillasWhatsapp`, junto a las del Panel, y se dan de alta
  con el comando que ya existe, `whatsapp:crear-plantillas`. Textos de la versión 1:

  | Nombre | Texto |
  |---|---|
  | `dueno_sin_conductor_v1` | El envío {{1}} se canceló porque ningún repartidor lo tomó en 30 minutos. |
  | `dueno_incidencia_v1` | El envío {{1}} se cerró por incidencia. Revísalo en el Panel. |
  | `dueno_sin_confirmar_v1` | El repartidor {{1}} no confirmó el cambio del envío {{2}}. Revísalo en el Panel. |
  | `dueno_pide_persona_v1` | {{1}} pidió hablar con alguien por WhatsApp. Su teléfono es {{2}}. |
  | `dueno_atorado_v1` | El envío {{1}} lleva {{2}} minutos en {{3}} sin avanzar. Repartidor: {{4}}. |
  | `dueno_pausado_v1` | El modo automático se pausó: se cancelaron {{1}} envíos en la última hora por falta de repartidor. Vuelve a prenderlo en Configuración cuando lo revises. |
  | `dueno_resumen_v1` | Resumen de hoy: {{1}} envíos entregados, {{2}} cancelados y {{3}} todavía en turno. |

  Mientras Meta no apruebe una plantilla, sus avisos no salen y se anotan en la bitácora como no
  enviados.

### Resumen diario

- **RN-22.** Un comando nuevo, `automatico:resumen-diario`, corre a las 22:00, hora del centro de
  México. Para cada flotilla con el modo en `Si` o en `Pausado` y en LIVE, cuenta los pedidos LIVE
  del día: los `ENTREGADO`, los `CANCELADO` y los que siguen en `ESTADOS_EN_TURNO`. Se manda con
  `dueno_resumen_v1` y se anota `RESUMEN` en la bitácora.
- **RN-23.** Si en el día no hubo ningún pedido, no se manda el resumen, para no cobrar un mensaje
  vacío.

### Límite de seguridad

- **RN-24.** Al cancelar por RN-10, se cuentan las cancelaciones con `cancelado_por = SISTEMA` de la
  última hora que sean posteriores a `modo_automatico_desde`. Si llegan a 5, `modo_automatico` pasa
  a `Pausado`, se anota `PAUSA` en la bitácora y se avisa al dueño con `dueno_pausado_v1`.
- **RN-25.** En `Pausado` el sistema vuelve al comportamiento de hoy (RN-01). Los pedidos sin
  conductor se quedan en `PENDIENTE` esperando a una persona, y el bot vuelve a pausarse cuando
  piden a alguien. El dueño lo reactiva desde Configuración. Al reactivarlo,
  `modo_automatico_desde` se actualiza, así que las cancelaciones anteriores ya no cuentan y los
  pedidos en espera empiezan su conteo de nuevo (RN-07).

### Bitácora

- **RN-26.** Cada acción de esta spec anota una línea en `automatico_bitacora`, con el folio del
  pedido si lo hay. El Panel la muestra en Configuración, dentro de la tarjeta "Modo automático",
  con la marca **"Lo hizo la IA"**, la hora y el detalle. En el historial de un pedido, las
  transiciones con `origen = SISTEMA` de esta spec también llevan esa marca.
- **RN-27.** Un comando diario nuevo, `automatico:purgar-bitacora`, borra las entradas de más de 90
  días.

### Señal de vida

- **RN-28.** Cada minuto, `schedule:run` manda un job pequeño, `EnviarLatido`, a la cola por defecto.
  El job hace un GET a la URL que esté en `services.latido.url` (variable `LATIDO_URL`). Si la
  variable está vacía, no hace nada. Como el latido pasa por la cola, deja de llegar tanto si se
  detiene `schedule:run` como si se detiene el worker de colas, que es lo que mueve las ofertas.
- **RN-29.** La URL es de un servicio de vigilancia externo al droplet. Si pasan 5 minutos sin
  latido, ese servicio avisa por WhatsApp al número que tenga configurado. El latido es uno solo
  para todo el servidor, no uno por flotilla. El servicio y el número a avisar se eligen al
  implementar y se configuran fuera del código.
- **RN-30.** Un fallo del GET se registra en el log y nada más. Nunca interrumpe otra tarea.

## 9. Proceso

```
Pedido sin aceptar tras 3 rondas
            │
            ▼
   PENDIENTE (sin_conductor_desde)
            │
  ¿modo automático activo y LIVE?
     │ no                    │ sí
     ▼                       ▼
 como hoy: espera     cada minuto, automatico:atender-sin-conductor
 a una persona               │
                ┌────────────┼──────────────────────┐
                ▼            ▼                      ▼
        ≥ 30 min:     ≥ 5 min desde la       una persona o la cola
        CANCELADO     última reoferta:       ya lo movió: no hace nada
        (SISTEMA)     PUBLICADO, 3 rondas
                │
        ¿5 en la última hora?
                │ sí
                ▼
        modo automático = Pausado
        + aviso al dueño
```

## 10. Cambios en archivos existentes (lo mínimo, sin romper nada)

| Archivo | Cambio |
|---|---|
| `ConfiguracionTenant` | Constantes `MODO_AUTOMATICO` y `MODO_AUTOMATICO_DESDE` |
| `ConfiguracionController` | Método `modoAutomatico()` y el campo nuevo en `estadoActual()` |
| `routes/api.php` | La ruta PUT junto a `/configuracion/ambiente` y la ruta GET de la bitácora |
| `OfertaPedidoService::expirar()` | Una línea: `sin_conductor_desde ??= now()` al caer a `PENDIENTE` |
| `OfertaPedidoService::rescatarDeAsignacionManual()` | Pasa de `private` a `public`, sin cambios en su lógica |
| Aceptación del pedido | Vacía `sin_conductor_desde` y `ultima_reoferta_automatica` |
| `PedidoEstadoService::transicionar()` | Vacía `aviso_atorado_en` en cada transición |
| `ConversacionWhatsappService::pedirPersona()` | Rama nueva para el modo automático (RN-12); la rama actual queda igual |
| `FrasesBot` | Frases `persona_automatico` |
| `PlantillasWhatsapp` | Las 7 plantillas de RN-21 |
| `AvisarSinConfirmar` y el cierre por incidencia | Al final, el aviso al dueño (RN-17) |
| `routes/console.php` | `automatico:atender-sin-conductor` y `EnviarLatido` cada minuto; `automatico:resumen-diario` a las 22:00; `automatico:purgar-bitacora` diario |
| Panel (Configuración) | Tarjeta "Modo automático": interruptor, estado `Pausado`, advertencias de RN-02, RN-18 y RN-19, y la bitácora |

**No cambian:** la ventana de 45 s, las 3 rondas, la elegibilidad, la cola de la spec 026, la
publicación de agendados, la máquina de estados, la fórmula de precio, la liquidación, el consumo de
viajes, los avisos a clientes de la spec 031 y la app del conductor.

## 11. Criterios de aceptación

1. Con el modo en `No`, todas las pruebas actuales pasan sin modificarse, y un pedido que agota sus
   rondas se queda en `PENDIENTE` para siempre, como hoy.
2. Con el modo en `Si` y la flotilla en LIVE, un pedido que agota sus rondas se reofrece a los
   5 minutos y se cancela a los 30, con `cancelado_por = SISTEMA`.
3. Si un conductor se libera mientras el pedido espera, la cola de la spec 026 lo rescata y el
   comando no lo toca.
4. Si un despachador cancela o publica el pedido antes de los 30 minutos, el comando no hace nada.
5. Con la flotilla en TEST, o con un pedido TEST, el comando no hace nada.
6. A la quinta cancelación por falta de conductor en una hora, el modo pasa a `Pausado` y el dueño
   recibe `dueno_pausado_v1`. Desde ahí, los pedidos se quedan en `PENDIENTE`.
7. Al reactivar el modo, las cancelaciones anteriores no cuentan para el límite.
8. Con el modo activo, "Quiero hablar con alguien" no pausa el bot, contesta con el teléfono y se
   marca en la bandeja. Con el modo apagado, se pausa como hoy.
9. Un pedido en recogida por más de 30 minutos genera un solo aviso `dueno_atorado_v1`, y su
   conductor no cambia.
10. El resumen diario no se manda si el día no tuvo pedidos.
11. El Despachador ve el interruptor y la bitácora, pero `PUT /configuracion/modo-automatico`
    le responde 403.
12. Sin `LATIDO_URL`, `EnviarLatido` no hace nada. Con la URL, hace un GET por minuto.
13. Cada acción queda en la bitácora y se ve en el Panel con "Lo hizo la IA".

## 12. Pruebas automáticas

- `ModoAutomaticoSinConductorTest`: reoferta a los 5 minutos, cancelación a los 30, sin efecto con el
  modo apagado, en TEST o con un pedido TEST, sin efecto si una persona o la cola movió el pedido, y
  conteo desde `modo_automatico_desde`.
- `ModoAutomaticoLimiteTest`: pausa a la quinta cancelación y reinicio del conteo al reactivar.
- `ModoAutomaticoBotTest`: las dos ramas de `pedirPersona()`.
- `ModoAutomaticoAtoradosTest`: umbrales de recogida y entrega, un solo aviso, sin cambio de conductor.
- `ModoAutomaticoAvisosTest`: destinatarios (RN-18), sin número activo (RN-19), plantillas sin aprobar.
- `ConfiguracionTest`: permisos del endpoint nuevo.
- Las suites actuales de oferta, publicación, cola y WhatsApp deben pasar sin modificarse.

## 13. Pendientes para la implementación

- Elegir el servicio externo de vigilancia y confirmar que puede avisar por WhatsApp (RN-29).
- Dar de alta las 7 plantillas en Meta y esperar su aprobación antes de activar los avisos.
