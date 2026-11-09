# SPEC-031 — Pedidos por WhatsApp

**Metadatos**

| Campo | Valor |
|---|---|
| ID | SPEC-031 |
| Módulo | Pedidos / Canal WhatsApp |
| Versión | 1.0 |
| Estado | Aprobada el 2026-11-05 |
| Rama | `pedidos-whatsapp` |
| Depende de | SPEC-006 (pedidos y máquina de estados), SPEC-020 (ofertas), SPEC-022 (cambios sobre envío), SPEC-025 (TEST/LIVE), SPEC-030 (comisiones) |

---

> **Estados:** esta spec propone cambios a la máquina de estados de `tenant/006-crud-pedidos.md`, que sigue siendo la fuente única. La etapa 3 los lleva allá antes de programarlos.

> **Nombres:** las clases, tablas y estados nuevos son propuestas y pueden cambiar al programarlos.

## 1. Objetivo

Que el cliente de una flotilla pida un envío escribiendo al número de WhatsApp de esa flotilla. La IA interpreta el mensaje, Laravel valida y calcula la tarifa, el cliente confirma y el pedido entra al flujo actual de creación, despacho y app.

## 2. Alcance

**Incluye:**

- Un número de WhatsApp Business por flotilla.
- Mensajes de texto, ubicación compartida y botones.
- Registro automático de clientes nuevos.
- Pedidos "lo antes posible".
- Las tres `modalidad_pago`, con cobro de producto contra entrega.
- Lo que el repartidor debe entregar y cobrar, visible en la app.
- El regreso del paquete cuando el comprador no paga.
- Avisos de estado al cliente.
- Bandeja de conversaciones en el Panel, con respuesta básica del despachador.

**No incluye:**

- Notas de voz.
- Pedidos agendados por WhatsApp.
- Una app aparte para despachadores.
- Cambios en `gps-service/` o en el motor de ofertas.
- Cobros en línea. El sistema registra importes, no mueve dinero.

## 3. Decisiones acordadas

| # | Decisión |
|---|---|
| D1 | El cliente ve un resumen con el precio y confirma antes de crear el pedido |
| D2 | Un cliente desconocido se registra solo y se atiende de inmediato |
| D3 | Con la flotilla en TEST, el bot no crea pedidos y avisa que el servicio no está disponible |
| D4 | Los números se conectan a mano al principio; la conexión automática de Meta llega en la etapa 8 |
| D5 | La IA es Claude, con su SDK oficial de PHP. El paquete queda aprobado para instalarse en la etapa 5 |
| D6 | Los despachadores pueden responder desde el Panel en la primera versión |
| D7 | Primera versión solo "lo antes posible" |
| D8 | El bot determina quién paga el envío y si hay un cobro de producto |
| D9 | En contra entrega, el repartidor adelanta el importe del producto al recoger |
| D10 | Si el comprador no paga, el vendedor devuelve el adelanto, paga el envío y una tarifa de regreso, y recupera su paquete |
| D11 | La tarifa de regreso es el 30% del envío |
| D12 | La tarifa de regreso es para el repartidor. La comisión de la flotilla sigue calculándose solo sobre el envío |
| D13 | El texto de los mensajes se borra a los 90 días. El pedido se conserva |
| D14 | Si el vendedor no paga lo que debe por un regreso, se marca como inactivo hasta que pague |
| D15 | Un regreso genera comisión sobre el envío y consume 1 viaje de la flotilla |

## 4. Reglas del dinero

### Campos

| Situación | `modalidad_pago` | `importe_envio` | `importe_cobro` |
|---|---|---|---|
| Quien recibe paga el envío | `RECEPTOR_PAGA_ENVIO` | Tarifa | 0 |
| Quien envía paga el envío | `REMITENTE_PAGA_ENVIO` | Tarifa | 0 |
| Contra entrega | `RECEPTOR_PAGA_ENVIO_PRODUCTOS` | Tarifa | Precio del producto |

### Reglas

