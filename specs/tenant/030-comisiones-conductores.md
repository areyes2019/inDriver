# Spec: Comisiones de conductores — generación por entrega, liquidaciones y reporte del tenant

## Historia de usuario

Como AdminCliente quiero que, cuando administro a mis conductores por **Comisión**, cada envío
entregado genere automáticamente la comisión del conductor (un porcentaje del importe del envío),
que el sistema me diga en todo momento cuánto le he generado, cuánto le he pagado y cuánto le debo a
cada uno, y que pueda registrar los pagos que les voy haciendo para llevar el control — sin que nada
de esto se mezcle con mi saldo de viajes de plataforma, que sigue bajando 1 por cada entrega como
hasta ahora.

## Regla principal del negocio

**Todo tenant siempre trabaja con prepago frente a ADMIN_CENTRAL.** Compra viajes por adelantado y
cada envío entregado le consume exactamente 1 (spec `015`, corrección RN-C1…C6). Eso no cambia con
esta historia y funciona igual en las dos modalidades de cobro a conductores:

| | Saldo de viajes del tenant (plataforma) | Cuenta del tenant con el conductor |
|---|---|---|
| **Prepago** | 100 → 99 al entregar | El conductor compró viajes; su saldo 10 → 9 al entregar (`prepago_descontado`) |
| **Comisión** | 100 → 99 al entregar | El conductor no compra nada; se le genera una comisión a su favor al entregar |

Son dos contabilidades independientes. Pagar una comisión no mueve el saldo de viajes del tenant;
consumir un viaje no paga ni reduce una comisión.

## Objetivo / Alcance

Deja funcionando:

- **Generación de la comisión al entregar.** Con `modalidad_conductores = Comision`, todo pedido que
  pasa a `ENTREGADO` con conductor asignado genera **una** fila en `comisiones_conductor` con:
  conductor, pedido, `importe_envio` del pedido, porcentaje vigente en ese instante, importe de
  comisión resultante, estado `PENDIENTE` y fecha/hora. Una sola vez por pedido (`UNIQUE`).
- **Base de cálculo: exclusivamente `importe_envio`.** `comision = round(importe_envio × porcentaje
  / 100, 2)`. `importe_cobro`, `pago_extra`, `cargo_extra` y el valor de la mercancía no participan.
- **Trazabilidad histórica.** El porcentaje y el importe base quedan guardados en la fila. Cambiar
  después `comision_porcentaje` no recalcula nada.
- **Liquidaciones.** El AdminCliente registra pagos hechos a un conductor (monto, nota opcional, quién
  lo registró, fecha) en `liquidaciones_comision`. Registro interno: el sistema no mueve dinero.
- **Generado / pagado / pendiente**, por conductor y para todo el tenant, siempre calculados desde
  las dos tablas por un helper único (`App\Support\ComisionesConductor`).
- **Estado por viaje** (`PENDIENTE` / `PAGADA`) asignado por orden cronológico al liquidar.
- **Pantalla nueva del Panel** (`ComisionesView.vue`, solo `AdminCliente`): resumen del tenant,
  selector de conductor con su detalle, sus viajes con comisión y su historial de liquidaciones, y
  el formulario "Registrar liquidación". Se actualiza sola al recibir `pedido.entregado`.
- **El conductor ya no necesita saldo de dinero para trabajar en Comisión.** Se retira ese bloqueo.

**No incluye:**

- Cambio alguno al modelo Prepago (`ventas_viajes_conductor`, `prepago_descontado`, `SaldoViajes`,
  venta por monto, historial de pagos, columna "Saldo de viajes", `saldo.acreditado`).
- Cambio alguno al saldo de viajes del tenant (`consumos_viajes_tenant`, `SaldoViajesTenant`,
  `saldo-tenant.cambiado`).
- Mostrar comisiones al conductor en `panda_express` (historia futura: un "monedero" donde el
  repartidor vea su cuenta y pueda cobrar). **Queda preparado, no construido:** las dos tablas
  nuevas ya están indexadas por `id_conductor` y `ComisionesConductor::resumenDe()` no depende de
  quién pregunta, así que esa historia solo necesitará un `GET /t/{slug}/conductor/comisiones` bajo
  `auth:conductor-token`, un evento al conductor con el patrón de `saldo.acreditado`, y —para
  "cobrar"— una tabla de solicitudes que al aprobarse cree una `liquidacion_comision`. Nada de esta
  spec se apoya en `conductores.saldo`, justamente para no cerrar esa puerta.
- Editar o eliminar una liquidación ya registrada; "ajustes" de comisión.
- Filtros por rango de fechas o exportación en el reporte.
- Facturación, contabilidad fiscal, cuentas bancarias, transferencias, pasarelas.
- Relleno retroactivo: pedidos entregados antes de desplegar no generan comisión;
  `pedidos.comision_calculada` no se migra ni se muestra.
