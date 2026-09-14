# Spec: Configuración del tenant — acreditación de paquetes, reventa de viajes a conductores por monto pagado, tarifas y geofence

## Historia de usuario

Actualmente admin_central vende paquetes de viajes a los admin_cliente. Como admin_cliente quiero
poder revender viajes a los repartidores (conductores) para que paguen sus viajes por adelantado.
También establecer un esquema de comisión por porcentaje de viaje.

Como admin_cliente quiero un manejo más efectivo de los créditos que le doy a mis conductores: en
vez de indicar directamente cuántos viajes acreditarle a un conductor, quiero capturar el monto en
dinero que el conductor me pagó, y que el sistema calcule por sí solo a cuántos viajes equivale ese
monto y se los acredite. También quiero poder consultar, por conductor y para todo mi negocio, un
historial de esos pagos.

También, como admin_cliente quiero poder configurar mi sistema:

1. Establecer tarifas de viaje por banderazo.
2. Establecer un mínimo de kilómetros incluidos en ese banderazo, antes de que se empiece a cobrar
   el kilómetro adicional.
3. Establecer tarifas por kilómetro adicional.
4. Establecer zonas de cobertura con geofence.
5. Establecer el costo del viaje o el porcentaje de comisión a los repartidores.

El acceso se ubica en el ícono de engranaje que está junto a la campanita del navbar, y lleva a una
pantalla de configuración.

## Objetivo / Alcance

Esta historia cubre cinco piezas conectadas entre sí:

**A. Acreditación manual de paquetes (admin_central → tenant)**

- En `DetalleTenantView.vue` (panel de admin_central), se agrega la acción "Acreditar paquete":
  el admin_central elige un paquete del catálogo (`paquetes_viajes`) y una cantidad, y lo acredita
  al tenant. Esto suma viajes al saldo disponible del tenant.
- No hay flujo de pago ni de solicitud: es una acreditación directa hecha por el equipo central,
  igual de manual que hoy es la gestión del catálogo de paquetes (spec 009).

**B. Reventa de viajes prepagados a conductores, por monto pagado (tenant → conductores)**

- El admin_cliente acredita viajes prepagados a un conductor desde `ConductoresListaView.vue`
  capturando el **monto en dinero** que el conductor le pagó — ya no la cantidad de viajes
  directamente.
- El sistema convierte ese monto a viajes usando el precio configurado en la modalidad Prepago
  (`costo_viaje_prepago`, ver punto C más abajo): `cantidad_viajes = piso(monto_pagado /
  costo_viaje_prepago)`. Solo se acreditan viajes completos: si el monto no alcanza exactamente
  para un número entero de viajes, ese remanente no se acredita ni se guarda como saldo a favor
  para una carga futura — se pierde.
- Esos viajes se descuentan del saldo de viajes del tenant y se suman al saldo prepagado del
  conductor, igual que antes.
- El saldo de viajes de cada conductor es visible directamente como columna en la tabla de
  `ConductoresListaView.vue` (antes solo se veía al abrir el modal "Vender viajes"), para que el
  admin_cliente pueda revisar de un vistazo cuánto le queda a cada conductor conforme lo va
  gastando, sin necesidad de abrir nada por fila.
- Si el tenant no tiene configurado `costo_viaje_prepago` (o está en `0`), no se puede acreditar
  por monto: se bloquea con un mensaje pidiendo configurarlo primero en la pantalla de
  Configuración.
- Cada conductor consume su saldo automáticamente: cada pedido que entrega descuenta 1 viaje.
- De forma **global para todo el tenant** (no por conductor), el admin_cliente elige una sola
  modalidad de cobro a sus conductores:
  - **Prepago**: los conductores compran viajes por adelantado (lo descrito arriba); el
    "costo del viaje" configurado es el precio que se les cobra por cada viaje prepagado, y también
    el que se usa para convertir el monto pagado a cantidad de viajes.
  - **Comisión**: no hay prepago; en cada pedido entregado se calcula y registra una comisión
    (porcentaje configurado × importe de cobro del pedido). No hay saldo que descontar ni pagos que
    registrar.

**C. Configuración de tarifas**

- Tarifa por banderazo: un valor único, global por tenant.
- Kilómetros incluidos en el banderazo: un valor único, global por tenant — la cantidad de
  kilómetros que ya cubre el banderazo antes de que se empiece a cobrar el kilómetro adicional.
  Estrictamente mayor a cero: no se acepta guardar `0` (si el tenant no quiere ofrecer kilómetros
  incluidos, esta tarifa simplemente no aplica a su operación, pero el campo no admite ese valor).
- Tarifa por kilómetro adicional: un valor único, global por tenant.
- Las tres son solo datos configurables en esta historia — no se aplican todavía al cálculo del
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

**F. Historial de pagos de conductores**

- Por cada conductor, el admin_cliente puede consultar la lista completa de pagos que ese
  conductor le ha hecho: fecha, monto pagado y cuántos viajes se acreditaron en esa ocasión — junto
  con el total acumulado pagado por ese conductor.
