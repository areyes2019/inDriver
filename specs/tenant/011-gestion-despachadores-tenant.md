# Spec: Gestión de despachadores por tenant (usar despachadores Sí/No)

## Historia de usuario

Como AdminCliente (dueño del tenant), quiero decidir si mi flotilla trabaja con despachadores
independientes o si yo mismo opero directamente el panel, para adaptar el sistema al tamaño de mi
operación.

Cuando `usar_despachadores = Sí`:

- Se muestra el menú "Despachadores" y se oculta el "Panel" del AdminCliente.
- Si existe un solo despachador, todos los conductores quedan asignados a él automáticamente.
- Si existen 2 o más, cada conductor debe tener un despachador responsable, asignable y
  reasignable libremente por el AdminCliente. Ningún conductor activo puede quedar sin despachador.
- No hay límite máximo de despachadores.

Cuando `usar_despachadores = No`:

- Se oculta el menú "Despachadores" y se muestra el "Panel" del AdminCliente.
- El AdminCliente actúa funcionalmente como el despachador de toda la flotilla
  (AdminCliente → Conductores → Pedidos, en vez de AdminCliente → Despachadores → Conductores →
  Pedidos).

La configuración se puede cambiar en cualquier momento. Al pasar de Sí a No, se muestra antes un
aviso de confirmación explicando que los despachadores existentes pasarán a `Inactivo` (sin
eliminarse) y que los conductores pasan a control directo del AdminCliente. Al volver de No a Sí,
los despachadores inactivos se pueden reactivar y los conductores deben redistribuirse entre ellos.

Regla general: si se usan despachadores, todo conductor activo pertenece a uno; si no se usan, el
AdminCliente es responsable directo de todos.

## Objetivo / Alcance

Este es el primer trabajo que conecta `despachadores` con `conductores` — hoy esa relación **no
existe en ningún lugar del sistema**: `conductores` no tiene ninguna columna que apunte a un
despachador, y el único vínculo entre ambos roles es que un `Pedido` puede tener, de forma
independiente y opcional, un `id_despachador` y un `id_conductor`. Tampoco existe hoy ninguna
configuración de tenant para esto, aunque sí existe el mecanismo genérico
(`configuraciones_tenant` / `ConfiguracionTenant`, spec 015) donde encaja sin tocar el esquema.

Deja funcionando:

- Nueva clave de configuración `usar_despachadores` (`Sí`/`No`), gestionada por el AdminCliente
  desde la pantalla de Configuración ya existente (spec 015).
- Nueva columna `conductores.id_despachador` (nullable), con las reglas de asignación
  automática/obligatoria descritas en la historia.
- Menú "Despachadores" y "Panel" del AdminCliente, condicionados a esta configuración (no solo
  ocultos visualmente: también bloqueados en el backend y en las rutas del frontend).
- Quién puede crear pedidos (`POST /pedidos`) pasa a depender de esta configuración, en vez de
  estar fijo al rol `Despachador` como hoy.
- Modal de confirmación al cambiar de Sí a No, y flujo de reactivación/redistribución al volver de
  No a Sí, reutilizando pantallas ya existentes (editar/crear conductor, listado de despachadores)
  en vez de construir pantallas nuevas dedicadas.

**No incluye:**

- Historial/bitácora de qué conductor estuvo asignado a qué despachador y cuándo — solo se guarda
  la asignación vigente (igual que el resto de `configuraciones_tenant`).
- La tabla `pedido_asignaciones` (stub sin usar hoy) — esta historia no la activa ni depende de
  ella.
- Cualquier cambio al cálculo de tarifas, comisión o prepago (spec 015) — solo se reutiliza el
  mismo mecanismo de configuración.

## Decisión técnica

### Por qué se agrega una columna simple y no una tabla de asignación con historial

`conductor_vehiculo` (spec 004) usa una tabla de asignación con `activo`/`fecha_inicio`/`fecha_fin`
porque esa historia sí necesita el historial de qué vehículo usó cada conductor. Aquí no: no se
pide bitácora de asignaciones despachador↔conductor, solo saber la asignación actual. Por eso se
agrega una columna nullable `conductores.id_despachador` (mismo patrón que `pedidos.id_despachador`
ya existente), no una tabla nueva.