- **RN-01.** `importe_envio` lo calcula siempre el servidor: `banderazo + max(0, km − km_incluidos) × km_adicional`, con la distancia por calles. Nunca lo fija la IA.
- **RN-02.** Si no se obtiene una ruta real, no se cotiza. La línea recta de `RutaService` no sirve para cobrar.
- **RN-03.** `importe_cobro` sale del mensaje del cliente, y el cliente lo confirma en el resumen.
- **RN-04.** Si no queda claro quién paga o cuánto, el bot pregunta. No supone.
- **RN-05.** `pago_extra` no se usa aquí: es para cambios de destino (SPEC-022).
- **RN-06.** El caso "quien envía paga el envío y al destinatario se le cobra un producto" no se admite en esta versión. El bot lo pasa a un despachador.

### Lo que debe mostrar la app

| Momento | Quien recibe paga | Quien envía paga | Contra entrega ($300 + $50) |
|---|---|---|---|
| Oferta | Envío $50, cobra al receptor | Envío $50, ya pagado | Envío $50, "Debes entregar $300 al recoger" |
| Recogida | Sin dinero | Sin dinero | "Entrega $300 al vendedor" |
| Entrega | "Cobrar $50" | "Nada que cobrar" | "Cobrar $350" |

La regla vive en `src/utils/dineroViaje.js` de `panda_expess`. `PedidoOfertaResource` envía `importe_cobro` para avisar antes de aceptar. "Pago estimado" sigue mostrando `importe_envio`, porque es lo que gana el viaje, no lo que se cobra.

### Comprador que no paga

Solo aplica a `RECEPTOR_PAGA_ENVIO_PRODUCTOS`. Con producto de $300 y envío de $50:

| Concepto | Importe |
|---|---|
| Devuelve el adelanto | $300 |
| Envío | $50 |
| Tarifa de regreso (30% del envío) | $15 |
| **El vendedor paga al repartidor** | **$365** |

- **RN-07.** La tarifa se guarda en el pedido al iniciar el regreso. Un cambio posterior del porcentaje no la altera.
- **RN-08.** Al cerrar el regreso se genera la comisión sobre `importe_envio`, igual que en SPEC-030, y se consume 1 viaje del tenant, porque el viaje sí se realizó.
- **RN-09.** El resumen de cada pedido contra entrega avisa de esta regla antes de confirmar.
- **RN-10.** El sistema registra lo que debe pagar el vendedor, pero no obliga a pagarlo. Si no paga, el cliente se marca como `Inactivo` hasta que pague (RN-15).

### Cambio a los estados

| Desde | Hacia | Quién | Cuándo |
|---|---|---|---|
| `ARRIBADO_A_ENTREGA` | `EN_REGRESO` | Conductor | El comprador no pagó |
| `EN_REGRESO` | `DEVUELTO` (final) | Conductor | Entregó el paquete al vendedor y recibió el pago |

`INCIDENCIA` se conserva para emergencias, también durante el regreso: cierra sin comisión ni consumo de viaje. Si el vendedor no paga, el despachador la usa y marca al cliente como `Inactivo` desde Clientes (D14). Ninguno de los dos pasos es automático.

En el mapa, el regreso dibuja la línea del comprador al vendedor. El servicio GPS no cambia: sigue rastreando hasta el estado final. En regreso no se puede reprogramar ni cambiar el destino. En TEST, el simulador no mueve al conductor durante el regreso.

Durante el regreso, la app ofrece llamar al vendedor con `telefono_vendedor`, que solo se envía en `EN_REGRESO`. Si el pedido no tiene un cliente con teléfono, no hay botones de contacto.

La tarifa de regreso se cobra en efectivo al vendedor. En Prepago aparece en el monedero del conductor; en Comisión no entra a su cuenta con la flotilla (D12).

## 5. Flujo

```text
Cliente en WhatsApp
      │
Meta Cloud API
      │
Webhook: firma válida → phone_number_id → tenant activo
      guarda el mensaje (id único) → responde 200
      │
Job en la cola "whatsapp", con tenancy iniciada
      │
IA: solo extrae campos
      │
Laravel valida cliente, coordenadas, cobertura, tarifa y ambiente
      │
  ┌───┴──────────────┐
Falta algo          Completo → resumen con botones
  │                       │
Pregunta o pasa      El cliente confirma
a una persona             │
                     CreacionPedidoService
                          │
                     Flujo actual: ofertas → app → repartidor
```