- También existe una vista a nivel de todo el tenant que junta los pagos de todos los conductores,
  con su total general, útil para llevar la contabilidad del negocio.
- Un pago ya registrado no se puede editar ni eliminar desde esta historia.

**No incluye (por ahora):**

- Ningún flujo de pago real ni pasarela — ni para que admin_central cobre a admin_cliente, ni para
  que el conductor pague su prepago. El monto que captura el admin_cliente es solo el registro de
  lo que recibió fuera del sistema (efectivo, transferencia, etc.).
- Acumular o conservar el remanente de un monto que no alcanza para un viaje completo.
- Editar o eliminar un pago ya registrado.
- Filtros avanzados (rango de fechas, exportación) en el reporte de pagos a nivel tenant — solo
  lista todos los pagos con su total.
- Aplicar el banderazo, los kilómetros incluidos o el km adicional al cálculo del importe en la
  creación de pedidos (spec 006).
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

- ~~**Saldo del tenant** = `SUM(compras_paquetes.cantidad_viajes)` − `SUM(ventas_viajes_conductor.cantidad_viajes)`.~~
  **Reemplazado** por la corrección "el saldo del tenant se consume por envío entregado" (abajo):
  `SUM(compras_paquetes.cantidad_viajes)` − `COUNT(consumos_viajes_tenant)`. La venta a conductores
  ya no lo descuenta; sigue validándose contra él (RN-1).
- **Saldo de un conductor** = `SUM(ventas_viajes_conductor.cantidad_viajes)` para ese conductor −
  `COUNT(pedidos)` de ese conductor con `prepago_descontado = true`.

Vender viajes a un conductor valida que la cantidad **resultante de la conversión** no exceda el
saldo disponible del tenant en ese momento (calculado igual que arriba). El monto pagado por el
conductor no participa en esta validación — solo determina cuántos viajes se calculan.

### Por qué el saldo del conductor se agrega al índice paginado en vez de pedirlo por fila

`ConductorController@index` ya devuelve la lista paginada que alimenta la tabla de
`ConductoresListaView.vue`. Para mostrar el saldo como columna sin disparar una petición
`GET /t/{slug}/conductores/{conductor}/saldo-viajes` por cada fila visible (N+1), la misma fórmula
de "Saldo de un conductor" se resuelve en una sola consulta agregada por página, usando
`withSum('ventasViajes as viajes_vendidos', 'cantidad_viajes')` (requiere la relación
`Conductor::ventasViajes()`, nueva) y `withCount(['pedidos as viajes_consumidos' => fn ($q) =>
$q->where('prepago_descontado', true)])`. `ConductorResource` expone `saldo_viajes =
viajes_vendidos - viajes_consumidos` (con `?? 0` si el conductor no tiene ventas todavía).

El endpoint `saldo-viajes` por conductor (usado por el modal "Vender viajes") no se elimina: sigue
sirviendo para refrescar el saldo justo después de acreditar un pago, sin tener que re-listar toda
la tabla paginada.

`saldo_viajes` se calcula y se manda **siempre** en la respuesta del índice, sin condicionar al
valor de `modalidad_conductores` — es barato de calcular junto con el resto de la fila, y así
`ConductoresListaView.vue` decide con un simple `v-if` (igual que ya hace con la columna
"Despachador" y `usaDespachadores`) cuándo mostrar la columna, sin necesitar un segundo viaje al
backend para saber la modalidad antes de pedir el saldo.

### Por qué se agrega `monto_pagado` a `ventas_viajes_conductor` en vez de crear una tabla nueva

Cada fila de `ventas_viajes_conductor` ya representa una acreditación puntual (una "venta").
Agregarle una columna `monto_pagado` (`decimal(10,2)`) es suficiente para tener el historial de
pagos sin duplicar información: el historial de un conductor es simplemente sus filas de
`ventas_viajes_conductor` ordenadas por `fecha_venta`, y el reporte de todo el tenant es la misma
tabla sin filtrar por conductor.

### Conversión de monto a viajes

`cantidad_viajes` deja de ser un input directo del usuario en este flujo: se calcula en el backend
como `floor(monto_pagado / costo_viaje_prepago)` en el momento de guardar la venta, usando el
`costo_viaje_prepago` vigente en ese instante (si luego cambia la tarifa, las ventas ya registradas
no se recalculan — mismo criterio que ya se usa para la comisión en pedidos). Si
`costo_viaje_prepago` es `0` o no está configurado, o si el monto no alcanza para al menos 1 viaje,
el endpoint rechaza la petición con un mensaje explicando por qué.

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
CRUD sin mapa, acreditación, reventa, tarifas, comisión, historial de pagos) no depende de eso y
puede construirse de forma independiente.

## Reglas de negocio

1. No se puede vender a un conductor una cantidad de viajes (ya calculada a partir del monto) que
   exceda el saldo disponible del tenant.
