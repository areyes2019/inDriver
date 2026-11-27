# SPEC-035 — Plan de implementación

**Spec:** [`035-app-clientes.md`](035-app-clientes.md) (versión 1.2)
**Fecha:** 2026-11-26
**Estado:** Autorizado. Fase 2 (prototipo) entregada; el código de acceso cambió a correo (spec 1.2).
Fase 3 desplegada el 2026-11-27 (app apagada en las flotillas reales). Fase 3b (instalación, spec 1.3) programada en la rama `app-clientes`.

**Variables nuevas para producción (Fase 3):** `OTP_CANAL=correo`, `OTP_MAIL_FROM` (dirección de
`motopanda.mx`), `OTP_TOPE_DIARIO=80` (plan Free de Resend), y `MAIL_FROM_ADDRESS` pasa a
`motopanda.mx`. Al desplegar: `php artisan tenants:migrate` (3 migraciones de solo agregar) y
`clientes:normalizar-telefonos` en modo reporte.

---

## 1. Diagnóstico de la arquitectura actual

| Pieza | Cómo está hoy | Qué significa para la app |
|---|---|---|
| Backend | Laravel 13, PHP 8.3, Pest | Se agrega un grupo de rutas y controladores de cliente |
| Multi-tenant | stancl/tenancy con **una base de datos por flotilla**. Rutas `/api/t/{slug}/...` con el middleware `tenant.slug` | El aislamiento entre flotillas ya lo da la base separada. Las tablas nuevas van en la base de cada flotilla |
| Sesiones con token | Sanctum, guard `conductor-token`. `personal_access_tokens` vive en la base de cada flotilla | El guard `cliente-token` usa la misma tabla. Sanctum ya rechaza un token cuyo dueño no es del modelo del guard |
| Alta de pedidos | `CreacionPedidoService::crear()` es el único camino (Panel y WhatsApp). Publica en el acto los "lo antes posible" y emite `PedidoCreado` | La app lo llama igual. No hay creación paralela |
| Cotización | Panel: el navegador calcula y el backend valida el redondeo. WhatsApp: `ZonaServicio::cubrePunto()` + `RutaService::distanciaPorCalleKm()` + `TarifaService` dentro de `ConversacionWhatsappService` | Esas tres llamadas se mueven a `CotizacionPedidoService`, compartido por WhatsApp y la app |
| Estados | `PedidoEstadoService::transicionar()`, con un mecanismo diferido que corre después de guardar (lo usa el consumo de viajes) | Ahí se enganchan el evento al cliente y el aviso push |
| Saldo | `SaldoViajesTenant`: puede quedar negativo, nada se bloquea (RN-C5). Se consume al entregar | No se toca |
| TEST/LIVE | El pedido sella su ambiente al crearse. WhatsApp no crea pedidos en TEST | La app sigue a WhatsApp |
| GPS | La app del conductor manda al servicio Go → Redis (buzón) → `ConsumirBuzonGps::difundir()` emite `UbicacionActualizada`. Queda un camino HTTP directo en `TrackingService::registrarPosicion()` | El reenvío al cliente va en esos dos puntos |
| Reverb | Canales privados `tenant.{slug}.conductores` y `tenant.{slug}.panel`. Auth en `/api/t/{slug}/broadcasting/auth` (Panel) y `/api/t/{slug}/conductor/broadcasting/auth` | Canal nuevo `tenant.{slug}.cliente.{id}` con su propio auth |
| Push | `FcmSender` para la app del conductor (Firebase) | No sirve para Web Push sin meter Firebase al navegador. Se usa Web Push estándar (VAPID) |
| Correo | Resend por SMTP, remitente `no-reply@prosello.com.mx` (dominio viejo) | El código sale por aquí, con un remitente de `motopanda.mx` (pendiente de verificar en Resend) |
| WhatsApp | `WhatsappClient` habla con la Cloud API de Meta | Se reusará para el código por WhatsApp cuando exista el número central (mejora futura) |
| Frontend | Un SPA (Vue 3, Vite 8, Tailwind 4, Pinia, vue-router 5). Servicios `services/maps` (Google, interpolación del marcador, recorte de ruta, globo con iniciales) y `services/realtime.ts` (pusher-js) | La app de clientes es una segunda entrada del mismo proyecto y reusa esos servicios |
| Servidor | Un droplet. nginx: `/api` → Laravel, `/gps/` → Go, `/app/` y `/apps/` → **Reverb**, `/` → SPA | La app va en `/pide/` |
| Despliegue | Scripts en `deploy/` (backend, frontend, verificación). Build en la máquina de desarrollo | Sin cambios: no hay staging (sección 13) |
| Rama | `pedidos-whatsapp` lleva 68 commits sobre `main` y es lo que está en producción | La rama nueva `app-clientes` sale de `pedidos-whatsapp` |