## 6. Reglas de negocio del canal

### Clientes

- **RN-11.** Quien escribe es el cliente y queda en `id_cliente`. Quien recibe va en `nombre_solicitante` y `telefono_solicitante`, porque esos campos guardan al destinatario desde SPEC-006.
- **RN-12.** La conversación se identifica con el `user_id` de Meta (BSUID), no con el teléfono. Meta puede omitir el teléfono si el usuario adoptó un nombre de usuario.
- **RN-13.** Si el teléfono llega, se normaliza a E.164 y se busca al cliente. Si no llega, el bot lo pide antes de registrarlo.
- **RN-14.** El nombre del cliente nuevo se le pregunta. No se copia del perfil.
- **RN-15.** Un cliente `Inactivo` no crea pedidos y el bot le ofrece hablar con la flotilla.

### Validaciones antes del resumen

- **RN-16.** Recogida y entrega deben tener coordenadas. Se prefiere la ubicación compartida o una dirección guardada, y nunca se adivina.
- **RN-17.** Ambos puntos deben caer en la cobertura (`ZonaServicio::cubrePunto`). Sin polígonos, todo punto se considera cubierto.
- **RN-18.** Con la flotilla en TEST o con el tenant no activo, no se crea el pedido.

### Confirmación y duplicados

- **RN-19.** El resumen usa botones "Confirmar", "Corregir" y "Cancelar". Meta admite un máximo de 3.
- **RN-20.** El pedido se crea solo al pasar la solicitud a confirmada, con bloqueo y una relación única solicitud-pedido. Dos confirmaciones crean un solo pedido.
- **RN-21.** Cada mensaje de Meta se guarda con su id único. Un reintento no se procesa dos veces.

### IA

- **RN-22.** La IA hace una sola llamada con salida por esquema y sin herramientas. No fija precios ni crea pedidos.
- **RN-23.** El texto del cliente es dato no confiable. Lo que devuelva la IA se vuelve a validar en Laravel.
- **RN-24.** Una negativa, una respuesta cortada, un fallo o un caso ambiguo se responde con una pregunta o pasa a una persona. La conversación nunca queda en silencio.
- **RN-25.** El bot atiende solo pedidos de esa flotilla.

### Política de Meta

- **RN-26.** Siempre debe existir una vía clara para hablar con una persona. Meta la exige al automatizar respuestas.
- **RN-27.** Fuera de las 24 horas solo se envían plantillas aprobadas. Los avisos de pedido van como plantillas de utilidad, sin promociones.
- **RN-28.** Los avisos de estado requieren permiso del cliente. El bot lo pide en el flujo y respeta la baja.
- **RN-29.** El bot nunca pide números completos de tarjeta, de cuenta bancaria o de identificación.

### Retención y consumo

La meta de capacidad es de 1,000 conversaciones al día por flotilla (40 por repartidor × 25 repartidores). Es una meta, no un volumen actual. Con unos 10 mensajes por conversación equivale a unos 300 MB al mes, según una estimación por medir con datos reales.

- **RN-30.** Un proceso diario borra el texto de los mensajes con más de 90 días. El pedido, la solicitud y la auditoría se conservan.
- **RN-31.** Los avisos de enviado, entregado y leído actualizan el estado del mensaje. No crean filas nuevas.
- **RN-32.** Los botones y las ubicaciones compartidas no llaman a la IA.
- **RN-33.** El modelo se elige con un conjunto de mensajes reales, empezando por Haiku 5.5. Se sube de modelo solo si no alcanza la precisión acordada.
- **RN-34.** El bot agrupa sus preguntas y respuestas para enviar menos mensajes a Meta.

## 7. Datos de Meta verificados

Consultado el 2026-11-05.