2. Un pedido solo descuenta saldo prepagado (o calcula comisión) al pasar a `ENTREGADO`, y solo una
   vez (columna `prepago_descontado` evita doble descuento si el estado se vuelve a consultar).
   **Corrección (abajo, RN-C2):** en ese mismo momento el envío consume también 1 viaje del saldo
   del tenant, en cualquier modalidad, una sola vez (`UNIQUE` en `consumos_viajes_tenant`).
3. Si la modalidad es Prepago y el conductor no tiene saldo, el pedido igual puede marcarse
   `ENTREGADO` (no se bloquea): simplemente no se descuenta nada.
4. Cambiar la modalidad global o los porcentajes/tarifas no recalcula pedidos ya entregados.
5. Solo el rol `AdminCliente` puede leer/escribir configuración, acreditar (eso es admin_central),
   vender viajes a conductores, o consultar el historial/reporte de pagos.
6. El monto pagado por el conductor se convierte a viajes truncando hacia abajo (`piso`); el
   remanente que no alcance para un viaje completo no se acredita ni se acumula para una carga
   futura.
7. No se puede acreditar viajes por monto si el tenant no tiene configurado `costo_viaje_prepago`
   mayor a `0`, ni si el monto ingresado no alcanza para al menos 1 viaje completo.
8. Todo pago queda registrado de forma permanente (monto, fecha, viajes resultantes, conductor); no
   existe edición ni eliminación de un pago ya registrado desde esta historia — corregir un error
   requeriría un ajuste manual fuera de este flujo.

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
- **Migración**: tabla `ventas_viajes_conductor` — `id_venta` (PK), `id_conductor` (FK
  `conductores`), `cantidad_viajes` (unsignedInteger), `monto_pagado` (`decimal(10,2)`, el monto en
  dinero que el conductor pagó y a partir del cual se calculó `cantidad_viajes`), `id_usuario` (FK
  `usuarios`, quién de admin_cliente hizo la venta), `fecha_venta` (dateTime), timestamps.
- **Modelos**: `Tenant\CompraPaquete` (tabla `compras_paquetes`, ya migrada),
  `Tenant\VentaViajeConductor` (tabla `ventas_viajes_conductor`), `Tenant\ConfiguracionTenant`
  (tabla `configuraciones_tenant`, con helpers estáticos `obtener(clave, default)` /
  `establecer(clave, valor)`), `Tenant\ZonaServicio` (tabla `zonas_servicio`). `Tenant\Conductor` se
  amplía con la relación `ventasViajes(): HasMany` (hacia `VentaViajeConductor`, análoga a la ya
  existente `pedidos()`).
- **`Tenant\ConfiguracionController`** (`rol.tenant:AdminCliente`):
  - `GET /t/{slug}/configuracion` — devuelve `tarifa_banderazo`, `km_incluidos_banderazo`,
    `tarifa_km_adicional`, `modalidad_conductores` (`Prepago`|`Comision`), `costo_viaje_prepago`,
    `comision_porcentaje`, y el saldo actual del tenant (calculado).
  - `PUT /t/{slug}/configuracion` — actualiza cualquiera de esas claves (valida rangos:
    `tarifa_banderazo`/`tarifa_km_adicional`/costo ≥ 0, `km_incluidos_banderazo` estrictamente mayor
    a cero —no acepta `0`—, porcentaje entre 0 y 100).
- **`Tenant\ZonaCoberturaController`** (`rol.tenant:AdminCliente`), CRUD estándar sobre
  `zonas_servicio` (`index`, `store`, `show`, `update`, `cambiarEstado`) — mismo patrón que
  `VehiculoController`/`ClienteController`. `poligono` se valida como arreglo de `{lat, lng}` con al
  menos 3 puntos.
- **`Tenant\VentaViajeConductorController@store`** (`rol.tenant:AdminCliente`) —
  `POST /t/{slug}/conductores/{conductor}/vender-viajes`, body `{ monto_pagado }`. Calcula
  `cantidad_viajes = floor(monto_pagado / costo_viaje_prepago)`; rechaza si `costo_viaje_prepago`
  no está configurado o es `0`, si el resultado es `0` viajes, o si excede el saldo del tenant.
  Crea el registro en `ventas_viajes_conductor` con `monto_pagado`, `cantidad_viajes`, `id_usuario` y
  `fecha_venta`.
- **`Tenant\VentaViajeConductorController@historialConductor`** (`rol.tenant:AdminCliente`) —
  `GET /t/{slug}/conductores/{conductor}/historial-pagos`. Devuelve la lista de filas de
  `ventas_viajes_conductor` de ese conductor (`fecha_venta`, `monto_pagado`, `cantidad_viajes`),
  ordenadas de más reciente a más antigua, más `total_pagado` (suma de `monto_pagado`).
- **`Tenant\VentaViajeConductorController@reportePagos`** (`rol.tenant:AdminCliente`) —
  `GET /t/{slug}/reportes/pagos-conductores`. Devuelve todas las filas de
  `ventas_viajes_conductor` de todos los conductores del tenant (conductor, fecha, monto, viajes),
  ordenadas de más reciente a más antigua, más `total_general` (suma de todos los `monto_pagado`).
