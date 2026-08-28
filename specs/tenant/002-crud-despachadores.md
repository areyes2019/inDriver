# Spec: CRUD de despachadores y su dependencia con usuarios

## Historia de usuario

Como AdminCliente, quiero que al dar de alta (o reasignar el rol de) un usuario con rol
`Despachador` se cree automáticamente su perfil operativo en la tabla `despachadores`, y quiero una
pantalla donde ver esos perfiles y cambiar su estado (Activo/Suspendido/Inactivo), para no tener que
crear esa fila a mano en la base de datos.

## Objetivo / Alcance

Cierra el pendiente que dejó explícitamente `tenant/001-login-y-crud-usuarios.md` ("no incluye:
crear automáticamente el registro relacionado en `despachadores`/`conductores`... el perfil
operativo queda para una historia futura") para la tabla `despachadores` (inciso 02 de
`db/02-base-de-datos.md`), que depende de `usuarios` (inciso 01).

Deja funcionando:

- Alta automática de `despachadores` al crear un `usuario` con `rol = Despachador`.
- Alta/baja automática de `despachadores` al editar un usuario existente y cambiarle el rol hacia o
  desde `Despachador`.
- Listado de despachadores (con el nombre/email del usuario asociado) con búsqueda y paginación.
- Cambiar el `estado` de un despachador (`Activo`/`Suspendido`/`Inactivo`).

**No** incluye:

- Una pantalla de "nuevo despachador" independiente — el alta ocurre siempre desde el formulario de
  usuarios ya existente (`tenant/001`).
- Un botón "Eliminar despachador" separado del usuario — eliminar el perfil operativo implica
  eliminar o cambiar el rol del usuario desde la pantalla de usuarios.
- La tabla `conductores` (inciso 03) — mismo patrón, pero historia aparte.

## Decisión técnica

### Por qué no hay CRUD de alta/baja independiente para `despachadores`

`despachadores` no tiene datos propios más allá de `estado` — es un perfil 1 a 1 sobre `usuarios`
que solo tiene sentido si existe un usuario con `rol = Despachador`. Exponer un formulario de "nuevo
despachador" duplicaría la captura de datos que ya vive en el formulario de usuarios y abriría la
puerta a un usuario con `rol = Despachador` sin fila en `despachadores`, o viceversa. En su lugar,
`UsuarioController` es quien crea/borra la fila de `despachadores`, como efecto del alta o de un
cambio de rol.

### Relación 1 a 1 a nivel de aplicación, no de base de datos

La migración `create_despachadores_table.php` (commit `5a2d882`) ya corrió sin `unique()` en
`id_usuario`. No se modifica esa migración ya aplicada (regla: nunca tocar una migración que ya
corrió en una base de trabajo). La garantía de "un usuario, un despachador" se hace en el código:
`UsuarioController` usa `firstOrCreate` al pasar un usuario a `Despachador`, y borra la fila al
sacarlo.

### Transacciones

Crear el `usuario` y, si aplica, su `despachadores`, ocurre dentro de `DB::transaction()` — si algo
falla a la mitad, ninguna de las dos filas queda a medias. Igual para `update` cuando cambia el rol.

### Cambiar estado: valor explícito, no un `toggle` binario

A diferencia de `clientes.estado` (dos valores, `ClienteController@cambiarEstado` alterna entre
ellos sin parámetros), `despachadores.estado` tiene tres valores (`Activo`/`Suspendido`/`Inactivo`,
igual que `usuarios.estado`). `DespachadorController@cambiarEstado` recibe el nuevo `estado` en el
body y lo valida contra esos tres valores, en vez de alternar ciegamente.

### Binding implícito de ruta