### Por qué el valor por defecto de `usar_despachadores` es `No`, incluso para tenants ya existentes

Si el valor por defecto fuera `Sí`, todo tenant que ya tiene conductores activos hoy violaría de
inmediato la regla "todo conductor activo pertenece a un despachador" — porque hoy **ningún**
conductor tiene despachador asignado (la columna no existe todavía). Poner `No` por defecto
preserva exactamente el comportamiento actual (nadie ha usado nunca esta relación) y deja que cada
AdminCliente decida activar `Sí` explícitamente, momento en el que sí corre el flujo de asignación
(automática con 1 despachador, o manual con 2+, como en el caso de reactivación de la historia).

### Cómo se decide quién puede crear pedidos (`POST /pedidos`)

Hoy esa ruta está fija a `rol.tenant:Despachador` (`routes/api.php:110-112`), documentado como
decisión deliberada en `tenant/006-crud-pedidos.md`. Esta historia lo vuelve dependiente de la
configuración, manteniendo la misma exclusividad operativa que ya existe (un solo rol "opera" el
panel a la vez), pero condicionada:

- El middleware de la ruta pasa a `rol.tenant:AdminCliente,Despachador` (igual que ya tienen
  `index`/`show`/`update`/`cambiarEstado` de pedidos).
- Dentro de `PedidoController@store`, se valida: si el usuario es `AdminCliente`, solo puede crear
  el pedido cuando `usar_despachadores = No`; si es `Despachador`, solo cuando
  `usar_despachadores = Sí`. Fuera de esa combinación, `403`.

Esto formaliza en código la regla de la historia: "el flujo es AdminCliente → Conductores → Pedidos
en vez de AdminCliente → Despachadores → Conductores → Pedidos" — nunca ambos roles operan pedidos
al mismo tiempo, evitando que un despachador siga creando pedidos "por fuera" cuando su tenant ya
pasó a control directo.

### Panel y menús: bloqueo real, no solo visual

Ocultar un ítem del menú no impide entrar por URL directa. Para "Despachadores" y para "Panel" del
AdminCliente se agrega bloqueo en dos capas, mismo patrón que ya usa `tenant-configuracion` en el
router (`router/index.ts:270-272`) y que usa el rol en el backend:

- **Backend**: `DespachadorController@index`/`cambiarEstado` responden `403` si
  `usar_despachadores = No` (además del chequeo de rol que ya tienen).
- **Frontend**: el router `beforeEach` redirige fuera de `tenant-panel-despachadores` si
  `usar_despachadores = No`, y fuera de `tenant-panel` si (`AdminCliente` y `Sí`) o (`Despachador` y
  `No`) — simétrico a la regla de creación de pedidos de arriba.

Para que el router pueda decidir esto sin una petición extra, `usar_despachadores` se agrega a la
respuesta de `GET /me` (`TenantAuthController@respuestaUsuario`, mismo lugar donde ya viajan
`ciudades_tenant` y `cobertura_bounds`), no solo a `GET /configuracion`.

### Por qué no se construye una pantalla nueva de "redistribuir conductores"

La historia pide poder asignar, cambiar y reasignar conductores entre despachadores cuando hay 2 o
más. Eso ya es exactamente lo que hacen las pantallas de conductor existentes (`CrearConductorView`,
`EditarConductorView`) con cualquier otro campo — agregarles el selector de despachador cubre "crear
con despachador" y "cambiar de despachador" sin pantalla nueva. Para el caso de reactivación (varios
conductores sin despachador a la vez), se agrega además una columna "Despachador" con un selector en
línea en `ListaConductoresView` (mismo patrón que el selector de estado en
`ListaDespachadoresView`), para no obligar a entrar conductor por conductor a la pantalla de editar.

### Reactivar despachadores sigue siendo manual, con el endpoint que ya existe

"Los despachadores previamente inactivos podrán volver a activarse" no implica reactivación
automática al cambiar `No → Sí` — el AdminCliente entra a "Despachadores" (ya visible de nuevo) y
cambia el estado de cada uno con `PATCH /despachadores/{id}/estado`, endpoint que ya existe
(spec 002). No se agrega ningún endpoint nuevo para esto.