| Tema | Lo que dice Meta | Fuente |
|---|---|---|
| Verificación del webhook | GET con `hub.mode`, `hub.verify_token` y `hub.challenge`; se responde el `challenge` | [Webhooks](https://developers.facebook.com/docs/graph-api/webhooks/getting-started) |
| Firma | `X-Hub-Signature-256`, SHA256 con el App Secret, prefijo `sha256=`. No aclara el cuerpo crudo: se valida contra él y se comprueba con un envío real | [Webhooks](https://developers.facebook.com/docs/graph-api/webhooks/getting-started) |
| Reintentos | Hasta 7 días según la página de WhatsApp y 36 horas según la general. Puede haber duplicados | [WhatsApp](https://developers.facebook.com/docs/whatsapp/cloud-api/guides/set-up-webhooks) |
| Lotes | Un POST puede traer varias entradas, cambios y mensajes | [Webhooks](https://developers.facebook.com/docs/graph-api/webhooks/getting-started) |
| Enrutamiento | `entry[].changes[].value.metadata.phone_number_id`; una sola URL para todas las cuentas | [Referencia](https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/components), [Embedded Signup](https://developers.facebook.com/docs/whatsapp/embedded-signup) |
| Identidad | `contacts[].user_id` siempre llega; el teléfono puede faltar. Se puede responder al BSUID | [Referencia](https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/components), [BSUID](https://developers.facebook.com/documentation/business-messaging/whatsapp/business-scoped-user-ids) |
| Botones | Máximo 3; título de hasta 20 caracteres | [Botones](https://developers.facebook.com/docs/whatsapp/cloud-api/messages/interactive-reply-buttons-messages) |
| Ventana | 24 horas desde el último mensaje del cliente; después, solo plantillas | [Precios](https://developers.facebook.com/docs/whatsapp/pricing) |
| Costos | 1,000 mensajes de servicio gratis al mes por número; se cobra desde el 1,001. Las tarifas exactas están en sus tablas, sin revisar | [Precios](https://developers.facebook.com/docs/whatsapp/pricing) |
| Automatización | Se permite con vías de escalamiento. La política leída no menciona IA ni exige avisar que es un bot | [Política](https://whatsappbusiness.com/policy/) |
| Plantillas | Las de pedido son de utilidad; deben estar aprobadas; la revisión tarda hasta 24 horas | [Categorías](https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/template-categorization) |

**Sin confirmar:**

- El cambio del 23 de octubre de 2026 en la página de BSUID, y si ya funciona enviar a un BSUID. Se comprueba en la etapa 4 con el número de prueba.
- El formato real de los móviles mexicanos en `wa_id` (`52` o `521`). Se comprueba con mensajes reales.
- Las restricciones a chatbots de propósito general que reportan fuentes de terceros. No aparecen en la política oficial leída; RN-25 mantiene el bot dentro de lo que sigue permitido en ambos casos.
- La tarifa exacta por mensaje en México.

## 8. Etapas

Cada etapa se detiene hasta el visto bueno del usuario, que la prueba en el sistema.

| Etapa | Contenido | Qué debe verse |
|---|---|---|
| 0 | Rama, spec, revisión de Meta | Nada cambia en el sistema |
| 1 | `CreacionPedidoService`, `TarifaService`, zona horaria | El Panel funciona y cobra igual |
| 2 | `importe_cobro` en la oferta; textos de dinero en la app | La app muestra lo de la sección 4 |
| 3 | Regreso del paquete | "En regreso" en Panel y app, con el total a cobrarle al vendedor |
| 4 | Webhook, firma, enrutamiento, guardado | El bot responde "Recibido"; sin IA ni pedidos |
| 5 | IA y validaciones, sin crear pedidos | El bot dice lo que entendió y el precio; el Panel no recibe nada |
| 6 | Conversación, confirmación y creación | Al confirmar, el pedido llega al Panel y a la app |
| 7 | Avisos, bandeja y plantillas | Menú de conversaciones; el cliente recibe avisos |
| 8 | Conexión de números por flotilla | Una segunda flotilla se conecta sin cruce de datos |

**Corrección de la etapa 1:** `fecha_servicio`, `hora_desde` y `hora_hasta` se capturan en hora local, pero se leían como UTC. Un agendado de 17:00 a 18:00 en Celaya se publicaba a las 10:45 hora local. Ahora se interpretan con `app.timezone_operacion` (`America/Mexico_City`, una sola zona para todas las flotillas) mediante `App\Support\HoraOperacion`. `app.timezone` sigue en UTC, porque es como están guardadas las demás fechas.

La etapa 6 se prueba con una flotilla de prueba en LIVE y el número de prueba, porque en TEST el bot no crea pedidos (D3).

**Cómo quedó la etapa 5** (2026-11-09):

- La IA lee los mensajes de las últimas 2 horas (máximo 12), incluidas las respuestas del bot, para entender respuestas cortas como "a Juan".
- Solo se atienden mensajes de texto. Una ubicación, una foto o un audio reciben una respuesta fija sin pasar por la IA (RN-32); usar la ubicación compartida como recogida o entrega queda para la etapa 6.
- Las direcciones se ubican con la Geocoding API de Google, con la misma llave de Maps, y solo se aceptan resultados de domicilio (`ROOFTOP` o `RANGE_INTERPOLATED`). La API debe estar habilitada en esa llave.
- Si el cliente manda varios mensajes antes de que el bot conteste, recibe una sola respuesta con todo (RN-34).
- Cada respuesta termina avisando que es una prueba y que no se crea el pedido.

## 9. Componentes

### Nuevos

| Componente | Función |
|---|---|
| `whatsapp_numeros` (base central) | `phone_number_id`, `id_tenant`, estado y token cifrado. Sin contenido de mensajes |
| `whatsapp_conversaciones`, `whatsapp_mensajes`, `whatsapp_solicitudes` (base del tenant) | Conversación, mensajes con id único, y borrador ligado al pedido |
| `WhatsappWebhookController` | Verificación y recepción |
| `ProcesarMensajeWhatsapp` | Job en la cola `whatsapp` |
| `ConversacionWhatsappService` | Estados de la conversación |
| `ExtractorPedidoIA` | Extracción de campos |
| `CreacionPedidoService` | Flujo de creación compartido con el Panel |
| `TarifaService` | Fórmula única de tarifa |
| `GeocodificadorService` | Dirección a coordenadas en el servidor |
| `WhatsappClient` | Envío de mensajes a Meta |
| Servicio de avisos al cliente | Escucha los eventos actuales del pedido |

### A modificar

`PedidoController.php`, `RutaService.php`, `PedidoEstadoService.php` y `006-crud-pedidos.md` (etapa 3), `PedidoOfertaResource.php`, `routes/api.php`, `routes/console.php`, `config/services.php`, `.env.example`, `composer.json`, `router/index.ts`, `TenantLayout.vue`, y en `panda_expess`: `AvailableOrderCard.vue`, `ActiveTripCard.vue` y la pantalla de viaje de `Dashboard.vue`.

### Operación del canal (etapa 4)

- La URL del webhook es `/api/v1/webhooks/whatsapp`, para todas las flotillas.
- Un número se conecta con `php artisan whatsapp:conectar-numero {slug} {phone_number_id}`. El token se pregunta en pantalla, para que no quede en el historial.
- Un número no se puede reasignar a otra flotilla con ese comando.
- Sin `WHATSAPP_APP_SECRET`, el webhook rechaza todo.
- La cola `whatsapp` tiene su propio worker en `routes/console.php`.

## 10. Criterios de aceptación

1. Crear un pedido desde el Panel da el mismo resultado antes y después de la etapa 1.
2. La tarifa del servidor coincide con la fórmula del Panel, y sin ruta real no se cotiza.
3. La app muestra lo que la sección 4 indica en las tres formas de pago.
4. Un comprador que no paga termina en `DEVUELTO`, con $365 a cobrar en el ejemplo, comisión sobre $50 y 1 viaje consumido.
5. Un webhook con firma inválida se rechaza.
6. Un mismo mensaje reenviado por Meta se procesa una vez.
7. Dos flotillas de prueba no ven los datos de la otra.
8. Un usuario sin teléfono en el webhook se atiende con su BSUID.
9. Con la flotilla en TEST no se crea ningún pedido.
10. Dos confirmaciones crean un solo pedido, y el pedido sigue el mismo despacho que uno del Panel.
11. Un fallo de la IA o de Meta no deja la conversación sin respuesta ni retrasa las ofertas a conductores.
12. A los 90 días, el texto de un mensaje queda borrado y su pedido sigue intacto.

## 11. Pendientes del usuario

1. Crear la app y el número de prueba en Meta, y la clave de la API de Claude. Se necesitan desde las etapas 4 y 5.