## 2. Arquitectura propuesta

```
                 ┌──────────────────────── Teléfono del cliente ────────────────────────┐
                 │  PWA /pide/{slug}/   (pide.html + service worker /pide/sw.js)        │
                 │   ├─ Google Maps JS: mapa, Places (token de sesión), Directions      │
                 │   ├─ pusher-js ──▶ canal privado tenant.{slug}.cliente.{id}          │
                 │   └─ axios ──▶ /api/t/{slug}/cliente/*   (Bearer cliente-token)      │
                 └───────────────┬───────────────────────────────▲──────────────────────┘
                                 │ REST                          │ eventos / push
┌────────────────────────────────▼───────────────────────────────┴─────────────────────┐
│ Laravel                                                                              │
│  Cliente\*Controller ─▶ CotizacionPedidoService ─▶ ZonaServicio, RutaService, Tarifa │
│                     └─▶ CreacionPedidoService (existente) ─▶ PedidoCreado ─▶ Panel   │
│                                                   └─▶ ofertas ─▶ panda_express       │
│  PedidoEstadoService (existente) ── después de guardar ──▶ PedidoClienteActualizado  │
│                                                       └──▶ EnviarAvisoCliente (cola) │
│  ConsumirBuzonGps / TrackingService ─▶ UbicacionActualizada (igual que hoy)          │
│                                    └─▶ SeguimientoCliente (caché Redis) ─▶ Ubicacion │
│                                                                          Repartidor  │
└───────────────┬───────────────────────────────┬─────────────────────────┬───────────┘
                │                               │                         │
        MySQL por flotilla               Reverb (existente)       Web Push (VAPID) /
                                                                  Resend (correo con el código)
```

**Principios:**

1. Laravel sigue siendo el dueño de toda la lógica. La app solo muestra y pide.
2. Todo lo nuevo se **agrega al lado** de lo existente. Los únicos cambios dentro de código actual
   son llamadas puntuales, listadas en la sección 11 de la spec.
3. Cada flotilla queda aislada en tres niveles: su base de datos, su token y su alcance de PWA.

## 3. Flujo de información

**Registro**

```
App ─POST codigo─▶ Laravel: normaliza E.164 → ¿en revisión? → límites → HMAC en cliente_codigos
                                → CodigoAccesoMail por la cola ─▶ Resend ─▶ correo del cliente
App ─POST verificar─▶ Laravel: valida HMAC, intentos y vencimiento → busca o crea Cliente
                                → token Sanctum con habilidad "cliente" ─▶ App lo guarda en pide:{slug}:token
```

**Pedido**

```
App ─POST cotizar─▶ CotizacionPedidoService → sello cifrado (cliente, slug, datos, 10 min) ─▶ App
App ─POST pedidos + Idempotency-Key─▶ inserta (cliente, llave, huella) → valida sello y datos
     → CreacionPedidoService::crear() → PedidoCreado (Panel) + ofertas (conductores)
     → guarda id_pedido en la solicitud ─▶ App: pantalla "Buscando repartidor"
```

**Seguimiento**

```
panda_express acepta ─▶ PedidoEstadoService (TOMADO) → guarda
     → borra caché conductor→pedido → PedidoClienteActualizado ─▶ canal del cliente
     → cliente_notificaciones (único por pedido+tipo) → EnviarAvisoCliente ─▶ push a sus dispositivos
GPS del conductor ─▶ Go ─▶ buzón ─▶ ConsumirBuzonGps::difundir()
     → UbicacionActualizada (Panel, igual que hoy)
     → caché conductor→(pedido, cliente) → ¿pedido de app activo? ¿posición nueva? ¿pasaron 3 s?
     → UbicacionRepartidor ─▶ canal del cliente ─▶ App mueve el marcador
```