- **`Tenant\ConductorController`**: se agrega `GET /t/{slug}/conductores/{conductor}/saldo-viajes`
  (saldo calculado de ese conductor). Además, `index` (el listado paginado ya existente) agrega
  `withSum`/`withCount` a su query para incluir `saldo_viajes` por fila sin N+1 (ver "Decisión
  técnica"), y `ConductorResource` expone ese campo.
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
  - **Tarifas**: formulario con banderazo, kilómetros incluidos en el banderazo y tarifa por km
    adicional, en ese orden.
  - **Comisión / Prepago**: selector de modalidad (Prepago/Comisión) + el valor correspondiente
    (costo del viaje prepago, o porcentaje de comisión) + saldo actual del tenant (solo lectura).
  - **Zonas de cobertura**: lista de zonas + mapa para dibujar/editar el polígono (bloqueado hasta
    que exista `MapService`/`GoogleProvider`, ver "Decisión técnica"; mientras tanto la lista y el
    CRUD de nombre/estado funcionan sin el picker visual).
- **`views/tenant/conductores/ConductoresListaView.vue`**:
  - Agrega acción "Vender viajes" por fila (visible solo si `usuario.rol === 'AdminCliente'` y la
    modalidad del tenant es `Prepago`) que abre un modal pidiendo el **monto pagado ($)** por el
    conductor. Al confirmar, muestra cuántos viajes resultaron acreditados (ej. "Se acreditaron 5
    viajes por $500.00") y el saldo actualizado del conductor y del tenant. Si el monto no alcanza
    para 1 viaje, o si `costo_viaje_prepago` no está configurado, o si excede el saldo del tenant,
    se muestra el error correspondiente sin ejecutar la acción. Al confirmar con éxito, se vuelve a
    pedir el listado completo (`fetchConductores()`) para que la columna "Saldo de viajes" quede al
    día, en vez de parchear solo la fila en memoria.
  - Agrega acción "Historial de pagos" por fila (misma visibilidad que "Vender viajes") que abre un
    modal con la lista de pagos de ese conductor (fecha, monto, viajes acreditados) y el total
    pagado acumulado.
  - Agrega columna "Saldo de viajes" en la tabla (`{{ conductor.saldo_viajes }} viaje(s)`), visible
    para cualquier rol que vea esta tabla — no solo `AdminCliente` — pero solo cuando la modalidad
    del tenant es `Prepago` (mismo patrón condicional que ya usa la columna "Despachador" con
    `usaDespachadores`); en modalidad `Comisión` la columna no se muestra. El valor viene ya
    calculado en la respuesta del listado (campo `saldo_viajes` de `ConductorResource`), sin
    petición adicional por fila.
- **`views/tenant/reportes/PagosConductoresView.vue`** (nueva): lista todos los pagos de todos los
  conductores del tenant (conductor, fecha, monto, viajes) con el total general pagado al final.
  Accesible solo para `AdminCliente`.
- **Router** (`frontend/src/router/index.ts`):
  - Nueva ruta `/t/:slug/panel/configuracion`, nombre `tenant-configuracion`,
    `meta: { requiresTenantAuth: true }`.
  - Nueva ruta `/t/:slug/panel/reportes/pagos-conductores`, nombre
    `tenant-reporte-pagos-conductores`, `meta: { requiresTenantAuth: true }`.
  - En el `beforeEach`, mismo patrón que ya existe para `tenant-panel`: si `to.name` es
    `tenant-configuracion` o `tenant-reporte-pagos-conductores` y
    `auth.usuario?.rol !== 'AdminCliente'`, redirige fuera.

## Fuera de alcance

- Cualquier pasarela de pago real (admin_central↔admin_cliente o admin_cliente↔conductor); el
  monto capturado es solo el registro de lo recibido fuera del sistema.
- Acumular o conservar el remanente de un monto que no alcanza para un viaje completo.
- Editar o eliminar un pago ya registrado en `ventas_viajes_conductor`.
- Filtros avanzados (rango de fechas, exportación) en el reporte de pagos a nivel tenant.
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
3. En la pestaña Tarifas, el admin_cliente guarda banderazo, kilómetros incluidos en el banderazo
   y tarifa por km adicional; los valores persisten y se recargan correctamente.
4. En la pestaña Comisión/Prepago, el admin_cliente elige la modalidad global y su valor (costo del
   viaje o porcentaje); el cambio aplica para todo el tenant, no por conductor.
5. Desde la lista de conductores, con modalidad Prepago, el admin_cliente acredita viajes a un
   conductor capturando el monto que este pagó; el sistema calcula `piso(monto /
   costo_viaje_prepago)` viajes, el saldo del tenant baja y el del conductor sube en esa cantidad.
6. Acreditar un monto que resulte en más viajes de los que el tenant tiene disponibles muestra un
   error y no se ejecuta.
7. Intentar acreditar por monto cuando `costo_viaje_prepago` no está configurado (o es `0`), o
   cuando el monto no alcanza para 1 viaje completo, muestra un error y no se ejecuta.
8. Al marcar un pedido como `ENTREGADO` con conductor asignado: si la modalidad es Prepago y el
   conductor tenía saldo, su saldo baja en 1; si es Comisión, el pedido guarda el importe de
   comisión calculado con el porcentaje vigente.
9. Un conductor sin saldo prepagado puede seguir recibiendo pedidos marcados `ENTREGADO`; solo no
   se le descuenta nada.
10. Desde "Historial de pagos" de un conductor, se listan todos sus pagos (fecha, monto, viajes) y
    el total mostrado coincide con la suma de los montos registrados.
11. El reporte `PagosConductoresView.vue` lista los pagos de todos los conductores del tenant y su
    total general coincide con la suma de todos los pagos registrados.
12. El CRUD de zonas de cobertura (nombre, descripción, estado) funciona de punta a punta aunque el
    picker visual del polígono todavía no exista.
13. Ningún rol distinto a `AdminCliente` puede acceder a los endpoints ni a las pantallas de
    Configuración, "Vender viajes", historial de pagos o reporte de pagos.
14. ESLint/Prettier y los tests de backend existentes siguen pasando.
15. Con modalidad `Prepago`, la tabla de conductores muestra una columna "Saldo de viajes" con el
    saldo calculado de cada conductor (viajes vendidos − viajes consumidos), visible para cualquier
    rol que acceda a esa tabla.
16. Con modalidad `Comisión`, la columna "Saldo de viajes" no aparece en la tabla.
17. Después de acreditar un pago con "Vender viajes", el valor de la columna "Saldo de viajes" de
    ese conductor queda actualizado sin recargar la página manualmente.
18. `GET /t/{slug}/conductores` (listado paginado) resuelve `saldo_viajes` para todos los
    conductores de la página con consultas agregadas (`withSum`/`withCount`), no con una consulta
    adicional por conductor.

## Supuestos asumidos (registro completo)

1. "Repartidores" se refiere a los Conductores ya existentes (tabla `conductores`), no a un tipo de
   usuario nuevo.
2. La acreditación de admin_central hacia el tenant es **manual**: desde `DetalleTenantView.vue`,
   eligiendo un paquete del catálogo y confirmando; se implementa el flujo completo (endpoint,
   escritura cross-DB vía `tenancy()->initialize()`, auditoría en `LogCentral`).
3. "Revender viajes a los repartidores" = el conductor tiene un saldo prepagado de viajes,
   descontado del saldo del tenant, que se consume 1 a 1 según entrega pedidos. La acreditación se
   hace capturando el **monto en dinero** que el conductor pagó, no la cantidad de viajes
   directamente; el sistema calcula los viajes resultantes.
4. La conversión de monto a viajes trunca hacia abajo (`piso`): solo se acreditan viajes completos,
   y el remanente que no alcanza para un viaje completo no se acredita ni se acumula para una carga
   futura.
5. ~~El saldo de viajes del tenant se sigue calculando y validando igual que antes
   (`SUM(compras_paquetes.cantidad_viajes) - SUM(ventas_viajes_conductor.cantidad_viajes)`); el
   monto pagado por el conductor no afecta ese cálculo, solo determina cuántos viajes se acreditan.~~
   Reemplazado por el supuesto 30 (corrección de abajo): el saldo del tenant baja por envío
   entregado, no por venta.
6. Cada acreditación registra también el monto pagado por el conductor (no solo la cantidad de
   viajes), formando un historial de pagos consultable por conductor y para todo el tenant.
7. El esquema de "comisión por porcentaje" es una modalidad **alternativa** al prepago, no
   simultánea para el mismo conductor.
8. La modalidad (Prepago o Comisión) es **global para todo el tenant**, no configurable por
   conductor individual.
9. La tarifa por banderazo es un valor único global por tenant.
10. Los kilómetros incluidos en el banderazo son un valor único global por tenant, **obligatorio**
    para poder crear pedidos y estrictamente mayor a cero — no se acepta guardar `0` (a diferencia
    de banderazo y km adicional, que sí aceptan `0` como valor configurado a propósito).
11. La tarifa por kilómetro adicional es un valor único global por tenant.
12. Las zonas de cobertura con geofence definen el área de servicio; no implican tarifas
    diferenciadas por zona.
13. El geofence se define dibujando uno o más polígonos sobre un mapa, guardado como lista de
    coordenadas; puede haber múltiples zonas por tenant.
14. "El costo del viaje o el porcentaje de comisión a los repartidores" es la misma configuración
    de modalidad: costo del viaje (si Prepago, y también usado para convertir monto a viajes) o
    porcentaje (si Comisión).
15. Todas las configuraciones (tarifas, zonas, comisión/prepago) viven en una sola pantalla de
    Configuración, con pestañas.
16. El ícono de engranaje ya existente en `UiNavbar.vue` (hoy sin funcionalidad) se conecta a esta
    pantalla; visible en todas las páginas del panel del tenant, solo para `AdminCliente`.
17. Solo el rol `AdminCliente` puede acceder a la pantalla de Configuración, a "Vender viajes" y al
    historial/reporte de pagos.
18. Esta historia no incluye aplicar el banderazo, los kilómetros incluidos o el km adicional al
    cálculo del importe en la creación de pedidos (spec 006) — solo la configuración y el guardado
    de esos valores.
19. Esta historia no incluye cobro real (pasarela de pago) del prepago del conductor ni de la venta
    de paquetes — el monto capturado es solo el registro de lo que el tenant recibió fuera del
    sistema.
20. La pestaña de Zonas de cobertura depende de que se extienda el contrato de `MapService`/
    `GoogleProvider` (ya existen, ver `tenant/009-mapa.md`) con soporte para dibujar polígonos —
    hoy no lo tienen. El CRUD de zonas sin el picker visual del mapa no depende de eso y se
    construye igual.
21. El monto pagado por el conductor está en la misma moneda que `costo_viaje_prepago`; no hay
    conversión de divisas ni tipo de cambio.
22. Un pago ya registrado no se puede editar ni eliminar desde esta historia; corregir un error
    requeriría un ajuste manual fuera de este flujo.
23. El reporte de pagos a nivel tenant no incluye filtros avanzados (rango de fechas, exportación)
    más allá de listar todos los pagos de todos los conductores con su total general.
24. El saldo de viajes de un conductor, visible hoy solo dentro del modal "Vender viajes", se agrega
    también como columna de solo lectura en la tabla principal de conductores
    (`ConductoresListaView.vue`), para verlo de un vistazo sin abrir nada por fila.
25. Esa columna se muestra u oculta según la modalidad global del tenant (visible en `Prepago`,
    oculta en `Comisión`), igual que ya ocurre con la columna "Despachador" y `usar_despachadores`
    — no es una preferencia por conductor ni por usuario.
26. La columna es de solo lectura y visible para cualquier rol con acceso a la tabla de conductores,
    a diferencia de las acciones "Vender viajes"/"Historial de pagos", que sí siguen restringidas a
    `AdminCliente`.
27. "Según se van gastando" no implica actualización en tiempo real (WebSocket/polling): el valor se
    recalcula en cada carga/recarga de la tabla (montaje, búsqueda, cambio de página, y tras un
    "Vender viajes" exitoso), igual que el resto de los datos de esa tabla.
28. El backend resuelve `saldo_viajes` para todos los conductores del listado paginado con una
    consulta agregada (`withSum` sobre la nueva relación `Conductor::ventasViajes()` +
    `withCount` de pedidos con `prepago_descontado = true`), no reutilizando
    `VentaViajeConductorController::saldoConductor()` en un loop por fila (evita N+1). Ese método
    sigue existiendo tal cual para el endpoint `saldo-viajes` individual.
29. `saldo_viajes` se incluye siempre en la respuesta de `ConductorResource`, sin condicionar al
    valor de `modalidad_conductores` en el backend; es el frontend quien decide, con un `v-if`,
    cuándo mostrar la columna.

## Corrección: el saldo del tenant se consume por envío entregado, no por venta a conductores

> Historia del dueño del tenant (ver `007-panel-despachador.md`, §"Ampliación: el Panel es la vista
> principal…"): *"quiero ver el número de viajes que tengo acreditados y cómo se van descontando"*.
> Al aterrizar esa historia salió a la luz que "descontar" significaba otra cosa para el dueño que
> para esta spec: aquí el saldo del tenant bajaba cuando el dueño **vendía** viajes a un conductor
> (viajes acreditados − viajes vendidos), y en modalidad Comisión no bajaba nunca. El dueño espera
> que baje **cada vez que se entrega un envío**, sin importar cómo le cobre a sus conductores. Esta
> corrección reescribe la fórmula del saldo del tenant y deja intacta la del conductor.

### Objetivo / Alcance de esta corrección

Deja funcionando:

- **Nueva fórmula del saldo del tenant**: viajes acreditados por ADMIN_CENTRAL menos envíos
  entregados que consumieron un viaje.
  `saldo_viajes_tenant = SUM(compras_paquetes.cantidad_viajes) − COUNT(consumos_viajes_tenant)`.
- **Qué consume**: cada envío que pasa a `ENTREGADO` consume **exactamente 1 viaje** del tenant,
  una sola vez, en cualquier modalidad (`Prepago` o `Comision`). Vender viajes a un conductor
  **deja de descontar** del saldo del tenant.
- **Qué no consume**: un envío cancelado o rechazado en cualquier punto (no consume ni devuelve);
  un envío del ambiente `TEST` (ver enmienda en `025-modo-test-live.md`); y los envíos entregados
  **antes** de desplegar esta corrección (no hay relleno retroactivo).
- **Bitácora del consumo**: tabla nueva `consumos_viajes_tenant`, una fila por envío consumido.
  Como el resto de saldos de esta spec, el número no se guarda en ningún contador: se calcula.
- **Un solo lugar que calcula**: helper `App\Support\SaldoViajesTenant::actual()`, usado por
  Configuración, por la validación de venta a conductores, por el aviso de tiempo real (027 §12) y
  por la carga inicial del Panel.
- **La validación de venta se conserva como tope** (RN-1): no se puede vender a un conductor una
  cantidad de viajes mayor al saldo actual del tenant, calculado con la fórmula nueva. Se preguntó
  y se decidió mantenerla aunque la venta ya no descuente.
- **Configuración sigue mostrando el saldo** (pestaña Comisión/Prepago), ahora con la fórmula
  nueva, para que el Panel y Configuración nunca muestren dos números distintos.

**No** incluye:

- Bloquear la creación, publicación o entrega de envíos cuando el saldo llega a `0` o queda en
  negativo. El saldo **puede quedar negativo**; el bloqueo sigue siendo la historia futura que ya
  registra `010-paquetes-viajes.md`.
- Pantalla de historial de consumos del tenant. La tabla queda lista para eso.
- Cambios al saldo prepagado del conductor (`ventas_viajes_conductor` − `pedidos.prepago_descontado`)
  ni a la comisión: `liquidarConductor()` no cambia. Son dos contabilidades independientes: la del
  tenant con ADMIN_CENTRAL (esta corrección) y la del tenant con cada conductor (lo ya existente).

### Decisión técnica

#### Por qué una tabla de consumos y no una columna en `pedidos`

Marcar el pedido (`viaje_tenant_consumido = true`, al estilo de `prepago_descontado`) era la salida
corta. Se prefiere la tabla porque (1) es la bitácora que el dueño va a pedir después ("qué envío
me consumió cada viaje"), con `created_at` propio y sin recorrer `pedidos`; (2) el `UNIQUE` sobre
`id_pedido` es la garantía de "una sola vez" a nivel de base, no de código; y (3) contar filas es la
misma consulta que ya usa `SaldoViajes` del conductor, sin filtrar por estado ni por ambiente:
la fila existe o no existe.

#### Cuándo se escribe el consumo

`PedidoEstadoService::transicionar()` no guarda —el llamador decide cuándo y dentro de qué
transacción (027 §5, Enmienda 2)—, así que el consumo no puede insertarse ahí: si el `save()` no
llegara, quedaría un consumo huérfano de un envío que nunca se entregó. Se escribe con el mismo
mecanismo que los avisos: `diferir($pedido, fn () => $this->consumirViajeDelTenant($pedido))` al
transicionar a `ENTREGADO`, que `PedidoObserver::saved()` suelta después de confirmar. Dentro de esa
clausura: si `$pedido->esTest()` no se hace nada; si no, `ConsumoViajeTenant::firstOrCreate(['id_pedido'
=> $pedido->id_pedido])` y, solo si se creó, se dispara `SaldoTenantCambiado` con el saldo
resultante (027 §12). `firstOrCreate` + `UNIQUE` cubren el caso de un `saved()` repetido.

Se difiere **antes** que `PedidoEntregado::dispatch(...)`, para que quien lea el saldo del tenant
después del aviso de entrega ya lo vea descontado; el propio `PedidoEntregado` no lo lleva en su
carga (027 §12 explica por qué va en un evento aparte).

#### Sin relleno retroactivo

La migración crea la tabla vacía. Los envíos `ENTREGADO` anteriores al despliegue no se cuentan:
bajo la regla anterior ya "pagaron" con la venta que los cubrió, y volverlos a contar castigaría dos
veces a los tenants que operaban en Prepago. Consecuencia visible que el dueño aceptó: al desplegar,
el saldo de un tenant **sube** a su total acreditado (las ventas a conductores dejan de restarle) y
empieza a bajar con el primer envío entregado desde entonces.

#### El saldo se calcula fuera de cualquier scope de ambiente

`ConsumoViajeTenant` no lleva `ambiente` ni global scope: la fila solo existe para envíos LIVE
(se decide al escribir, no al leer). `compras_paquetes` tampoco tiene ambiente. Así `actual()` da el
mismo número desde una petición del Panel en TEST, en LIVE, desde la app del conductor o desde el
contexto de ADMIN_CENTRAL con la tenencia inicializada.

### Reglas de negocio (de esta corrección)

Reemplazan o complementan las reglas 1 y 2 de arriba:

- **RN-C1** *(reemplaza la definición implícita en "Saldos calculados")*: saldo del tenant =
  `SUM(compras_paquetes.cantidad_viajes) − COUNT(consumos_viajes_tenant)`. Puede ser negativo.
- **RN-C2** *(complementa RN-2)*: al pasar a `ENTREGADO`, además de liquidar al conductor, el envío
  consume 1 viaje del tenant, una sola vez (`UNIQUE(id_pedido)`), en ambas modalidades.
- **RN-C3**: cancelaciones y rechazos no consumen ni devuelven. Un envío TEST no consume.
- **RN-C4** *(RN-1 se mantiene)*: la venta a un conductor se rechaza si la cantidad resultante
  excede el saldo actual del tenant (RN-C1). La venta no modifica ese saldo.
- **RN-C5**: ningún saldo del tenant `<= 0` bloquea operación alguna.
- **RN-C6**: no hay relleno retroactivo; el consumo empieza con el primer envío entregado después
  de desplegar la corrección.

### Backend (Laravel) — cambios de esta corrección

- **Migración de tenant** `create_consumos_viajes_tenant_table`: `id_consumo` (PK), `id_pedido`
  (FK a `pedidos.id_pedido`, `UNIQUE`), `timestamps()`. Sin `ambiente`, sin borrado lógico.
- **Modelo** `App\Models\Tenant\ConsumoViajeTenant` (`$table = 'consumos_viajes_tenant'`,
  relación `pedido()`), y `Pedido::consumoViajeTenant()` (hasOne).
- **Helper** `App\Support\SaldoViajesTenant::actual(): int` — la fórmula RN-C1, una sola vez.
  Sustituye las dos copias de `CompraPaquete::sum(...) - VentaViajeConductor::sum(...)` que hoy
  viven en `ConfiguracionController::estadoActual()` y en `VentaViajeConductorController::store()`.
  **Enmienda (posterior a la implementación):** gana `actualONull(): ?int`, que envuelve el cálculo
  en `try/catch`, registra el fallo y devuelve `null`. Lo usan las lecturas de paso —Configuración y
  el `meta` de `pedidos/en-turno`— para que un saldo que no se puede calcular (el caso real fue la
  migración de tenant pendiente en local) no tumbe las tarifas ni las listas del Panel: Configuración
  muestra "no disponible por ahora" y la píldora se queda en skeleton. El tope de venta (RN-C4)
  sigue usando `actual()` y sí falla, porque ahí el número decide algo.
- **`PedidoEstadoService`**: método privado `consumirViajeDelTenant(Pedido)` diferido al
  transicionar a `ENTREGADO` (ver "Cuándo se escribe el consumo").
- **Evento** `App\Events\Tenant\SaldoTenantCambiado` — definido en 027 §12.
- **`Admin\CreditoPaqueteController::store()`**: dentro del bloque con la tenencia inicializada,
  tras `CompraPaquete::create(...)`, dispara `SaldoTenantCambiado` (motivo `acreditacion`) con
  `$tenant->slug`. Como todo aviso del protocolo (018, RN-08), si la emisión falla la acreditación
  responde con éxito igual.
- **Pruebas**: `ConfiguracionTest` (fórmula nueva: vender no baja el saldo, entregar sí),
  `VentaViajeConductorTest` (tope RN-C4 con la fórmula nueva), `PedidoTest`/`PanelReactivoTest`
  (entregar un envío LIVE crea exactamente un consumo aunque se guarde dos veces; entregar un TEST
  no crea ninguno; cancelar no crea ninguno), y prueba del controlador central (acreditar emite el
  evento con el saldo resultante).

### Frontend (Vue 3) — cambios de esta corrección

- `ConfiguracionView.vue`: ninguno; sigue leyendo `saldo_viajes_tenant` de `GET /configuracion`,
  que ahora sale del helper.
- El indicador sobre el mapa y su actualización en vivo: `007` (ampliación) y `027 §12`.

### Criterios de aceptación (de esta corrección)

1. Con 100 viajes acreditados y 30 vendidos a conductores, `GET /configuracion` reporta
   `saldo_viajes_tenant = 100`.
2. Entregar un envío LIVE baja ese número a 99, en Prepago y en Comisión; entregarlo "dos veces"
   (doble `save()` del mismo estado) lo deja en 99.
3. Cancelar un envío, o entregar un envío TEST, no cambia el número.
4. Vender a un conductor 101 viajes con saldo 100 se rechaza con el mensaje de RN-1; vender 100 se
   acepta y el saldo del tenant sigue en 100.
5. Con saldo 0, entregar un envío lo deja en −1 y no falla nada.
6. Acreditar un paquete desde ADMIN_CENTRAL emite `saldo-tenant.cambiado` con el saldo resultante.
7. Las pruebas listadas arriba pasan.

### Supuestos asumidos (continúa el registro de arriba)

30. El saldo del tenant baja 1 por envío entregado, en cualquier modalidad; ya no baja al vender
    viajes a un conductor. **Reemplaza al supuesto 5.**
31. Cancelados y rechazados no consumen ni devuelven.
32. Los envíos TEST no consumen del saldo real del tenant (excepción a RN-06 de 025, registrada
    allá).
33. El saldo puede quedar negativo; no se bloquea nada por saldo. El bloqueo sigue pendiente.
34. La validación de venta a conductores (RN-1) se mantiene como tope contra la fórmula nueva.
    **Decidido al preguntar.**
35. No hay relleno retroactivo de consumos. **Decidido al preguntar.**
36. Cada consumo queda registrado como fila propia (`consumos_viajes_tenant`), no como bandera en
    `pedidos`, para que exista bitácora aunque no haya pantalla todavía.
