# Spec: Configuración del tenant — acreditación de paquetes, reventa de viajes a conductores, tarifas y geofence

## Historia de usuario

Actualmente admin_central vende paquetes de viajes a los admin_cliente. Como admin_cliente quiero
poder revender viajes a los repartidores (conductores) para que paguen sus viajes por adelantado.
También establecer un esquema de comisión por porcentaje de viaje.

También, como admin_cliente quiero poder configurar mi sistema:

1. Establecer tarifas de viaje por banderazo.
2. Establecer tarifas por kilómetro adicional.
3. Establecer zonas de cobertura con geofence.
4. Establecer el costo del viaje o el porcentaje de comisión a los repartidores.

El acceso se ubica en el ícono de engranaje que está junto a la campanita del navbar, y lleva a una
pantalla de configuración.

## Objetivo / Alcance

Esta historia cubre cuatro piezas conectadas entre sí:

**A. Acreditación manual de paquetes (admin_central → tenant)**

- En `DetalleTenantView.vue` (panel de admin_central), se agrega la acción "Acreditar paquete":
  el admin_central elige un paquete del catálogo (`paquetes_viajes`) y una cantidad, y lo acredita
  al tenant. Esto suma viajes al saldo disponible del tenant.
- No hay flujo de pago ni de solicitud: es una acreditación directa hecha por el equipo central,
  igual de manual que hoy es la gestión del catálogo de paquetes (spec 009).

**B. Reventa de viajes prepagados / comisión (tenant → conductores)**

- El admin_cliente puede vender viajes prepagados a un conductor desde `ConductoresListaView.vue`:
  elige un conductor y una cantidad, y esos viajes se descuentan del saldo del tenant y se suman al
  saldo prepagado de ese conductor.
- Cada conductor consume su saldo automáticamente: cada pedido que entrega descuenta 1 viaje.
- De forma **global para todo el tenant** (no por conductor), el admin_cliente elige una sola
  modalidad de cobro a sus conductores:
  - **Prepago**: los conductores compran viajes por adelantado (lo descrito arriba); el
    "costo del viaje" configurado es el precio que se les cobra por cada viaje prepagado.
  - **Comisión**: no hay prepago; en cada pedido entregado se calcula y registra una comisión
    (porcentaje configurado × importe de cobro del pedido). No hay saldo que descontar.

**C. Configuración de tarifas**

- Tarifa por banderazo: un valor único, global por tenant.
- Tarifa por kilómetro adicional: un valor único, global por tenant.
- Ambos son solo datos configurables en esta historia — no se aplican todavía al cálculo del
  importe de un pedido nuevo (eso es la spec 006 y queda fuera de alcance aquí).

**D. Zonas de cobertura con geofence**

- El admin_cliente puede crear una o más zonas de cobertura, cada una dibujada como un polígono
  sobre un mapa. Por ahora son solo datos guardados (nombre, estado, polígono) — no se usan todavía
  para validar si un pedido cae dentro o fuera de una zona (fuera de alcance).

**E. Entrada por el ícono de engranaje**

- El botón de engranaje ya existe visualmente en `UiNavbar.vue` (sin funcionalidad). Se conecta
  para llevar a la nueva pantalla de Configuración, visible en cualquier página del panel del
  tenant, pero **solo para el rol `AdminCliente`** (Despachadores y Conductores no lo ven).
- La pantalla de Configuración tiene tres secciones/pestañas: **Tarifas** (C), **Zonas de
  cobertura** (D) y **Comisión / Prepago** (B, la parte de modalidad global y su valor — la venta
  individual a cada conductor vive en Conductores, no aquí).

**No incluye (por ahora):**

- Ningún flujo de pago real ni pasarela — ni para que admin_central cobre a admin_cliente, ni para
  que el conductor pague su prepago. Todo es un registro de acreditación/venta de viajes.
- Aplicar el banderazo/km adicional al cálculo del importe en la creación de pedidos (spec 006).
- Usar las zonas de cobertura para validar si un pedido está dentro del área de servicio.
- Bloquear la asignación de pedidos a un conductor sin saldo prepagado disponible — solo se
  registra que no se pudo descontar.
- Historial de cambios de configuración (solo se guarda el valor vigente).

## Decisión técnica

### Por qué se reutiliza `compras_paquetes` en vez de crear una tabla nueva