## 4. Tablas existentes que se reutilizan

| Tabla | Uso |
|---|---|
| `clientes` | El cliente de la app (con 3 columnas nuevas) |
| `direcciones_clientes` | Direcciones favoritas |
| `pedidos` | El pedido (con 5 columnas nuevas) |
| `zonas_servicio` | Área de servicio |
| `configuraciones_tenant` | Interruptores, marca, horario y ventana (8 claves nuevas) |
| `personal_access_tokens` | Sesiones de cliente |
| `auditoria` | Registro de unificaciones |
| `whatsapp_conversaciones` | Se reasignan al unificar clientes |
| `conductores`, `usuarios` | Nombre y teléfono del repartidor para el seguimiento |

## 5. Tablas nuevas

`telefonos_en_revision`, `cliente_codigos`, `cliente_solicitudes_pedido`,
`cliente_suscripciones_push` y `cliente_notificaciones`. El detalle está en la sección 7 de la spec.
Todas van en la base de cada flotilla (`database/migrations/tenant/`).

## 6. Endpoints

Están en la sección 8 de la spec. Reparto por controlador:

| Controlador (`app/Http/Controllers/Cliente/`) | Endpoints |
|---|---|
| `MarcaController` | `marca`, `manifest.webmanifest` |
| `AccesoController` | `codigo`, `verificar`, `salir` |
| `PerfilController` | `perfil` |
| `DireccionController` | `direcciones` |
| `CotizacionController` | `cotizar` |
| `PedidoController` | `pedidos` (listar, ver, crear, cancelar), `seguimiento` |
| `NotificacionController` | `notificaciones`, `leida` |
| `PushController` | `push` |
| `BroadcastController` existente | `broadcasting/auth` con el guard de cliente |

En el Panel: `ConfiguracionController` (sección "App de clientes") y `ClienteController`
(`por-revisar`, `unificar`).

## 7. Servicios y clases nuevas (backend)

| Clase | Qué hace |
|---|---|
| `Support\TelefonoMx` | Normaliza a E.164 (RN-10). Lo usan la app, el Panel y WhatsApp |
| `Services\Cliente\CodigoAccesoService` | Genera, guarda, manda y verifica códigos con sus límites (RN-11 a RN-13) |
| `Services\Cliente\CanalCodigo` (interfaz) con `CanalCodigoCorreo` y `CanalCodigoLog` | Por dónde sale el código. El de log se niega a arrancar fuera de `local`. `CanalCodigoWhatsapp` se agrega después sin tocar lo demás |
| `Mail\CodigoAccesoMail` | El correo con el código: nombre y logo de la flotilla, código en grande, sin el código en el asunto (RN-13) |
| `Services\CotizacionPedidoService` | Área, distancia por calles y tarifa (RN-21) |
| `Services\Cliente\SelloCotizacion` | Cifra y verifica el sello (RN-23, RN-24) |
| `Services\Cliente\SolicitudPedidoService` | Idempotencia y creación (RN-31) |
| `Services\Cliente\ConciliacionClientesService` | Detección y unificación (RN-16 a RN-19) |
| `Services\Cliente\SeguimientoCliente` | Caché conductor → pedido y emisión de posición (RN-42) |
| `Services\Cliente\AvisoClienteService` | Inserta la notificación y encola el push (RN-52) |
| `Jobs\EnviarAvisoCliente` | Manda el push con `minishlink/web-push` y borra suscripciones caducadas |
| `Events\Tenant\PedidoClienteActualizado`, `Events\Tenant\UbicacionRepartidor` | Eventos del canal del cliente |
| `Console\Commands\NormalizarTelefonosClientes` | `clientes:normalizar-telefonos` |
| `Console\Commands\PurgarDatosCliente` | Purga de códigos, solicitudes y notificaciones |
| `Console\Commands\SimularCargaGps` | Prueba de rendimiento con 100 conductores |
| `Support\MarcaFlotilla` | Logo, banner, íconos 192/512 (GD) y manifiesto |

**Dependencia nueva del backend:** `minishlink/web-push` (Web Push con VAPID). Ninguna otra.

## 8. Eventos de Reverb