## Reglas de negocio

1. `usar_despachadores` (`Sí`/`No`) es una única configuración por tenant, editable solo por
   `AdminCliente`, con `No` como valor por defecto.
2. Con `usar_despachadores = Sí` y exactamente 1 despachador `Activo`: todo conductor nuevo, y todo
   conductor sin despachador, se asigna automáticamente a ese despachador (no se pide el campo en
   el formulario).
3. Con `usar_despachadores = Sí` y 2+ despachadores `Activo`: `id_despachador` es obligatorio para
   guardar un conductor, o para dejarlo en estado `ACTIVO`.
4. Con `usar_despachadores = Sí` y 0 despachadores `Activo`: se permite guardar el conductor sin
   despachador (no hay ninguno que asignar); queda pendiente de asignación en cuanto exista uno.
5. Con `usar_despachadores = No`, `id_despachador` no se pide ni se valida en conductores.
6. Cambiar `usar_despachadores` de `Sí` a `No` pone en `Inactivo` todos los despachadores
   (`Activo` o `Suspendido`), sin eliminarlos ni afectar al usuario asociado (login/rol intactos).
7. `POST /pedidos`: `AdminCliente` solo puede crear pedidos si `usar_despachadores = No`;
   `Despachador` solo si `usar_despachadores = Sí`.
8. El acceso a `GET /despachadores` y `PATCH /despachadores/{id}/estado` requiere además
   `usar_despachadores = Sí` (aparte del rol `AdminCliente` que ya exigen).
9. Ningún pedido ya creado se modifica al cambiar esta configuración — conserva el
   `id_despachador`/`id_conductor` que tenía.

## Backend (Laravel)

- **Modelo `ConfiguracionTenant`**: agrega constante `USAR_DESPACHADORES = 'usar_despachadores'`.
- **Migración nueva** `add_id_despachador_to_conductores_table.php`:

  ```php
  Schema::table('conductores', function (Blueprint $table) {
      $table->foreignId('id_despachador')->nullable()->after('id_usuario')
          ->constrained('despachadores', 'id_despachador')->nullOnDelete();
  });
  ```

- **Modelo `Conductor`**: agrega `id_despachador` a `Fillable`, y relación
  `despachador(): BelongsTo` (`belongsTo(Despachador::class, 'id_despachador', 'id_despachador')`).
- **`Tenant\ConfiguracionController`**:
  - `show`/`update`: agrega `usar_despachadores` a la validación (`Rule::in(['Sí', 'No'])`) y a la
    respuesta, junto a las claves ya existentes.
  - `update`: cuando el valor pasa de `Sí` a `No`, en la misma transacción actualiza
    `Despachador::whereIn('estado', ['Activo', 'Suspendido'])->update(['estado' => 'Inactivo'])`, y
    registra `Auditoria` (`tabla_afectada = 'despachadores'`, `accion = 'CAMBIO_ESTADO'`,
    descripción indicando que fue por el cambio de configuración).
- **`Tenant\DespachadorController`**: `index` y `cambiarEstado` responden `403` si
  `ConfiguracionTenant::obtener(ConfiguracionTenant::USAR_DESPACHADORES, 'No') !== 'Sí'`.
- **`Tenant\ConductorController`**:
  - `store`/`update`: agrega validación condicional de `id_despachador` según las reglas de negocio
    2-5 (cuántos despachadores `Activo` existen, y el valor de `usar_despachadores`); con 1 solo
    despachador activo, ignora el valor recibido y fuerza ese id; con `estado = ACTIVO` y 2+
    despachadores, `id_despachador` se vuelve `required`.
  - `index`/`show`: agrega `with('despachador.usuario')` y expone el despachador en
    `ConductorResource`.
- **`Tenant\PedidoController@store`**: después de `validarDatos`, valida la exclusividad de rol
  descrita en "Decisión técnica" (`403` si no corresponde según `usar_despachadores`).
- **Rutas** (`routes/api.php`):
  - `POST /pedidos` se mueve del grupo `rol.tenant:Despachador` al grupo
    `rol.tenant:AdminCliente,Despachador` (línea 110-112 actual, se une al grupo de la línea 114).