`ClienteController` ya usa binding implícito (`Cliente $cliente`) de forma segura, porque
`tenant.slug` está insertado en la lista de prioridad de middleware antes de `AuthenticatesRequests`,
y `SubstituteBindings` corre después de ese — por eso `DespachadorController@cambiarEstado` también
usa binding implícito (`Despachador $despachador`), no resolución manual como hace
`UsuarioController` (que predata ese ajuste de prioridad).

## Reglas de negocio

- Un `usuario` con `rol = Despachador` siempre tiene exactamente una fila en `despachadores`, creada
  en `Activo`. Un usuario con otro rol nunca tiene fila en `despachadores`.
- Si se edita un usuario y su rol cambia **hacia** `Despachador`, se crea la fila (`Activo`). Si
  cambia **desde** `Despachador` hacia otro rol, se borra la fila.
- Eliminar un `usuario` borra en cascada su fila en `despachadores` (ya lo hace la migración,
  `cascadeOnDelete`).
- Solo `AdminCliente` accede a `GET /despachadores` y `PATCH /despachadores/{id}/estado` — mismo
  middleware de rol y mismo límite de peticiones (`tenant-usuarios`) que usuarios/clientes.

## Backend (Laravel)

- **Modelo nuevo** `App\Models\Tenant\Despachador`: `$table = 'despachadores'`,
  `$primaryKey = 'id_despachador'`, relación `belongsTo(Usuario::class, 'id_usuario', 'id_usuario')`.
- **Resource nuevo** `App\Http\Resources\Tenant\DespachadorResource`: expone `id_despachador`,
  `id_usuario`, `nombre`, `apellido_paterno`, `email` (del usuario relacionado), `estado`,
  `created_at`.
- **Controlador nuevo** `App\Http\Controllers\Tenant\DespachadorController`:
  - `index`: lista con `with('usuario')`, búsqueda por nombre/apellido/email del usuario asociado
    (`whereHas`), paginado (15), mismo patrón que `ClienteController@index`.
  - `cambiarEstado(Request $request, Despachador $despachador)`: valida `estado` contra
    `Rule::in(['Activo', 'Suspendido', 'Inactivo'])`, actualiza, registra `Auditoria`
    (`tabla_afectada = 'despachadores'`, `accion = 'CAMBIO_ESTADO'`).
- **Modifica** `App\Http\Controllers\Tenant\UsuarioController`:
  - `store`: envuelve la creación del `Usuario` en `DB::transaction()`; si `rol === 'Despachador'`,
    crea el `Despachador` (`estado = 'Activo'`) en la misma transacción.
  - `update`: envuelve `update()` en `DB::transaction()`; compara el rol anterior contra el nuevo —
    si entra a `Despachador`, `Despachador::firstOrCreate(['id_usuario' => ...], ['estado' =>
    'Activo'])`; si sale de `Despachador`, borra la fila (`Despachador::where('id_usuario',
    ...)->delete()`).
- **Rutas** (`routes/api.php`), agregadas al mismo grupo `['throttle:tenant-usuarios',
  'rol.tenant:AdminCliente']` donde ya viven `/usuarios`:

  ```php
  Route::get('/despachadores', [Tenant\DespachadorController::class, 'index']);
  Route::patch('/despachadores/{despachador}/estado', [Tenant\DespachadorController::class, 'cambiarEstado']);
  ```

## Frontend (Vue 3)

- **Vista nueva** `views/tenant/despachadores/ListaDespachadoresView.vue`: tabla con nombre, email,
  estado (`UiBadge`) y un `<select>` para cambiar el estado (no un botón de "toggle" binario, por la
  misma razón de tres valores explicada arriba); búsqueda y paginación, mismo patrón visual que
  `ListaClientesView.vue`. Sin botones "Nuevo"/"Editar"/"Eliminar".
- **Ruta nueva** (`router/index.ts`): `/t/:slug/panel/despachadores`, con
  `meta: { requiresTenantAuth: true }`.
- **`layouts/TenantLayout.vue`**: agrega el ítem "Despachadores" al menú lateral.

## Fuera de alcance