| Evento | Estado | Uso en la app |
|---|---|---|
| `PedidoCreado` | Existente, sin cambios | Sigue avisando al Panel |
| `PedidoDisponible`, `PedidoYaTomado`, `PedidoEstadoCambiado`, `PedidoEntregado`, `PedidoCanceladoSinConductor`, etc. | Existentes, sin cambios | No llegan a la app: son de canales de Panel y conductores |
| `UbicacionActualizada` | Existente, sin cambios | Sigue siendo del Panel |
| `PedidoClienteActualizado` | **Nuevo** | Estado, repartidor y mensaje, solo al canal del cliente |
| `UbicacionRepartidor` | **Nuevo** | Posición, solo al canal del cliente y solo con pedido activo |

No se reusan los eventos actuales para el cliente porque viajan por canales que el cliente no puede
escuchar (traen datos de toda la flotilla). Se emiten en los mismos puntos, sin cambiarlos.

## 9. Componentes Vue

Dentro de `frontend/src/pide/`:

| Tipo | Componentes |
|---|---|
| Raíz | `PideApp.vue`, `main.ts`, `router.ts` (base `/pide/:slug/`) |
| Layout | `LayoutCliente.vue` (encabezado con logo y campanita + `BarraInferior.vue`) |
| Acceso | `RegistroView.vue`, `EntrarView.vue`, `CodigoView.vue`, `CasillasCodigo.vue` |
| Inicio | `InicioView.vue`, `BannerFlotilla.vue`, `BotonServicio.vue` (Enviar/Recibir), `FranjaPedidoActivo.vue` |
| Asistente | `AsistenteView.vue`, `BarraPasos.vue`, `PasoRecogida.vue`, `PasoEntrega.vue`, `PasoDetalles.vue`, `PasoConfirmacion.vue`, `BuscadorDireccion.vue` (Places con token de sesión), `SelectorEnMapa.vue`, `TarjetasQuienPaga.vue`, `SelectorHorario.vue`, `ResumenRuta.vue` |
| Espera y seguimiento | `BuscandoView.vue` (animación), `SeguimientoView.vue`, `MapaSeguimiento.vue`, `TarjetaRepartidor.vue`, `LineaProgreso.vue`, `AvisoReconectando.vue` |
| Pedidos | `MisPedidosView.vue`, `TarjetaPedido.vue`, `DetallePedidoView.vue` |
| Cuenta | `CuentaView.vue`, `DireccionesView.vue`, `FormDireccion.vue` |
| Avisos | `NotificacionesView.vue` |
| PWA | `InvitacionInstalar.vue` (Android con botón, iPhone con guía), `PermisoAvisos.vue`, `AvisoVersionNueva.vue` |
| Estado (Pinia) | `sesion.ts`, `marca.ts`, `asistente.ts`, `pedidos.ts`, `seguimiento.ts`, `notificaciones.ts` |
| Composables | `useAlmacenFlotilla.ts` (llaves `pide:{slug}:`), `useRutaCliente.ts` (Directions con límites), `useTiempoEstimado.ts`, `useConexionTiempoReal.ts` (Reverb + respaldo cada 15 s), `useInstalacion.ts`, `usePush.ts` |
| Service worker | `sw/sw.ts` (push, clic en aviso, caché solo de estáticos, actualización con aviso) |

**Se reutiliza del Panel:** `services/maps/*` (proveedor de Google, `interpolacionMarcador`,
`recorteRuta`, `globoConductor`), `services/realtime.ts` (o una variante con otro endpoint de auth),
el tema de Tailwind e íconos de `@iconify/vue`.

**En el Panel:** `ConfiguracionView.vue` gana la sección "App de clientes", y la vista de clientes
gana la pestaña "Por revisar" con `UnificarClientesDialog.vue`. Más la etiqueta de origen "App" en
clientes y pedidos.

**Sin dependencias nuevas en el frontend.**

## 10. Estructura de carpetas