La migración `compras_paquetes` (tenant) ya existe con exactamente las columnas que necesita un
registro de acreditación (`codigo_paquete`, `cantidad_paquetes`, `cantidad_viajes`,
`precio_unitario`, `importe_total`, `forma_pago`, `estado`, `fecha_compra`), pero no tiene modelo ni
controlador — es un stub sin usar (igual que `configuraciones_tenant` y `zonas_servicio`). Se le da
un modelo (`Tenant\CompraPaquete`) y se usa tal cual, sin agregar columnas: cuando admin_central
acredita, `forma_pago` queda `null` (no es una compra con método de pago, es una acreditación
directa) y `precio_unitario`/`importe_total` se completan con el precio del catálogo, solo para
referencia histórica de cuánto "valía" lo acreditado.

### Cómo escribe admin_central en la base de datos del tenant

Sigue el mismo patrón que `CrearAdminClienteInicial` (usado al dar de alta un tenant nuevo):
`tenancy()->initialize($tenant)` → crea el registro en `compras_paquetes` de esa base →
`tenancy()->end()`. Además, se registra un `LogCentral` (auditoría del lado admin_central) con
`tipo: 'PAQUETE'`, `accion: 'ACREDITACION'` — mismo patrón que ya usa
`PaqueteViajeController` para alta/edición/baja del catálogo.

No se guarda en `compras_paquetes` quién (qué admin_central) hizo la acreditación: las bases de
tenant están aisladas y no tienen forma de referenciar una fila de la base central. Ese dato vive
solo en `LogCentral`, del lado central.

### Saldos calculados, no contadores que se editan directamente

Ni el saldo del tenant ni el del conductor se guardan como un número que se suma/resta a mano (eso
se desincroniza fácil si algo falla a medio camino). Se calculan siempre a partir de las tablas de
movimientos, mismo espíritu que `LogCentral`/`Auditoria`: la fuente de verdad es la bitácora, no un
acumulador.

- **Saldo del tenant** = `SUM(compras_paquetes.cantidad_viajes)` − `SUM(ventas_viajes_conductor.cantidad_viajes)`.
- **Saldo de un conductor** = `SUM(ventas_viajes_conductor.cantidad_viajes)` para ese conductor −
  `COUNT(pedidos)` de ese conductor con `prepago_descontado = true`.

Vender viajes a un conductor valida que la cantidad no exceda el saldo disponible del tenant en ese
momento (calculado igual que arriba).

### Modalidad global, aplicada al momento de entregar el pedido

La modalidad (Prepago/Comisión) y sus valores viven en `configuraciones_tenant` (clave/valor, tabla
ya existente sin modelo — se le agrega `Tenant\ConfiguracionTenant`). El hook va en
`PedidoController@cambiarEstado`, en el mismo `match` que ya existe para setear fechas al cambiar de
estado: cuando el nuevo estado es `ENTREGADO` y el pedido tiene `id_conductor`,

- si la modalidad es `Prepago` y el conductor tiene saldo > 0 → se marca `prepago_descontado = true`
  en ese pedido (así el saldo calculado baja 1, sin tocar ningún contador).
- si la modalidad es `Comisión` → se calcula `comision_calculada = importe_cobro * (porcentaje / 100)`
  y se guarda en el propio pedido, usando el porcentaje vigente **en ese momento** (si luego cambia
  la configuración, los pedidos ya entregados no se recalculan).

### Geofence: `MapService`/`GoogleProvider` ya existen, pero sin soporte de polígonos

**Corrección** (esta afirmación original de la spec ya no es cierta): se anticipaban aquí las
specs `012-map-servide.md` y `013-google-service.md` para un servicio de mapas centralizado, y se
daba por hecho que ese código "todavía no existe" en `frontend/src/services/`. Esos dos archivos
nunca se crearon con ese nombre/numeración — en su lugar, el servicio de mapas se construyó
completo dentro de `tenant/009-mapa.md` (autocompletado, rutas reales, mapa de conductores), y hoy
sí existe en `frontend/src/services/maps/` (`MapService.ts`, `BaseProvider.ts`,
`GoogleProvider.ts`).