- Pantalla de alta/baja independiente de `despachadores`.
- Tabla `conductores` (inciso 03 de `db/02-base-de-datos.md`) — historia futura, mismo patrón.
- Restricción `unique` a nivel de base de datos sobre `despachadores.id_usuario` (la migración ya
  corrió; la garantía queda a nivel de aplicación).

## Criterios de aceptación

1. `POST /api/v1/t/{slug}/usuarios` con `rol = Despachador` crea también una fila en `despachadores`
   con `estado = Activo`, vinculada al usuario nuevo.
2. `POST /api/v1/t/{slug}/usuarios` con cualquier otro rol no crea fila en `despachadores`.
3. `PUT /api/v1/t/{slug}/usuarios/{id}` que cambia el rol de un usuario **hacia** `Despachador` crea
   su fila en `despachadores` (`Activo`) si no existía.
4. `PUT /api/v1/t/{slug}/usuarios/{id}` que cambia el rol de un usuario **desde** `Despachador` hacia
   otro rol borra su fila en `despachadores`.
5. `GET /api/v1/t/{slug}/despachadores` sin sesión responde `401`; con sesión de un usuario
   `Despachador` o `Conductor` responde `403`.
6. `GET /api/v1/t/{slug}/despachadores` devuelve el nombre/email del usuario asociado a cada
   despachador, paginado, y filtra con `?search=`.
7. `PATCH /api/v1/t/{slug}/despachadores/{id}/estado` con un `estado` válido lo actualiza y registra
   `Auditoria` (`CAMBIO_ESTADO`); con un valor fuera del enum responde `422`.
8. El frontend expone `/t/:slug/panel/despachadores` con la tabla, búsqueda y el selector de estado
   por fila; el menú lateral del tenant incluye el enlace "Despachadores".
9. Pint y ESLint/Prettier corren sin errores sobre el código nuevo; `php artisan test` pasa.

## Supuestos asumidos (registro completo)

1. El alcance es "CRUD completo" de `despachadores` = listar (con búsqueda/paginación), ver
   (implícito en el listado) y cambiar estado — sin alta/baja manual independiente, porque el alta
   ocurre siempre como efecto de crear/editar un `usuario`.
2. La relación 1 a 1 `usuario`↔`despachador` se garantiza en la aplicación (`firstOrCreate` /
   borrado explícito), no con una restricción `unique` nueva en base de datos, porque la migración de
   `despachadores` ya corrió (commit `5a2d882`) y no se edita una migración ya aplicada.
3. Cambiar el rol de un usuario hacia/desde `Despachador` crea/borra automáticamente su fila en
   `despachadores` — comportamiento nuevo en `UsuarioController@update`, no solo en `store`.
4. `despachadores.estado` se cambia con un valor explícito en el body (`PATCH .../estado` con
   `{estado: "..."}`), no con un `toggle` binario como `clientes.estado`, porque tiene tres valores
   posibles.
5. `DespachadorController@cambiarEstado` usa binding implícito de ruta (`Despachador $despachador`),
   igual que ya hace `ClienteController`, porque el ajuste de prioridad de middleware
   (`tenant.slug` antes de `AuthenticatesRequests`, que a su vez corre antes de `SubstituteBindings`)
   ya lo hace seguro — la resolución manual de `UsuarioController` es de antes de ese ajuste.
6. No hay pantalla de "eliminar despachador" — se elimina o se le cambia el rol al usuario desde la
   pantalla de usuarios ya existente, y la fila de `despachadores` se borra en cascada (a nivel de
   base de datos, ya existente) o por el nuevo código de `update` (cambio de rol).
7. La tabla `conductores` (inciso 03) queda fuera de esta historia — incluso siendo un patrón similar
   (perfil operativo 1 a 1 sobre `usuarios`), tiene columnas propias (licencia, disponibilidad) que
   ameritan su propia historia.