```
backend/
  app/Http/Controllers/Cliente/         controladores de la app
  app/Http/Requests/Cliente/            validaciones
  app/Http/Resources/Cliente/           respuestas (nunca exponen datos internos)
  app/Services/Cliente/                 servicios de la app
  app/Services/CotizacionPedidoService.php
  app/Support/TelefonoMx.php, MarcaFlotilla.php
  app/Events/Tenant/PedidoClienteActualizado.php, UbicacionRepartidor.php
  app/Jobs/EnviarAvisoCliente.php
  database/migrations/tenant/           5 tablas y 2 alteraciones
  tests/Feature/Cliente/                pruebas de la app
  tests/Feature/Tenant/WhatsappCotizacionRegresionTest.php
frontend/
  pide.html                             entrada de la PWA
  public/pide/                          íconos por defecto de MotoPanda
  src/pide/{views,components,stores,composables,sw}/
deploy/
  vps/nginx-motopanda.mx.conf           bloque /pide/
```

## 11. Riesgos

| Riesgo | Impacto | Mitigación |
|---|---|---|
| Correos con el código que caen en spam o tardan | Clientes que no pueden entrar | Remitente verificado en `motopanda.mx` (SPF, DKIM y DMARC), correo sencillo sin enlaces, aviso "revisa spam" en la app y código de 10 minutos |
| Límite diario del plan de Resend | Un abuso o mucho tráfico agota el correo de todo el sistema (incluida la recuperación de contraseñas) | Tope diario propio por debajo del plan (RN-12) y revisar el plan antes de abrir la app a flotillas reales |
| El teléfono ya no está verificado | Números falsos o mal escritos; el repartidor no puede llamar | Validación de formato; el teléfono se puede corregir en "Mi cuenta"; el código por WhatsApp lo verificará después |
| Sin staging: las pruebas corren en producción | Un error puede afectar al Panel o a `panda_express` | Migraciones de solo agregar, interruptores apagados en flotillas reales, suites completas en verde antes de cada despliegue, respaldo previo y despliegue solo con autorización |
| Droplet con 2 GB, sin swap y ~640 MB libres | Si se acaba la memoria, Linux puede matar MySQL | Agregar 2 GB de swap antes de prender la app en flotillas reales (pide autorización aparte) |
| Refactor de la cotización de WhatsApp | Cotizaciones distintas para clientes reales | Pruebas de regresión primero; la lógica se mueve sin cambios |
| Teléfonos duplicados en flotillas existentes | Clientes que no pueden entrar | Reporte previo, bloqueo explícito y conciliación desde el Panel |
| Carga del GPS (100 conductores) | Atraso del buzón, y con él del Panel | Caché por conductor, filtro de duplicados y 3 s mínimos; prueba de carga antes de producción |
| Reverb con más conexiones (clientes) | Más memoria en el droplet | Los clientes solo se conectan con pedido activo; medir con las flotillas de prueba |
| Push en iPhone solo instalado | Clientes de iPhone sin avisos | La campanita y el respaldo cada 15 s siguen funcionando; la guía de instalación lo explica |
| Dos PWA desde el mismo dominio | Sesiones o avisos cruzados | Alcance, llaves y suscripciones por slug; prueba manual en Android y iPhone |
| Service worker defectuoso en producción | La app queda "pegada" en una versión | `sw.js` sin caché, aviso de versión nueva y un interruptor de emergencia: un `sw.js` que se da de baja solo |
| Costo de Google Maps | Facturación alta | Tokens de sesión, espera de 300 ms, rutas por tramo con tope de 10, llave restringida |
| Abuso del envío de códigos | Correo a terceros y cupo de Resend | Límites por correo, IP, flotilla y tope diario global |
| Unificar clientes es difícil de deshacer | Pedidos en el cliente equivocado | Solo AdminCliente, confirmación y auditoría con los ids movidos |
| Cambios en `PedidoEstadoService`, `ConsumirBuzonGps` y `TrackingService` | Romper el despacho actual | Llamadas al final, protegidas con `try/catch`; las suites actuales deben pasar sin modificarse |

## 12. Plan de pruebas

Está en la sección 13 de la spec. Reglas de trabajo:

1. Ninguna funcionalidad existente se modifica sin una prueba que fije su comportamiento antes del
   cambio.
2. Cada fase termina con toda la suite en verde (`php artisan test` y `npm run test:unit`), más
   `pint`, `vue-tsc` y `eslint`.
3. Las pruebas de concurrencia (idempotencia) usan dos procesos reales contra MySQL, no solo SQLite.
4. Las pruebas manuales de PWA y push se hacen en producción con las flotillas de prueba y teléfonos
   reales, y se anotan en una lista de verificación dentro del PR de la fase.