- Borrar `conductores.saldo`, `movimientos_saldo`, `SaldoService`, `SaldoConductorController`,
  `Conductor\SaldoController@dinero/movimientos` ni el evento `saldo.cambiado`. Dejan de tener
  relación con comisiones (ver "Decisión técnica") pero se quedan en su lugar.
- Distinguir comisiones de ambiente TEST y LIVE (ver supuesto 4).

---

## Decisión técnica

### Por qué NO se reutiliza `conductores.saldo` / `movimientos_saldo` (opción C)

Ese ledger (SPEC-023, en `022-cambios-sobre-envio.md`) modela **dinero que el conductor deposita**
con el tenant: la comisión se pensó como cargo negativo contra ese depósito, y con saldo ≤ 0 el
conductor no puede conectarse (`DisponibilidadService::tieneSaldoDisponible`,
`OfertaPedidoService::puedeRecibirOfertas`). El modelo de esta historia es el inverso: **dinero que
el tenant le debe al conductor**, y el conductor trabaja sin depositar nada.

Se descartó adaptarlo (opción B) porque: `saldo_resultante` cambiaría de significado; los
`CREDITO/AJUSTE` manuales se mezclarían con lo adeudado; `conductores.saldo` es un contador
almacenado cuando toda la spec `015` calcula saldos desde bitácoras; y no tiene columnas para
`porcentaje_aplicado` ni `importe_envio`. Además hoy **nadie escribe movimientos `COMISION`**: el
Panel nunca llama `POST /conductores/{c}/saldo` y la app solo escucha el toast `saldo.cambiado`. No
hay datos que migrar ni pantallas que perder. El patrón correcto ya existe en el propio proyecto:
`consumos_viajes_tenant` (una fila por entrega, `UNIQUE`, escritura diferida). `comisiones_conductor`
es su gemela.

### Cuándo y cómo se escribe la comisión

`PedidoEstadoService::transicionar()` no guarda —el llamador decide cuándo (spec `027`)—, así que la
fila no se inserta ahí: quedaría huérfana si el `save()` no llegara. Se difiere con el mismo
mecanismo que el consumo del tenant: `diferir($pedido, fn () => $this->generarComision($pedido))`
cuando el nuevo estado es `ENTREGADO`, hay `id_conductor` y la modalidad vigente es `Comision`.
`PedidoObserver::saved()` la suelta con la fila ya escrita y confirmada (`afterCommit`). Dentro de
la clausura: `ComisionConductor::firstOrCreate(['id_pedido' => ...], [...datos...])`; `firstOrCreate`
más el `UNIQUE(id_pedido)` cubren un `saved()` repetido sin error visible.

El porcentaje y la modalidad se leen **en el momento de transicionar** (dentro de
`liquidarConductor()`), y se pasan ya resueltos a la clausura, para que un cambio de configuración
entre la transición y el commit no altere el resultado.

Se difiere desde `liquidarConductor()` —que corre antes que `consumirViajeDelTenant()`— y, como
todas las clausuras, **antes** de `PedidoEntregado::dispatch(...)` (`notificarConductores` es lo
último de `transicionar()`), para que quien vuelva a pedir el resumen de comisiones tras ese aviso
ya vea la fila nueva.

**TEST y LIVE por igual.** A diferencia de `consumirViajeDelTenant()`, aquí `esTest()` no se
consulta: el sistema completo está en pruebas y se quiere ejercitar Comisión al 100 % en modo TEST
(supuesto 4). Las tablas nuevas no llevan columna `ambiente` ni scope. Consecuencia para las
lecturas: el detalle de viajes carga `pedido` con `withoutGlobalScopes()`, porque desde el Panel
`AmbienteScope` ocultaría los pedidos del otro ambiente y las filas saldrían sin número de pedido.

### Estado por viaje: FIFO al liquidar, totales por suma

Los totales **nunca** dependen del estado: generado = `SUM(comisiones.importe_comision)`, pagado =
`SUM(liquidaciones.monto)`, pendiente = generado − pagado. El estado por fila existe para responder
"¿este viaje ya está cubierto?" y "¿qué pago lo cubrió?":

Al registrar una liquidación, dentro de la transacción con `lockForUpdate` sobre el conductor:

1. `disponible = SUM(liquidaciones del conductor, incluida la nueva) − SUM(importe_comision de sus
   filas PAGADA)`.
2. Se recorren sus filas `PENDIENTE` de la más antigua a la más nueva (`created_at`, luego
   `id_comision`). Mientras `disponible ≥ importe_comision` de la fila: se marca `PAGADA`, se le
   anota `id_liquidacion` y `pagada_en`, y `disponible −= importe_comision`.
