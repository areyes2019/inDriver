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

- **Saldo del tenant** = `SUM(compras_paquetes.cantidad_viajes)` − `SUM(ventas_viajes_conductor.cantidad_viajes)`.
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
5. El saldo de viajes del tenant se sigue calculando y validando igual que antes
   (`SUM(compras_paquetes.cantidad_viajes) - SUM(ventas_viajes_conductor.cantidad_viajes)`); el
   monto pagado por el conductor no afecta ese cálculo, solo determina cuántos viajes se acreditan.
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