5. Las pruebas de rendimiento GPS corren en local, nunca en producción.

## 13. Sin staging (decisión del 2026-11-26)

No hay entorno staging por ahora. Todo se prueba en local y después en producción, así:

- **Dos flotillas de prueba en LIVE** dadas de alta en producción, con zonas, tarifas y 2 o 3
  conductores de prueba usando la `panda_express` de producción. Son las únicas con
  `app_clientes = Si`.
- **Código por correo:** los clientes de prueba usan correos reales del equipo.
- **Google Maps:** la llave pública de la app se restringe a `motopanda.mx/pide/*`.
- **Memoria:** antes de prender la app en una flotilla real se agregan 2 GB de swap al droplet, con
  autorización aparte.
- Si más adelante se quiere staging, el plan de la versión anterior (droplet aparte, unos US$12 al
  mes) sigue siendo la opción recomendada.

## 14. Fases

Cada fase va en la rama `app-clientes` (que sale de `pedidos-whatsapp`), termina con pruebas en verde
y se revisa antes de seguir. Una fase solo se despliega a producción con tu autorización expresa, con
los interruptores apagados en las flotillas reales.

| Fase | Entregable | Criterio de salida |
|---|---|---|
| 1 | Esta spec 1.1 y este plan | Autorización |
| 2 | Prototipo navegable de la PWA con datos simulados (todas las pantallas, sin backend) | Lo revisas en el navegador del teléfono y apruebas el diseño |
| 3 | `TelefonoMx`, comando de normalización, tablas de revisión y códigos, guard `cliente-token`, registro, entrada, código por correo, conciliación en el Panel | Criterios 2 a 7 de la spec |
| 4 | Pruebas de regresión de WhatsApp, `CotizacionPedidoService`, cotización, sello, direcciones | Criterios 8 a 11; la regresión de WhatsApp en verde sin cambios |
| 5 | Columnas de pedidos, idempotencia, creación, TEST, horario, ventana, cancelación | Criterios 12 a 17 |
| 6 | Canal del cliente, `PedidoClienteActualizado`, `SeguimientoCliente`, pantalla de seguimiento, respaldo de conexión, prueba de carga en local | Criterios 18 a 20 |
| 7 | Mis pedidos, detalle, Repetir, direcciones favoritas | Pantallas conectadas al backend |
| 8 | Logo y banner por flotilla, push y campanita (la instalación pasó a la Fase 3b) | Criterios 21 a 24, con la prueba manual en teléfonos reales |
| 9 | Revisión de seguridad, regresión completa (criterio 25) y pruebas de punta a punta con las flotillas de prueba en producción | Lista para pedir tu autorización de prender la app en una flotilla real |

## 15. Despliegue a producción (cuando lo autorices)

1. Respaldo de las bases de las flotillas.
2. Migraciones de solo agregar (columnas nulas y tablas nuevas). No hay migración que borre o
   cambie datos existentes.
3. Despliegue de backend y frontend con `app_clientes = No` y `app_push = No` en todas las flotillas
   reales. Solo las dos flotillas de prueba (sección 13) los prenden.
   El Panel, WhatsApp y `panda_express` siguen igual.
4. `clientes:normalizar-telefonos` en modo reporte, y revisión contigo del resultado.
5. Con tu visto bueno, `--aplicar` en la flotilla elegida, conciliación de sus duplicados y
   `app_clientes = Si` solo en esa flotilla.
6. Pruebas con viajes reales controlados. Después, `app_push = Si`.
7. Vuelta atrás: apagar `app_clientes` deja todo como antes sin tocar la base.

## 16. Decisiones pendientes

1. ~~Staging~~: no habrá staging por ahora (2026-11-26).
2. ~~Número central de WhatsApp~~: ya no bloquea. El código va por correo (spec 1.2) y WhatsApp se
   agrega después.
3. **Verificar `motopanda.mx` en Resend** (autorizado el 2026-11-26): alta del dominio en Resend y
   sus registros en GoDaddy (TXT `resend._domainkey`, CNAME `send` y `rsend`). No toca el SPF de Zoho
   del dominio raíz. "Enable receiving" apagado.
4. **Plan de Resend:** confirmar el límite diario antes de abrir la app a flotillas reales.