3. La primera fila que no queda cubierta por completo sigue `PENDIENTE`; el sobrante queda contado
   en "pagado" y se aplica en la siguiente liquidación (paso 1 lo recupera solo).

Una fila `PAGADA` nunca vuelve a `PENDIENTE`: las liquidaciones no se editan ni se borran, así que
el acumulado pagado solo puede crecer. Una fila con `importe_comision = 0` (porcentaje 0) se marca
`PAGADA` en la primera liquidación que pase por ella, sin consumir nada, o se crea ya `PAGADA`
(ver RN-05).

### Un solo lugar que calcula: `App\Support\ComisionesConductor`

Mismo criterio que `SaldoViajes` y `SaldoViajesTenant`: el resumen del tenant, el detalle por
conductor, el tope de liquidación y cualquier evento leen las mismas dos consultas. Vue no suma
nada.

```php
ComisionesConductor::resumenTenant(): array   // viajes, importe_envios, generado, pagado, pendiente
ComisionesConductor::resumenDe(Conductor $c): array  // lo mismo + porcentaje_actual
ComisionesConductor::pendienteDe(Conductor $c): float
```

### Retiro del bloqueo por saldo de dinero

`DisponibilidadService::tieneSaldoDisponible()` es el único punto que decide "¿puede trabajar?" al
conectarse y al recibir ofertas. En `Comision` pasa a devolver `true`. `SaldoService` deja de
inyectarse ahí. `Prepago` no cambia.

### `pedidos.comision_calculada` deja de escribirse

La columna se queda (no hay migración destructiva) y `liquidarConductor()` ya no la asigna. Los
pedidos nuevos la llevan `null`; los viejos conservan su valor, que ninguna pantalla lee.

### Tiempo real: se reutiliza `pedido.entregado`

`ComisionesView.vue` se suscribe al canal del tenant con `realtimeService.subscribe(slug)` (el
mismo que usa `stores/panel.ts`) y, al recibir `pedido.entregado`, vuelve a pedir
`GET /comisiones/resumen` y, si hay un conductor seleccionado, su detalle. No se agrega carga al
evento ni se crea un evento nuevo: la fila de comisión se escribe en una clausura diferida y colgarle
el resumen a `pedido.entregado` dependería del orden de dos clausuras (mismo razonamiento que
`saldo-tenant.cambiado` en `027 §12`). Refrescar contra el backend es más simple y mantiene al
backend como fuente de verdad. Si el socket está caído, la pantalla sigue siendo correcta en su
siguiente carga.

---

## Reglas de negocio

- **RN-01** La comisión se genera **únicamente** al pasar a `ENTREGADO` con `id_conductor`, y solo
  si `modalidad_conductores` vigente en ese momento es `Comision`. Ningún otro estado la genera.
- **RN-02** Base de cálculo: `importe_envio`. `comision = round(importe_envio × porcentaje / 100, 2)`.
  `importe_cobro`, `pago_extra`, `cargo_extra` y la mercancía no participan.
- **RN-03** Una entrega = una comisión. `UNIQUE(id_pedido)` en `comisiones_conductor`. Un
  `saved()` repetido no duplica ni falla.
- **RN-04** `porcentaje_aplicado` e `importe_envio` se guardan en la fila. Cambiar el porcentaje del
  tenant no recalcula filas existentes.
- **RN-05** Con porcentaje `0` o no configurado en `Comision`, la fila se crea igual con
  `importe_comision = 0.00` y estado `PAGADA` (no hay nada que liquidar).
- **RN-06** En `Prepago` no se genera comisión, aunque exista `comision_porcentaje` configurado.
- **RN-07** Los pedidos TEST generan comisión igual que los LIVE; comisiones y liquidaciones no
  distinguen ambiente y se suman en los mismos totales.
- **RN-08** Cancelar o rechazar un pedido no genera ni revierte comisión.
- **RN-09** Generado = `SUM(importe_comision)`; pagado = `SUM(liquidaciones.monto)`; pendiente =
  generado − pagado. Calculados siempre; sin contadores.
- **RN-10** Una liquidación exige `monto > 0` y `monto ≤ pendiente` del conductor en ese momento,
  validado dentro de la transacción con bloqueo. Excederlo devuelve 422.
- **RN-11** Las liquidaciones solo se insertan; nunca se editan ni se borran.
- **RN-12** Estado por viaje FIFO (ver "Decisión técnica"). `PAGADA` es definitivo.
- **RN-13** Solo `AdminCliente` consulta comisiones y registra liquidaciones.
- **RN-14** Ni generar una comisión ni registrar una liquidación toca `consumos_viajes_tenant`,
  `ventas_viajes_conductor`, `prepago_descontado`, `conductores.saldo` ni `movimientos_saldo`.