Lo que **no** es cierto tampoco es que su contrato exponga `getNativeMap()` como escape para casos
avanzados — ese método nunca se implementó. El contrato real de `MapService`
(`initialize`, `addMarker`, `updateMarker`, `clearMarkers`, `drawRoute`, `clearRoutes`,
`centerOn`, `searchAddress`, `resolveAddress`, `searchCity`, `resolveCity`, `fitToPositions`,
`destroy`) no incluye ningún método para dibujar o editar un polígono.

**Esto sigue bloqueando la parte visual de "dibujar en el mapa"** — ya no porque el servicio no
exista, sino porque su contrato no cubre polígonos todavía. Extender `BaseProvider`/
`GoogleProvider` con algo como `drawPolygon`/`DrawingManager` queda fuera de esta historia, sin
spec numerada todavía (se documentará cuando se aborde). El resto de esta spec (backend de zonas,
CRUD sin mapa, acreditación, reventa, tarifas, comisión) no depende de eso y puede construirse de
forma independiente.

## Reglas de negocio

1. No se puede vender a un conductor más viajes que el saldo disponible del tenant.
2. Un pedido solo descuenta saldo prepagado (o calcula comisión) al pasar a `ENTREGADO`, y solo una
   vez (columna `prepago_descontado` evita doble descuento si el estado se vuelve a consultar).
3. Si la modalidad es Prepago y el conductor no tiene saldo, el pedido igual puede marcarse
   `ENTREGADO` (no se bloquea): simplemente no se descuenta nada.
4. Cambiar la modalidad global o los porcentajes/tarifas no recalcula pedidos ya entregados.
5. Solo el rol `AdminCliente` puede leer/escribir configuración, acreditar (eso es admin_central) o
   vender viajes a conductores.

## Backend (Laravel)

### Central (base `delivery_central`)

- **`Admin\CreditoPaqueteController@store`** — `POST /admin/tenants/{tenant}/creditos-paquetes`,
  body `{ id_paquete, cantidad_paquetes }`. Valida que el paquete esté `Activo`. Usa
  `tenancy()->initialize($tenant)` para crear el `Tenant\CompraPaquete` en la base del tenant
  (`cantidad_viajes = paquete.cantidad_viajes * cantidad_paquetes`, `precio_unitario =
  paquete.precio`, `importe_total = precio_unitario * cantidad_paquetes`, `forma_pago = null`,
  `estado = 'Activo'`, `fecha_compra = now()`), luego `tenancy()->end()`. Registra `LogCentral`
  (`tipo: 'PAQUETE'`, `accion: 'ACREDITACION'`). Protegido con `auth:admin` +
  `throttle:admin-tenants` (mismo grupo de middlewares que el resto de `TenantController`).

### Tenant (por cada base de tenant)

- **Migración**: agrega columna `poligono` (`json`, nullable) a `zonas_servicio`.
- **Migración**: agrega columnas `prepago_descontado` (`boolean`, default `false`) y
  `comision_calculada` (`decimal(10,2)`, nullable) a `pedidos`.
- **Migración nueva**: tabla `ventas_viajes_conductor` — `id_venta` (PK), `id_conductor` (FK
  `conductores`), `cantidad_viajes` (unsignedInteger), `id_usuario` (FK `usuarios`, quién de
  admin_cliente hizo la venta), `fecha_venta` (dateTime), timestamps.
- **Modelos nuevos**: `Tenant\CompraPaquete` (tabla `compras_paquetes`, ya migrada),
  `Tenant\VentaViajeConductor` (tabla `ventas_viajes_conductor`), `Tenant\ConfiguracionTenant`
  (tabla `configuraciones_tenant`, con helpers estáticos `obtener(clave, default)` /
  `establecer(clave, valor)`), `Tenant\ZonaServicio` (tabla `zonas_servicio`).
- **`Tenant\ConfiguracionController`** (`rol.tenant:AdminCliente`):
  - `GET /t/{slug}/configuracion` — devuelve `tarifa_banderazo`, `tarifa_km_adicional`,
    `modalidad_conductores` (`Prepago`|`Comision`), `costo_viaje_prepago`, `comision_porcentaje`, y
    el saldo actual del tenant (calculado).
  - `PUT /t/{slug}/configuracion` — actualiza cualquiera de esas claves (valida rangos: tarifas y
    costo ≥ 0, porcentaje entre 0 y 100).
