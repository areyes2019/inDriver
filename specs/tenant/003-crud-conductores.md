# Spec: CRUD de conductores y su dependencia con usuarios

## Historia de usuario

Como AdminCliente, quiero dar de alta el perfil operativo (licencia, disponibilidad) de un usuario
con rol `Conductor` que ya existe, verlo en una lista, y editar sus datos y estado, para tener
registrado quién puede operar como conductor y con qué licencia.

## Objetivo / Alcance

Cierra, para la tabla `conductores` (inciso 03 de `db/02-base-de-datos.md`), el mismo pendiente que
`tenant/002-crud-despachadores.md` cerró para `despachadores` (inciso 02) — con una diferencia
importante explicada abajo.

Deja funcionando:

- Alta manual del perfil de conductor (el AdminCliente elige un usuario existente con
  `rol = Conductor` y captura sus datos de licencia).
- Listado de conductores (con el nombre/email del usuario asociado) con búsqueda y paginación.
- Edición de los datos propios del conductor: `numero_licencia`, `fecha_vencimiento_licencia`,
  `estado`, `disponibilidad`.
- Borrado automático del perfil cuando el usuario deja de tener `rol = Conductor`.

**No** incluye:

- Alta automática del perfil al crear/editar un usuario con `rol = Conductor` (ver "Decisión
  técnica" — a diferencia de `despachadores`, aquí sí hace falta captura manual).
- Un botón "Eliminar conductor" independiente — se maneja cambiando el rol o eliminando el usuario,
  igual que `despachadores`.
- La tabla `conductor_vehiculo` (inciso 05) ni ninguna asignación de vehículo — historia futura.
- Que `disponibilidad` se sincronice con la tabla `conductor_estado` (inciso 14, la fuente "en vivo"
  del estado del conductor en campo) — aquí es un campo editable a mano desde el panel.

## Decisión técnica

### Por qué aquí sí hace falta una pantalla de alta manual (a diferencia de despachadores)

`despachadores` no tiene columnas propias más allá de `estado`, así que su alta podía ser un efecto
automático de crear el usuario. `conductores` sí tiene una columna obligatoria sin valor por
default: `numero_licencia` (`NOT NULL`, migración `create_conductores_table.php`, ya aplicada —
commit `5a2d882`). Ese dato no se captura en el formulario de usuarios (`tenant/001`), así que crear
la fila automáticamente fallaría por violar esa restricción, o forzaría a inventar un valor de
relleno sin sentido de negocio. En vez de eso, la fila se crea explícitamente en una pantalla propia,
donde el AdminCliente elige el usuario y captura la licencia.

### Selector de usuarios disponibles

`GET /conductores/usuarios-disponibles` devuelve los usuarios con `rol = Conductor` y
`estado = Activo` que todavía no tienen fila en `conductores` (`whereNotIn` contra
`Conductor::pluck('id_usuario')` — no se agrega una relación nueva a `Usuario` solo para esto, es una
subconsulta directa en el controlador). Ese es el selector de la pantalla "Nuevo conductor". Si no
hay ninguno, la pantalla lo indica en vez de mostrar un selector vacío.

### Relación 1 a 1, a nivel de aplicación

Igual que `despachadores`: la migración de `conductores` ya corrió sin `unique()` en `id_usuario`.
`ConductorController@store` valida a mano que el usuario elegido no tenga ya una fila en
`conductores` antes de crearla.

### Qué pasa con el rol del usuario después de creado el perfil

- Igual que `despachadores`: si el rol de un usuario que **ya tenía** perfil de conductor cambia a
  otro distinto de `Conductor`, `UsuarioController@update` borra su fila en `conductores` (mismo
  mecanismo ya usado para `despachadores`, extendido en el mismo método).
- A diferencia de `despachadores`: si el rol de un usuario cambia **hacia** `Conductor`, no pasa nada
  automático — el usuario simplemente aparece disponible en el selector de "Nuevo conductor" hasta
  que alguien complete su licencia a mano.

### Un solo endpoint de edición, no un `cambiarEstado` aparte

A diferencia de `despachadores` (que solo tiene `estado`), aquí hay varios campos propios que tiene
sentido editar juntos en un solo formulario, incluyendo `estado` y `disponibilidad`. Por eso
`ConductorController@update` es un `PUT` completo, patrón `ClienteController@update`/
`UsuarioController@update`, no un endpoint de cambio de estado aislado.

## Reglas de negocio

- Un usuario con `rol = Conductor` puede o no tener fila en `conductores` (a diferencia de
  `despachadores`, donde el usuario siempre la tiene). El perfil se completa cuando alguien lo captura
  a mano.
- Un usuario solo puede tener una fila en `conductores` a la vez (garantizado en la aplicación, no en
  la base de datos).
- Si el rol de un usuario con perfil de conductor cambia a otro rol, la fila en `conductores` se
  borra — si vuelve a ser `Conductor` más adelante, hay que volver a capturar la licencia.
- Eliminar el usuario borra en cascada su fila en `conductores` (ya lo hace la migración,
  `cascadeOnDelete`).
- `numero_licencia` no es único — puede haber dos filas con el mismo valor si se captura por error;
  no se valida.
- Solo `AdminCliente` accede a las rutas de `conductores` — mismo middleware de rol y mismo límite de
  peticiones (`tenant-usuarios`) que usuarios/clientes/despachadores.

## Backend (Laravel)

- **Modelo nuevo** `App\Models\Tenant\Conductor`: `$table = 'conductores'`,
  `$primaryKey = 'id_conductor'`, `fecha_vencimiento_licencia` casteada a `date`, relación
  `belongsTo(Usuario::class, 'id_usuario', 'id_usuario')`.
- **Resource nuevo** `App\Http\Resources\Tenant\ConductorResource`: expone `id_conductor`,
  `id_usuario`, `nombre`/`apellido_paterno`/`email` (del usuario relacionado), `numero_licencia`,
  `fecha_vencimiento_licencia`, `estado`, `disponibilidad`, `created_at`.
- **Controlador nuevo** `App\Http\Controllers\Tenant\ConductorController`:
  - `usuariosDisponibles`: usuarios `rol = Conductor`, `estado = Activo`, sin fila en `conductores`.
  - `index`: lista con `with('usuario')`, búsqueda por `numero_licencia` o nombre/apellido/email del
    usuario asociado, paginado (15).
  - `store`: valida `id_usuario` (existe, `rol = Conductor`, sin perfil previo) + los campos de
    licencia; crea en `estado = 'ACTIVO'`, `disponibilidad = 'FUERA_DE_SERVICIO'` (mismos defaults
    que la migración).
  - `show(Conductor $conductor)`: un conductor con los datos de su usuario asociado — lo usa la
    pantalla de edición para precargar el formulario (binding implícito, mismo criterio de seguridad
    que `cambiarEstado` de `despachadores`).
  - `update(Request $request, Conductor $conductor)`: valida y actualiza todos los campos propios,
    incluidos `estado` y `disponibilidad` (`Rule::in` contra sus enums).
- **Modifica** `App\Http\Controllers\Tenant\UsuarioController@update`: agrega, junto a la regla ya
  existente de `despachadores`, que si el rol anterior era `Conductor` y el nuevo no lo es, borra la
  fila en `conductores` (misma transacción).
- **Rutas** (`routes/api.php`), mismo grupo protegido que `/usuarios`/`/despachadores`:

  ```php
  Route::get('/conductores/usuarios-disponibles', [Tenant\ConductorController::class, 'usuariosDisponibles']);
  Route::get('/conductores', [Tenant\ConductorController::class, 'index']);
  Route::post('/conductores', [Tenant\ConductorController::class, 'store']);
  Route::get('/conductores/{conductor}', [Tenant\ConductorController::class, 'show']);
  Route::put('/conductores/{conductor}', [Tenant\ConductorController::class, 'update']);
  ```

## Frontend (Vue 3)

- **Vista nueva** `views/tenant/conductores/ListaConductoresView.vue`: tabla con nombre, email,
  número de licencia, estado, disponibilidad, botón "Editar" por fila, botón "Nuevo conductor"
  arriba. Búsqueda y paginación, mismo patrón visual que `ListaClientesView.vue`. Sin botón
  "Eliminar". La tabla ocupa el 100% del ancho interior disponible del card, sin `max-width` ni
  centrado propio, igual que la corrección ya aplicada en `ListaTenantsView.vue` (spec 008).
- **Vista nueva** `views/tenant/conductores/CrearConductorView.vue`: `<select>` con los usuarios de
  `usuariosDisponibles` (mensaje explicativo si viene vacío) + campos de licencia. Mismo patrón que
  `CrearClienteView.vue`/`CrearUsuarioView.vue`.
- **Vista nueva** `views/tenant/conductores/EditarConductorView.vue`: mismos campos de licencia, más
  `estado` y `disponibilidad` como `<select>`.
- **Rutas** (`router/index.ts`): `/t/:slug/panel/conductores`,
  `/t/:slug/panel/conductores/crear`, `/t/:slug/panel/conductores/:id/editar`, con
  `meta: { requiresTenantAuth: true }`.
- **`layouts/TenantLayout.vue`**: agrega "Conductores" al arreglo `items` del navbar (mismo formato
  `{ label, to }` que ya usan Clientes/Usuarios/Despachadores).

## Fuera de alcance

- Alta automática del perfil al poner `rol = Conductor` en un usuario.
- Botón "Eliminar conductor" independiente.
- Tabla `conductor_vehiculo` (inciso 05) y cualquier asignación de vehículo.
- Sincronizar `disponibilidad` con `conductor_estado` (inciso 14) — queda como campo editable a mano.
- Restricción `unique` a nivel de base de datos sobre `conductores.id_usuario` — la migración ya
  corrió; la garantía queda a nivel de aplicación (mismo criterio que `despachadores`).

## Criterios de aceptación

1. `GET /api/v1/t/{slug}/conductores/usuarios-disponibles` solo devuelve usuarios `rol = Conductor`,
   `estado = Activo`, sin fila en `conductores`.
2. `POST /api/v1/t/{slug}/conductores` con un `id_usuario` que no tiene `rol = Conductor`, o que ya
   tiene perfil, responde `422` sin crear nada.
3. `POST /api/v1/t/{slug}/conductores` con datos válidos crea la fila en `estado = ACTIVO`,
   `disponibilidad = FUERA_DE_SERVICIO`.
4. `PUT /api/v1/t/{slug}/usuarios/{id}` que cambia el rol de un usuario **hacia** `Conductor` no crea
   ninguna fila en `conductores`.
5. `PUT /api/v1/t/{slug}/usuarios/{id}` que cambia el rol de un usuario con perfil de conductor
   **desde** `Conductor` hacia otro rol borra su fila en `conductores`.
6. `GET /api/v1/t/{slug}/conductores` sin sesión responde `401`; con sesión de un usuario
   `Despachador` o `Conductor` responde `403`; con `AdminCliente` lista con búsqueda y paginación.
7. `PUT /api/v1/t/{slug}/conductores/{id}` con un `estado` o `disponibilidad` fuera de su enum
   responde `422`.
8. El frontend expone `/t/:slug/panel/conductores` con la tabla y el botón "Nuevo conductor"; ese
   formulario muestra un selector con los usuarios disponibles (o un mensaje si no hay ninguno); el
   menú del tenant incluye el enlace "Conductores".
9. En `/t/:slug/panel/conductores`, la tabla ocupa el 100% del ancho interior del card (sin franjas
   vacías a los lados) en pantallas anchas, y sigue siendo legible con scroll horizontal propio en
   pantallas angostas.
10. Pint y ESLint/Prettier corren sin errores sobre el código nuevo; `php artisan test` pasa.

## Supuestos asumidos (registro completo)

1. El alcance es "CRUD completo" = listar (búsqueda/paginación), crear (alta manual, no automática),
   editar (todos los campos propios incluidos `estado`/`disponibilidad`) — sin "eliminar"
   independiente, mismo criterio que `despachadores`.
2. A diferencia de `despachadores`, la dependencia con `usuarios` **no** se resuelve con alta
   automática: `numero_licencia` es `NOT NULL` sin default en una migración ya aplicada, y ese dato
   no se captura en el formulario de usuarios. Por eso existe una pantalla "Nuevo conductor"
   independiente con un selector de usuarios elegibles.
3. El selector de "Nuevo conductor" solo muestra usuarios `rol = Conductor` y `estado = Activo` sin
   fila previa en `conductores` — no se agrega una relación nueva a `Usuario` para esa consulta, se
   resuelve con una subconsulta directa en `ConductorController`.
4. Cambiar el rol de un usuario **hacia** `Conductor` no crea nada automáticamente (a diferencia de
   `despachadores`) — el usuario solo queda disponible en el selector.
5. Cambiar el rol de un usuario que **ya tenía** perfil de conductor, hacia otro rol, sí borra su
   fila en `conductores` — mismo mecanismo ya usado para `despachadores`, extendido en el mismo
   método `UsuarioController@update`.
6. `numero_licencia` no se valida como único, ni en la aplicación ni en la base de datos.
7. `disponibilidad` se edita a mano desde este mismo formulario — no se conecta con `conductor_estado`
   (inciso 14), que queda fuera de esta historia.
8. `ConductorController@update` usa un solo `PUT` con todos los campos propios (incluido
   `estado`/`disponibilidad`), no un endpoint de cambio de estado aislado como `despachadores` —
   porque aquí sí hay varios campos que editar juntos.
9. Sin "eliminar conductor" independiente — se maneja cambiando el rol o eliminando el usuario desde
   la pantalla de usuarios ya existente (punto 5 arriba).
10. La tabla de `ListaConductoresView.vue` ocupa el 100% del ancho interior del card, sin
    `max-width` ni centrado propio; solo conserva un ancho mínimo para pantallas angostas, resuelto
    con scroll horizontal en su propio contenedor — misma corrección aplicada a
    `ListaTenantsView.vue` (spec 008).