- **RN-15** En `Comision` el conductor puede conectarse y recibir ofertas sin ningún saldo. En
  `Prepago` se sigue exigiendo `SaldoViajes > 0`.
- **RN-16** Sin relleno retroactivo: los pedidos entregados antes del despliegue no generan fila.

---

## Backend (Laravel)

### Migraciones (tenant)

**`create_comisiones_conductor_table`**

| Columna | Tipo | Notas |
|---|---|---|
| `id_comision` | PK | |
| `id_conductor` | FK `conductores` | `cascadeOnDelete`, indexada |
| `id_pedido` | FK `pedidos` | **`UNIQUE`** |
| `id_liquidacion` | FK `liquidaciones_comision`, nullable | `nullOnDelete`; qué pago la cubrió |
| `importe_envio` | decimal(10,2) | copia del pedido al momento de entregar |
| `porcentaje_aplicado` | decimal(5,2) | vigente al entregar |
| `importe_comision` | decimal(10,2) | resultado redondeado |
| `estado` | enum `PENDIENTE`, `PAGADA` | default `PENDIENTE` |
| `pagada_en` | timestamp nullable | |
| `created_at` | timestamp | fecha/hora de generación; sin `updated_at` salvo `pagada_en` |

Índice `(id_conductor, estado, created_at)` para el recorrido FIFO y el detalle.

**`create_liquidaciones_comision_table`**

| Columna | Tipo | Notas |
|---|---|---|
| `id_liquidacion` | PK | |
| `id_conductor` | FK `conductores` | `cascadeOnDelete`, indexada |
| `id_usuario` | FK `usuarios`, nullable | quién del tenant la registró; `nullOnDelete` |
| `monto` | decimal(10,2) | > 0 |
| `referencia` | string(120) nullable | nota / referencia |
| `created_at` | timestamp | fecha del registro |

Orden de las migraciones: primero `liquidaciones_comision`, luego `comisiones_conductor` (por la FK).
Sin columna `ambiente` en ninguna.

### Modelos

- `App\Models\Tenant\ComisionConductor` — tabla `comisiones_conductor`, PK `id_comision`,
  `UPDATED_AT = null`, casts decimales y `pagada_en` datetime; relaciones `conductor()`, `pedido()`,
  `liquidacion()`. Constantes `ESTADO_PENDIENTE`, `ESTADO_PAGADA`.
- `App\Models\Tenant\LiquidacionComision` — tabla `liquidaciones_comision`, PK `id_liquidacion`,
  `UPDATED_AT = null`; relaciones `conductor()`, `usuario()`, `comisiones()` (hasMany).
- `Conductor` gana `comisiones(): HasMany` y `liquidacionesComision(): HasMany`.
- `Pedido` gana `comision(): HasOne`.

### Helper `App\Support\ComisionesConductor`

- `resumenTenant(): array{viajes:int, importe_envios:float, generado:float, pagado:float, pendiente:float}`
- `resumenDe(Conductor): array` — igual, más `porcentaje_actual` (el configurado hoy, solo informativo).
- `pendienteDe(Conductor): float`.

### Servicio `App\Services\LiquidacionComisionService`

```php
public function registrar(Conductor $conductor, float $monto, ?Usuario $registradoPor, ?string $referencia): LiquidacionComision
```

`DB::transaction` + `Conductor::lockForUpdate()`; valida RN-10 (`ValidationException` en `monto`);
crea la liquidación; aplica el FIFO de "Decisión técnica"; escribe `Auditoria` (`tabla_afectada:
liquidaciones_comision`, `accion: ALTA`). Devuelve la liquidación con `comisiones` cargadas.

### `PedidoEstadoService`

- `liquidarConductor()`: en `Comision`, ya no asigna `comision_calculada`; resuelve porcentaje y
  difiere `generarComision($pedido, $porcentaje)`. En `Prepago` sin cambios.
- `generarComision(Pedido, float $porcentaje)` (privado): `ComisionConductor::firstOrCreate(...)`
  con RN-02 y RN-05. Nunca lanza fuera de la clausura (el observador ya la envuelve en `try/catch`).

### `DisponibilidadService`

`tieneSaldoDisponible()`: `Comision` → `true`. Se retira la dependencia de `SaldoService` del
constructor. El docblock del método se actualiza.

### Controlador `Tenant\ComisionController` (`rol.tenant:AdminCliente`, `throttle:tenant-usuarios`)