- **`Tenant\ZonaCoberturaController`** (`rol.tenant:AdminCliente`), CRUD estándar sobre
  `zonas_servicio` (`index`, `store`, `show`, `update`, `cambiarEstado`) — mismo patrón que
  `VehiculoController`/`ClienteController`. `poligono` se valida como arreglo de `{lat, lng}` con al
  menos 3 puntos.
- **`Tenant\VentaViajeConductorController@store`** (`rol.tenant:AdminCliente`) —
  `POST /t/{slug}/conductores/{conductor}/vender-viajes`, body `{ cantidad_viajes }`. Valida que no
  exceda el saldo del tenant; crea el registro en `ventas_viajes_conductor`.
- **`Tenant\ConductorController`**: se agrega `GET /t/{slug}/conductores/{conductor}/saldo-viajes`
  (saldo calculado de ese conductor).
- **`PedidoController@cambiarEstado`**: en el `match` existente (líneas 167-173 actuales), se
  agrega el hook descrito en "Decisión técnica" para `ENTREGADO` con `id_conductor` presente.

## Frontend (Vue 3)

- **`views/admin/tenants/DetalleTenantView.vue`**: agrega sección "Acreditar paquete" (selector de
  paquete del catálogo + cantidad + botón "Acreditar"), llama al nuevo endpoint y muestra
  confirmación.
- **`components/ui/UiNavbar.vue`**: agrega prop `mostrarConfiguracion?: boolean` (default `true`,
  para no romper `AdminLayout`, que sigue sin escuchar el evento) y evento `click-configuracion` en
  el botón de engranaje existente.
- **`layouts/TenantLayout.vue`**: pasa `:mostrar-configuracion="usuario?.rol === 'AdminCliente'"` y,
  al recibir `@click-configuracion`, navega a `tenant-configuracion`.
- **`views/tenant/configuracion/ConfiguracionView.vue`** (nueva): tres pestañas —
  - **Tarifas**: formulario con banderazo y tarifa por km adicional.
  - **Comisión / Prepago**: selector de modalidad (Prepago/Comisión) + el valor correspondiente
    (costo del viaje prepago, o porcentaje de comisión) + saldo actual del tenant (solo lectura).
  - **Zonas de cobertura**: lista de zonas + mapa para dibujar/editar el polígono (bloqueado hasta
    que exista `MapService`/`GoogleProvider`, ver "Decisión técnica"; mientras tanto la lista y el
    CRUD de nombre/estado funcionan sin el picker visual).
- **`views/tenant/conductores/ConductoresListaView.vue`**: agrega acción "Vender viajes" por fila
  (visible solo si `usuario.rol === 'AdminCliente'` y la modalidad del tenant es `Prepago`) que abre
  un modal con cantidad a vender y muestra el saldo del conductor y del tenant.
- **Router** (`frontend/src/router/index.ts`): nueva ruta `/t/:slug/panel/configuracion`, nombre
  `tenant-configuracion`, `meta: { requiresTenantAuth: true }`; en el `beforeEach`, mismo patrón que
  ya existe para `tenant-panel`, se agrega: si `to.name === 'tenant-configuracion'` y
  `auth.usuario?.rol !== 'AdminCliente'`, redirige fuera.

## Fuera de alcance