- **`Tenant\AuthController@respuestaUsuario`**: agrega `usar_despachadores` a la respuesta de
  `login`/`me`, leído de `ConfiguracionTenant`.

## Frontend (Vue 3)

- **`stores/tenantAuth.ts`**: agrega `usar_despachadores` al tipo de usuario/estado, poblado desde
  la respuesta de `/me`.
- **`layouts/TenantLayout.vue`**:
  - El ítem "Despachadores" del menú solo se agrega cuando `auth.usuario?.usar_despachadores`.
  - El ítem "Panel" solo se agrega cuando (`AdminCliente` y `No`) o (`Despachador` y `Sí`).
  - `mostrarNuevaEntrega` pasa a depender de esa misma combinación rol/config, no solo del rol.
- **`router/index.ts`** (`beforeEach`): agrega las dos redirecciones descritas en "Decisión
  técnica" (fuera de `tenant-panel-despachadores` y fuera de `tenant-panel` según corresponda).
- **`views/tenant/configuracion/ConfiguracionView.vue`**: agrega el campo "¿Utilizar
  despachadores?" (select Sí/No) a la pestaña de Tarifas o a una nueva pestaña "Despachadores"
  (se reutiliza la pestaña "Comisión / Prepago", ya que ahí conviven otras decisiones operativas
  del tenant). Al enviar un cambio de `Sí` a `No`, antes de llamar a `PUT /configuracion` se abre
  `UiConfirmDialog` (ya existente, spec 004) con el mensaje de advertencia de la historia; solo se
  guarda si se confirma.
- **`views/tenant/conductores/CrearConductorView.vue`** y **`EditarConductorView.vue`**: agregan el
  selector "Despachador", poblado con `GET /despachadores` filtrado a `estado = Activo`:
  - Oculto si `usar_despachadores = No`.
  - Oculto (fijo al único despachador) si hay exactamente 1 despachador activo.
  - Visible y obligatorio si hay 2+.
- **`views/tenant/conductores/ListaConductoresView.vue`**: agrega columna "Despachador" (nombre, o
  badge "Sin asignar" si `usar_despachadores = Sí` y es `null`), con un `<select>` en línea para
  reasignar sin entrar a editar, visible solo cuando `usar_despachadores = Sí` y hay 2+
  despachadores activos.

## Fuera de alcance

- Historial de asignaciones despachador↔conductor (solo se guarda la asignación vigente).
- Cualquier uso de la tabla `pedido_asignaciones` (stub sin controlador, no se activa aquí).
- Reactivación automática de despachadores al pasar de `No` a `Sí` — se hace manual con el endpoint
  ya existente (`PATCH /despachadores/{id}/estado`).
- Pantalla dedicada de "redistribución masiva" — se resuelve con el selector en línea de
  `ListaConductoresView` y los formularios de crear/editar conductor ya existentes.
- Notificar (correo/push) a despachadores o conductores cuando cambia esta configuración.

## Criterios de aceptación

1. `GET /t/{slug}/configuracion` y `GET /t/{slug}/me` devuelven `usar_despachadores`
   (`Sí`/`No`, `No` por defecto si nunca se configuró).
2. `PUT /t/{slug}/configuracion` con `usar_despachadores: 'No'` habiendo despachadores `Activo`
   los pasa todos a `Inactivo` en la misma operación, sin eliminarlos ni afectar a sus usuarios.
3. Con `usar_despachadores = Sí` y 1 despachador `Activo`: crear un conductor lo asigna
   automáticamente a ese despachador, sin pedir el campo.
4. Con `usar_despachadores = Sí` y 2+ despachadores `Activo`: crear o activar un conductor sin
   `id_despachador` responde `422`.
5. Con `usar_despachadores = No`: crear/editar un conductor no pide ni valida `id_despachador`.
6. `POST /t/{slug}/pedidos` con `usar_despachadores = No` funciona para `AdminCliente` y responde
   `403` para `Despachador`; con `usar_despachadores = Sí`, al revés.
7. `GET /t/{slug}/despachadores` responde `403` cuando `usar_despachadores = No`, incluso para
   `AdminCliente`.
8. El menú lateral muestra "Despachadores" solo con `usar_despachadores = Sí`, y "Panel" solo
   cuando corresponde según rol/configuración (ver reglas de negocio).