| Método | Ruta | Devuelve |
|---|---|---|
| GET | `/t/{slug}/comisiones/resumen` | `resumenTenant()` + `modalidad_conductores` + `comision_porcentaje` |
| GET | `/t/{slug}/conductores/{conductor}/comisiones` | `resumen` (`resumenDe`) + `viajes` (filas de `comisiones_conductor`, más recientes primero, con `numero_pedido`, `fecha_entrega`, `importe_envio`, `porcentaje_aplicado`, `importe_comision`, `estado`, `id_liquidacion`) + `liquidaciones` (más recientes primero, con `monto`, `referencia`, `usuario` nombre, `created_at`) |
| POST | `/t/{slug}/conductores/{conductor}/liquidaciones` | 201 con la liquidación creada y el `resumen` actualizado del conductor |

Validación del POST: `monto` `required|numeric|min:0.01`, `referencia` `nullable|string|max:120`.
El tope contra el pendiente lo aplica el servicio (RN-10), no el `validate()` del request, para que
ocurra dentro del bloqueo.

Las rutas se registran en el grupo ya existente de `AdminCliente` (junto a `vender-viajes` e
`historial-pagos`). `GET .../comisiones` va **antes** de cualquier `GET /conductores/{conductor}`
comodín si hiciera falta; con el registro actual no hay colisión porque el sufijo es distinto.

### `Tenant\ConductorController@index` y `ConductorResource` (adición 8)

El listado paginado agrega dos sumas por página, junto a las que ya calculan `saldo_viajes`:
`withSum('comisiones as comision_generada', 'importe_comision')` y
`withSum('liquidacionesComision as comision_pagada', 'monto')`. `ConductorResource` expone
`comision_pendiente = comision_generada − comision_pagada` (con `?? 0`), **siempre**, sin condicionar
a la modalidad — el frontend decide con `v-if`, igual que con `saldo_viajes`. Sin consulta por fila.

### Recursos API

- `ComisionConductorResource` — una fila de viaje con comisión (los campos de la tabla de arriba;
  `numero_pedido` y `fecha_entrega` desde `pedido` cargado con `withoutGlobalScopes()`).
- `LiquidacionComisionResource` — una liquidación con `usuario` resuelto a nombre.
- El resumen sale como arreglo plano del helper (sin resource; son cinco números).

### Rutas retiradas / que dejan de usarse

Ninguna se retira. `POST/GET /conductores/{c}/saldo`, `GET /conductor/saldo`, `GET
/conductor/movimientos-saldo` siguen respondiendo igual; simplemente ya no influyen en comisiones ni
en conectarse.

---

## Frontend (Vue 3)