- Cualquier pasarela de pago real (admin_central↔admin_cliente o admin_cliente↔conductor).
- Aplicar banderazo/km adicional al importe de un pedido nuevo (spec 006).
- Usar el geofence para validar cobertura al crear un pedido.
- Bloquear pedidos por falta de saldo prepagado.
- El `DrawingManager` de Google Maps en sí: `MapService`/`GoogleProvider` (`tenant/009-mapa.md`)
  ya existen, pero su contrato no incluye dibujar/editar polígonos todavía (ver "Decisión
  técnica"). La pestaña de Zonas de cobertura queda con su CRUD de nombre/estado listo; el picker
  visual queda para una historia futura, sin spec numerada aún.
- Historial/bitácora visual de cambios de configuración (solo se guarda el valor vigente en
  `configuraciones_tenant`; sí queda auditado quién acreditó vía `LogCentral` y quién vendió a un
  conductor vía `ventas_viajes_conductor.id_usuario`).

## Criterios de aceptación

1. Desde `DetalleTenantView.vue`, admin_central puede acreditar un paquete a un tenant; el saldo de
   viajes de ese tenant sube en la cantidad correspondiente.
2. El ícono de engranaje del navbar, visible solo para `AdminCliente`, lleva a
   `/t/{slug}/panel/configuracion`.
3. En la pestaña Tarifas, el admin_cliente guarda banderazo y tarifa por km adicional; los valores
   persisten y se recargan correctamente.
4. En la pestaña Comisión/Prepago, el admin_cliente elige la modalidad global y su valor (costo del
   viaje o porcentaje); el cambio aplica para todo el tenant, no por conductor.
5. Desde la lista de conductores, con modalidad Prepago, el admin_cliente vende viajes a un
   conductor; el saldo del tenant baja y el del conductor sube en la misma cantidad.
6. Vender más viajes de los que el tenant tiene disponibles muestra un error y no se ejecuta.
7. Al marcar un pedido como `ENTREGADO` con conductor asignado: si la modalidad es Prepago y el
   conductor tenía saldo, su saldo baja en 1; si es Comisión, el pedido guarda el importe de
   comisión calculado con el porcentaje vigente.
8. Un conductor sin saldo prepagado puede seguir recibiendo pedidos marcados `ENTREGADO`; solo no
   se le descuenta nada.
9. El CRUD de zonas de cobertura (nombre, descripción, estado) funciona de punta a punta aunque el
   picker visual del polígono todavía no exista.
10. Ningún rol distinto a `AdminCliente` puede acceder a los endpoints ni a la pantalla de
    Configuración, ni a "Vender viajes".
11. ESLint/Prettier y los tests de backend existentes siguen pasando.

## Supuestos asumidos (registro completo)

1. "Repartidores" se refiere a los Conductores ya existentes (tabla `conductores`), no a un tipo de
   usuario nuevo.
2. La acreditación de admin_central hacia el tenant es **manual**: desde `DetalleTenantView.vue`,
   eligiendo un paquete del catálogo y confirmando; se implementa el flujo completo (endpoint,
   escritura cross-DB vía `tenancy()->initialize()`, auditoría en `LogCentral`) — no se asume que ya
   exista ni se deja fuera de alcance.
3. "Revender viajes a los repartidores" = el conductor tiene un saldo prepagado de viajes,
   descontado del saldo del tenant, que se consume 1 a 1 según entrega pedidos.
4. El esquema de "comisión por porcentaje" es una modalidad **alternativa** al prepago, no
   simultánea para el mismo conductor.
5. La modalidad (Prepago o Comisión) es **global para todo el tenant**, no configurable por
   conductor individual.
6. La tarifa por banderazo es un valor único global por tenant.
7. La tarifa por kilómetro adicional es un valor único global por tenant.
8. Las zonas de cobertura con geofence definen el área de servicio; no implican tarifas
   diferenciadas por zona.
9. El geofence se define dibujando uno o más polígonos sobre un mapa, guardado como lista de
   coordenadas; puede haber múltiples zonas por tenant.
10. "El costo del viaje o el porcentaje de comisión a los repartidores" es la misma configuración
    de modalidad: costo del viaje (si Prepago) o porcentaje (si Comisión).
11. Todas las configuraciones (tarifas, zonas, comisión/prepago) viven en una sola pantalla de
    Configuración, con pestañas.
12. El ícono de engranaje ya existente en `UiNavbar.vue` (hoy sin funcionalidad) se conecta a esta
    pantalla; visible en todas las páginas del panel del tenant, solo para `AdminCliente`.
13. Solo el rol `AdminCliente` puede acceder a la pantalla de Configuración y a "Vender viajes".
14. Esta historia no incluye aplicar el banderazo/km adicional al cálculo del importe en la
    creación de pedidos (spec 006) — solo la configuración y el guardado de esos valores.
15. Esta historia no incluye cobro real (pasarela de pago) del prepago del conductor ni de la venta
    de paquetes — solo el registro de los movimientos.
16. La pestaña de Zonas de cobertura depende de que se extienda el contrato de `MapService`/
    `GoogleProvider` (ya existen, ver `tenant/009-mapa.md`) con soporte para dibujar polígonos —
    hoy no lo tienen. El CRUD de zonas sin el picker visual del mapa no depende de eso y se
    construye igual.