9. Navegar directamente a `/t/{slug}/panel/despachadores` con `usar_despachadores = No` redirige
   fuera, sin depender de que el menú esté oculto.
10. Al guardar `usar_despachadores: 'No'` desde la pantalla de Configuración, se muestra el modal
    de confirmación con las consecuencias antes de aplicar el cambio; cancelar no guarda nada.
11. `ListaConductoresView` muestra el despachador asignado de cada conductor y permite reasignarlo
    en línea cuando hay 2+ despachadores activos.
12. Pedidos ya existentes conservan su `id_despachador`/`id_conductor` sin cambios al modificar
    esta configuración.
13. Pint y ESLint/Prettier corren sin errores sobre el código nuevo; `php artisan test` pasa.

## Supuestos asumidos (registro completo)

1. La configuración `usar_despachadores` es un valor único por tenant (no por ciudad/sucursal) y
   aplica a toda la flotilla sin excepciones.
2. Solo `AdminCliente` puede cambiar esta configuración.
3. La asignación conductor→despachador es 1 a muchos: un conductor tiene un solo despachador a la
   vez, guardado en una columna simple (`conductores.id_despachador`), no en una tabla de
   asignación con historial — esta historia no pide bitácora de reasignaciones.
4. La asignación es manual por el AdminCliente (sin criterio automático de zona/carga), salvo el
   caso de un solo despachador activo, que se asigna automático.
5. Con 2+ despachadores activos, el sistema exige elegir despachador al crear o al dejar `ACTIVO`
   un conductor.
6. "Conductor activo" es el estado `ACTIVO` ya existente en `conductores.estado`.
7. Al cambiar `Sí → No`, todos los despachadores (`Activo` o `Suspendido`) pasan a `Inactivo`.
8. Al cambiar `Sí → No`, el usuario vinculado al despachador conserva su acceso/login normal; solo
   cambia el estado del registro `Despachador`.
9. El AdminCliente usa, en modo `No`, exactamente el mismo Panel que hoy usa el Despachador
   (servicios en turno, mapa, botón "Nueva Entrega"), sin diferencias de funcionalidad.
10. No se requiere bitácora de qué conductor estuvo asignado a qué despachador y cuándo.
11. Al reactivar (`No → Sí`) con 2+ despachadores existentes, los conductores no se auto-asignan;
    quedan pendientes hasta que el AdminCliente los reasigne.
12. La regla "todo conductor activo tiene despachador" se hace cumplir con validación de backend,
    no solo con convención de UI.
13. Con `usar_despachadores = No`, el menú y las rutas de "Despachadores" quedan completamente
    inaccesibles (también por URL directa), no solo ocultas del menú — mismo criterio se aplica al
    "Panel" del AdminCliente cuando `Sí`, y al de Despachador cuando `No`, por simetría.
14. Los pedidos ya creados conservan su `id_despachador` al momento de crearse, sin importar
    cambios posteriores de configuración.
15. El valor por defecto de `usar_despachadores`, incluyendo tenants ya existentes, es `No` — no
    `Sí` — porque hoy ningún conductor tiene despachador asignado (la relación no existía) y
    defaultear a `Sí` violaría de inmediato la regla principal para cualquier tenant con
    conductores activos.
16. La exclusividad de creación de pedidos (`AdminCliente` cuando `No`, `Despachador` cuando `Sí`)
    es simétrica y mutuamente excluyente: nunca ambos roles pueden crear pedidos en el mismo
    momento para el mismo tenant.
17. Con 0 despachadores `Activo` y `usar_despachadores = Sí`, se permite guardar un conductor sin
    despachador (no hay ninguno disponible); queda como pendiente de asignación, sin bloquear la
    operación completa del tenant.
18. La reactivación de despachadores inactivos (`No → Sí`) es manual, usando el endpoint
    `PATCH /despachadores/{id}/estado` ya existente — no se agrega un endpoint ni una reactivación
    automática.
19. No se construye una pantalla dedicada de "redistribución masiva": la reasignación de
    conductores entre despachadores se resuelve con un selector en línea en `ListaConductoresView`
    y con el campo ya agregado a los formularios de crear/editar conductor.