- **`views/tenant/comisiones/ComisionesView.vue`** (nueva, `TenantLayout`, `UiCard`):
  - **Bloque "Comisiones"** (resumen del tenant): viajes realizados, importe de envíos, generadas,
    pagadas, pendientes. Si la modalidad actual es `Prepago`, se muestra un aviso ("El tenant está en
    modalidad Prepago; aquí solo se ven comisiones generadas mientras estuvo en Comisión") pero la
    pantalla sigue funcionando.
  - **Selector de conductor** (preseleccionado desde `?conductor={id}` si viene en la URL;
    `<select>` alimentado por `GET /conductores` — la lista ya
    existente, paginada a 15; se recorre completa para el selector, mismo patrón que usa
    `ListaConductoresView` para búsquedas) → al elegir, pide `GET /conductores/{c}/comisiones` y
    pinta: tarjeta con viajes, importe de envíos, porcentaje actual, generado, pagado, pendiente;
    tabla "Viajes" (pedido, fecha de entrega, importe de envío, % aplicado, comisión, estado);
    tabla "Liquidaciones" (fecha, monto, referencia, registró).
  - **"Registrar liquidación"**: botón visible con un conductor seleccionado (deshabilitado con
    pendiente $0); abre `LiquidarComisionModal.vue` (el mismo de la lista de conductores). En éxito
    muestra "Se registró un pago de $X a {conductor}" y refresca el detalle con el `resumen` de la
    respuesta.
  - **Tiempo real**: en `onMounted`, `realtimeService.subscribe(slug)` y `canal.bind('pedido.entregado', ...)`
    → refresca resumen y, si hay conductor seleccionado, su detalle. En `onUnmounted`, `unbind`.
    No se llama `unsubscribe()` (el canal es compartido con el Panel; mismo criterio que la
    suscripción del store).
  - Formato de dinero con `Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' })`,
    como el resto del proyecto. Sin clases nuevas en `global.css`.
- **`views/tenant/conductores/ListaConductoresView.vue`** (adición 8, espejo de lo que ya hace
  Prepago con "Vender viajes" / "Historial de pagos" / columna "Saldo de viajes"):
  - Botón general "Comisiones" junto a "Reporte de pagos", visible para `AdminCliente` cuando
    `modalidad_conductores === 'Comision'`, hacia `tenant-comisiones`.
  - Columna **"Comisión pendiente"** (`{{ conductor.comision_pendiente }}` formateado como dinero),
    visible para cualquier rol que vea la tabla, solo en `Comision`. En `Prepago` se sigue mostrando
    "Saldo de viajes"; nunca las dos a la vez. Se recalcula al cargar, buscar, paginar y tras
    liquidar — sin tiempo real en esta lista.
  - Acción por fila **"Liquidar comisión"**, solo `AdminCliente` y solo en `Comision`. Abre el
    componente compartido `LiquidarComisionModal.vue`; con pendiente $0 se muestra deshabilitada,
    no oculta. Al confirmar con éxito, `fetchConductores()` completo y mensaje "Se registró un pago
    de $X a {conductor}".
  - Acción por fila **"Ver comisiones"** → `tenant-comisiones` con `?conductor={id}`.
- **`components/comisiones/LiquidarComisionModal.vue`** (nuevo): un solo modal para la lista y para
  `ComisionesView.vue`. Props: `conductor` (id + nombre). Al abrirse pide
  `GET /conductores/{c}/comisiones` y prefija el monto con el `pendiente` fresco del servidor (no
  el de la tabla); campo referencia opcional; `POST .../liquidaciones`; emite `liquidado` con la
  respuesta; errores 422 bajo el campo.
- **`views/tenant/configuracion/ConfiguracionView.vue`**: en la pestaña Comisión / Prepago, cuando
  la modalidad es `Comision`, enlace "Ver comisiones de conductores" a la misma ruta. Sin más
  cambios.
- **Router**: ruta `/t/:slug/panel/comisiones`, nombre `tenant-comisiones`, `meta: {
  requiresTenantAuth: true }`, y se agrega a la guarda que redirige a no-`AdminCliente` junto a
  `tenant-configuracion` y `tenant-reporte-pagos-conductores`.
- **`stores/panel.ts`**: sin cambios.
- **`panda_express`**: sin cambios. El mensaje `INSUFFICIENT_BALANCE` deja de poder ocurrir en
  Comisión; su texto se conserva porque sigue aplicando a Prepago.

---

## Pruebas

### Backend (Pest, `tests/Feature/Tenant/`)

**`ComisionConductorTest.php`** (nuevo; helpers al estilo `panelTenant`/`panelConductor`/`panelPedido`):

1. Entregar en `Comision` con 25 % e `importe_envio` 100 crea una fila con `importe_envio 100`,
   `porcentaje_aplicado 25`, `importe_comision 25.00`, `PENDIENTE`, y **no** toca
   `comision_calculada`.
2. La base es `importe_envio`: con `importe_cobro 500`, `pago_extra 40` e `importe_envio 100`, la
   comisión sigue siendo 25.00.
3. Nada antes del `save()`; un segundo `save()` del mismo pedido deja una sola fila (RN-03).
4. Cambiar el porcentaje a 30 después de entregar no altera la fila (RN-04).
5. En `Prepago` no se crea fila aunque haya porcentaje (RN-06); `prepago_descontado` sigue igual.
6. Un pedido TEST en `Comision` sí crea fila (RN-07).
7. Cancelar desde `ARRIBADO_A_ENTREGA` no crea fila (RN-08).
8. Porcentaje 0 → fila con 0.00 y `PAGADA` (RN-05).
9. Entregar en `Comision` crea **además** exactamente un `consumos_viajes_tenant` (RN-14; el saldo
   del tenant baja 1 igual que en Prepago).
10. `PENDIENTE`/`PUBLICADO`/`TOMADO`/`ARRIBADO`/`EN_CAMINO`/`ARRIBADO_A_ENTREGA` no generan fila (RN-01).

**`LiquidacionComisionTest.php`** (nuevo):

1. 10 viajes × 100 al 25 %: `GET /conductores/{c}/comisiones` reporta generado 250, pagado 0,
   pendiente 250; `GET /comisiones/resumen` coincide.
2. `POST .../liquidaciones` con 100 → 201; pagado 100, pendiente 150; las 4 comisiones más antiguas
   quedan `PAGADA` con `id_liquidacion`, las 6 restantes `PENDIENTE` (RN-12).
3. Segunda liquidación de 30 → pagado 130, pendiente 120; 30 cubre una comisión más de 25 →
   5 `PAGADA`, sobrante 5 sin asignar. Tercera de 20 → pagado 150; el sobrante acumulado (5 + 20 =
   25) cubre otra → 6 `PAGADA`, 4 `PENDIENTE`. Los totales nunca dependen de cuántas están `PAGADA`.
4. Liquidar 151 con pendiente 150 → 422 en `monto`; nada se escribe (RN-10).
5. Liquidar 0 o negativo → 422 (RN-10).
6. Dos liquidaciones concurrentes de 150 sobre pendiente 150: solo una se acepta (RN-10 con
   bloqueo; se prueba secuencialmente dentro de una transacción abierta, mismo enfoque que
   `SaldoConductorTest`).
7. Liquidar no crea `consumos_viajes_tenant` ni `movimientos_saldo` ni cambia `conductores.saldo`
   (RN-14).
8. `Despachador` recibe 403 en los tres endpoints (RN-13).
9. La liquidación registra `id_usuario` del AdminCliente y `referencia`; aparece en `liquidaciones`
   del detalle con el nombre del usuario.
10. Con pedidos TEST y LIVE entregados, el detalle desde el Panel en LIVE lista los dos con su
    `numero_pedido` (RN-07; `withoutGlobalScopes`).

**`ConductorTest.php`** (existente; se agrega 1): `GET /conductores` devuelve `comision_pendiente`
por fila (generado − pagado) resuelto con agregados por página, y sigue devolviendo `saldo_viajes`
sin cambios.

**`DisponibilidadTest.php`** (existente; se reescriben 2):

- `rejects going online without balance under Comision modality` → **`allows going online under
  Comision modality without any money balance`**: `conductores.saldo = 0`, `POST /conductor/conectar`
  → 200 (RN-15).
- `allows going online under Comision modality once money balance is positive` → se elimina (ya no
  describe una regla) y se sustituye por: en `Prepago` sin viajes sigue devolviendo
  `INSUFFICIENT_BALANCE` (si no existe ya una prueba equivalente; si existe, solo se elimina).

**`PanelReactivoTest.php`** (existente): la prueba `consumes one tenant viaje per delivered pedido,
once, in either modality` sigue pasando sin cambios; se le agrega la aserción de que en `Comision`
existe además una fila en `comisiones_conductor`.

**Prueba de no regresión Prepago**: `ConfiguracionTest`, `VentaViajeConductorTest`,
`ConductorTest`, `ConductorAppTest`, `SaldoConductorTest`, `ModoTestLiveTest` deben seguir en verde
sin modificación.

### Frontend

`npm run lint` y `npm run type-check` (o el `build`) en verde. Sin pruebas unitarias de Vue en el
proyecto hoy; no se agregan.

---

## Criterios de aceptación

1. Tenant en `Comision` al 25 %: entregar un pedido con `importe_envio 100` deja al conductor con
   generado $25, pagado $0, pendiente $25, **y** el saldo de viajes del tenant baja 1.
2. El mismo pedido con `importe_cobro 500` y `pago_extra 40` genera la misma comisión de $25.
3. Cambiar el porcentaje a 30 % después: el viaje anterior sigue mostrando 25 % y $25; el siguiente
   viaje muestra 30 %.
4. En `Prepago`, entregar descuenta 1 viaje al conductor y 1 al tenant, y no aparece comisión.
5. Un conductor con `conductores.saldo = 0` puede conectarse y recibir ofertas en `Comision`.
6. Registrar una liquidación de $100 sobre $250 pendientes deja: generado $250, pagado $100,
   pendiente $150; los 4 viajes más antiguos figuran `PAGADA` y el resto `PENDIENTE`.
7. Intentar liquidar $151 con $150 pendientes muestra error y no registra nada.
8. Una liquidación registrada no se puede editar ni borrar desde la pantalla; queda con fecha,
   conductor, monto, referencia y quién la registró.
9. `GET /comisiones/resumen` y la suma de los detalles por conductor coinciden.
10. Con la pantalla de comisiones abierta, entregar un pedido (desde el Panel o la app) actualiza
    el resumen sin recargar.
11. Pedidos TEST generan comisión y se ven en la pantalla igual que los LIVE.
12. `Despachador` no ve el botón "Comisiones" ni puede abrir la ruta ni llamar los endpoints.
13. En `Comision`, la tabla de conductores muestra la columna "Comisión pendiente" con el valor
    calculado por el backend y las acciones "Liquidar comisión" / "Ver comisiones" por fila; en
    `Prepago` muestra "Saldo de viajes" y ninguna de las dos acciones.
14. Liquidar desde la lista deja la columna al día sin recargar la página manualmente; "Ver
    comisiones" abre la pantalla con ese conductor ya seleccionado.
15. Toda la suite de backend y el lint/type-check del frontend pasan.

---

## Supuestos asumidos (registro completo)

1. Comisión = `round(importe_envio × porcentaje / 100, 2)`, con el porcentaje vigente al entregar;
   porcentaje e importe base quedan guardados en la fila.
2. La modalidad que decide si hay comisión es la vigente al pasar a `ENTREGADO`, no la del momento de
   crear el pedido.
3. En `Comision` con porcentaje `0` o no configurado, sí se crea la fila con $0.00 (y nace `PAGADA`).
4. ~~Un pedido TEST no genera comisión.~~ **Reemplazado al preguntar:** TEST y LIVE generan
   comisión por igual; comisiones y liquidaciones no distinguen ambiente y se suman en los mismos
   totales. Todo el sistema está en pruebas; al salir a producción se reinicia.
5. En `Prepago` nunca se genera comisión, aunque exista porcentaje configurado.
6. Sin comisión retroactiva para pedidos entregados antes del despliegue; `comision_calculada` no se
   migra ni se muestra.
7. Generado / pagado / pendiente siempre calculados por suma; sin contadores.
8. Estado por viaje FIFO: cada liquidación cubre primero los viajes más antiguos; un viaje pasa a
   `PAGADA` solo cuando el acumulado cubre su comisión completa; uno cubierto a medias sigue
   `PENDIENTE` aunque los totales reflejen el pago.
9. Una liquidación debe ser > 0 y ≤ pendiente del conductor.
10. Una liquidación no se edita ni se elimina.
11. Solo `AdminCliente` registra liquidaciones y consulta comisiones.
12. La liquidación guarda fecha, conductor, monto, referencia opcional y usuario que la registró. Solo
    registro interno.
13. El conductor no ve sus comisiones en `panda_express` en esta historia.
14. Pantalla única en el Panel: resumen del tenant + selector de conductor con detalle, viajes,
    liquidaciones y "Registrar liquidación". Sin filtros de fecha.
15. Acceso desde `Conductores` (botón junto a "Reporte de pagos") y desde Configuración, visible solo
    en `Comision`.
16. El ledger de dinero (`conductores.saldo`, `movimientos_saldo`, endpoints, `saldo.cambiado`) deja
    de usarse para comisiones y pierde su bloqueo de conexión, pero no se borra.
17. La pantalla se actualiza al recibir `pedido.entregado` por el canal existente, volviendo a pedir
    el resumen al backend.
18. Cancelar o rechazar nunca genera ni revierte comisión.
19. Las acciones "Liquidar comisión" y "Ver comisiones" son por fila en la lista de conductores,
    visibles solo para `AdminCliente` y solo en `Comision` (mismo criterio que "Vender viajes").
20. La columna "Comisión pendiente" se muestra a cualquier rol que vea la tabla, solo en `Comision`;
    en `Prepago` se sigue mostrando "Saldo de viajes". Nunca las dos a la vez.
21. `comision_pendiente` viene siempre en `ConductorResource`, sin condicionar a la modalidad en el
    backend; el frontend decide con `v-if`, igual que `saldo_viajes`.
22. El modal de liquidación es un solo componente compartido por la lista y por la pantalla de
    Comisiones; al abrirse vuelve a consultar el pendiente al servidor para no liquidar sobre un
    número viejo de la tabla.
23. Con pendiente $0 el botón "Liquidar comisión" se muestra deshabilitado, no se oculta.
24. Tras liquidar con éxito se vuelve a pedir el listado completo y se muestra confirmación.
25. La columna no se actualiza en tiempo real en la lista; se recalcula al cargar, buscar, paginar y
    tras liquidar. La actualización en vivo vive solo en la pantalla de Comisiones.
26. "Ver comisiones" navega a `tenant-comisiones` con `?conductor={id}` y la pantalla lo preselecciona.

## Adiciones técnicas aceptadas

1. Tabla `comisiones_conductor` con `UNIQUE(id_pedido)`.
2. Tabla `liquidaciones_comision`, solo inserción.
3. Escritura diferida de la comisión (mismo mecanismo que `consumos_viajes_tenant`), sin excluir TEST.
4. Helper único `App\Support\ComisionesConductor`.
5. Transacción con `lockForUpdate` sobre el conductor al liquidar.
6. `DisponibilidadService::tieneSaldoDisponible()` devuelve `true` en `Comision`.
7. `pedidos.comision_calculada` deja de escribirse; la columna se conserva.
8. Liquidar comisión desde la lista de repartidores: columna "Comisión pendiente" (agregados por
   página en `ConductorController@index`, sin N+1), acciones por fila y modal compartido
   `LiquidarComisionModal.vue`.

## Orden de implementación (etapa 3)

1. Migraciones + modelos + helper `ComisionesConductor` → pruebas de helper vacías/cero.
2. `PedidoEstadoService` (generación diferida, retiro de `comision_calculada`) → `ComisionConductorTest`
   + suite existente en verde (prioridad 1: no romper Prepago; prioridad 2: saldo del tenant).
3. `DisponibilidadService` → `DisponibilidadTest` reescrita.
4. `LiquidacionComisionService` + `ComisionController` + resources + rutas → `LiquidacionComisionTest`.
5. `ConductorController@index` + `ConductorResource` (`comision_pendiente`) → `ConductorTest`.
6. `LiquidarComisionModal.vue` + `ComisionesView.vue` + `ListaConductoresView.vue` + router +
   enlaces + tiempo real → lint/type-check.
7. Suite completa de backend.
