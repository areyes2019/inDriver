# Spec: Alta automática del AdminCliente inicial al crear un tenant

## Historia de usuario

Como ADMIN_CENTRAL, quiero que al dar de alta un tenant nuevo se cree automáticamente su primer
usuario (AdminCliente) con credenciales de acceso, para que el dueño del negocio pueda entrar a su
panel sin que yo tenga que crear ese usuario a mano en la base de datos del tenant.

## Objetivo / Alcance

Dejar funcionando: la captura del nombre y correo del AdminCliente en el formulario de "Crear
tenant" (`007-crear-tenants.md`), la creación automática de ese usuario en la base del tenant recién
aprovisionada, la generación de una contraseña aleatoria, y el envío de esa contraseña por correo.
Es la continuación directa de `007-crear-tenants.md`, que dejó explícitamente fuera "Alta del
ADMIN_CLIENTE inicial del tenant". **No** incluye el login de AdminCliente/Despachador/Conductor en
sí (sigue pendiente, ver `004-auth-admin-central.md`), ni pantallas de gestión de usuarios del
tenant.

## Decisión técnica

- El campo `email` del formulario de Crear tenant (spec 007), hoy opcional, pasa a ser
  **requerido**: sirve tanto para el tenant como para las credenciales de acceso del AdminCliente.
- Se agregan dos campos nuevos y requeridos al formulario: `nombre` y `apellido_paterno` (persona
  dueña/contacto del negocio), y uno opcional: `apellido_materno` — mismos nombres de columna que
  la tabla `usuarios` del tenant. Estos datos **no** se guardan en `tenants` (base central); solo
  viajan en la petición para crear el usuario del tenant.
- Se agrega un nuevo job, `CrearAdminClienteInicial`, que `TenantController@store` dispara de forma
  síncrona justo después de guardar el tenant con éxito (mismo lugar donde ya se registra el alta
  en `logs_centrales`) — no dentro del pipeline de `Events\TenantCreated` de
  `TenancyServiceProvider`: ese pipeline solo recibe el tenant ya guardado, y guardar ahí mismo el
  nombre/apellido de la persona (datos que no son columnas de `tenants`) los mandaría sin querer a
  la columna `data` (json) de la base central, violando la regla de que esos datos no se persisten
  ahí. Al dispararse desde el controlador, el job recibe el tenant y los tres campos de la persona
  como argumentos directos, sin tocar el modelo `Tenant`. El job mismo inicializa la tenencia sobre
  la base del tenant recién migrada antes de insertar en su tabla `usuarios`, y la cierra al
  terminar. No se usa el job genérico `SeedDatabase` de `stancl/tenancy` (además de estar comentado
  hoy) porque necesita datos dinámicos capturados en el formulario, no un seeder estático.
- La contraseña se genera con la función de Laravel para contraseñas aleatorias seguras
  (`Str::password()`), y se guarda hasheada (`Hash::make()`) — igual que cualquier contraseña del
  sistema.
- El correo con las credenciales reutiliza el mismo mecanismo SMTP/Mailpit ya configurado en
  `004-auth-admin-central.md` (Mailpit en local, SMTP de Hostinger en producción, sin cambiar
  código entre ambos).
- Si el job de creación del AdminCliente falla después de que el tenant y su base ya existen, **no**
  se revierte el tenant (ya quedó operativo, con su base creada y migrada); se registra el error
  con `Log::error()` y no se envía el correo. Reintentar la creación del AdminCliente queda para
  una historia futura.
- No se agrega "forzar cambio de contraseña en el primer login" — el AdminCliente entra con la
  contraseña recibida por correo tal cual, sin flujo adicional.

## Backend (Laravel)

- **`App\Http\Controllers\Admin\TenantController@store`**: agrega validación de `nombre`
  (requerido, string), `apellido_paterno` (requerido, string), `apellido_materno` (opcional,
  string); el campo `email` pasa de `nullable` a `required`.
- **Job nuevo** `App\Jobs\CrearAdminClienteInicial` (`ShouldQueue`, dispatch síncrono): recibe el
  tenant recién creado más `nombre`, `apellido_paterno` y `apellido_materno` como argumentos.
  Inicializa la tenencia sobre la base del tenant, crea el registro en `usuarios`
  (`rol = 'AdminCliente'`, `estado = 'Activo'`, `email` tomado del tenant), genera y hashea la
  contraseña, dispara la notificación de credenciales con la contraseña en texto plano (única vez
  que se conoce en claro), y cierra la tenencia. Si algo falla, atrapa el error, lo registra con
  `Log::error()` y no lo vuelve a lanzar — así una falla aquí nunca revierte el tenant ya creado.
- **Modelo nuevo** `App\Models\Tenant\Usuario`: modelo Eloquent para la tabla `usuarios` del
  tenant (`$primaryKey = 'id_usuario'`, `$hidden = ['password']`, `password` con cast `hashed`).
  Sin `$connection` explícita — corre bajo el contexto de tenancy que el propio job inicializa.
- **Notificación nueva** `App\Notifications\CredencialesAdminCliente`: correo con el nombre
  comercial del tenant, el email de acceso y la contraseña generada. Se envía "on demand"
  (`Notification::route('mail', $email)->notify(...)`), sin requerir que `Usuario` use el trait
  `Notifiable`.
- **`TenantController@store`**: tras `$tenant->save()` y registrar el alta en `logs_centrales`,
  llama a `CrearAdminClienteInicial::dispatchSync(...)` con el tenant y los datos de la persona.

## Frontend (Vue 3)

- **`CrearTenantView.vue`** (spec 007): agrega los campos `nombre` y `apellido_paterno`
  (requeridos) y `apellido_materno` (opcional), bajo una etiqueta que deje claro que son los datos
  de la persona que tendrá el primer acceso (ej. "Datos del administrador del negocio"). El campo
  `email` deja de mostrarse como opcional.
- Tras crear el tenant con éxito, el mensaje de confirmación aclara que las credenciales de acceso
  se enviaron al correo capturado.

## Fuera de alcance

- El login de AdminCliente/Despachador/Conductor en sí — sigue pendiente (ver
  `004-auth-admin-central.md`, problema de identificación de tenant sin resolver).
- Forzar cambio de contraseña en el primer login.
- Reintentar automáticamente la creación del AdminCliente si su job falla — el tenant ya quedó
  creado; el reintento sería una historia futura.
- Crear Despachador o Conductor — solo el AdminCliente inicial.
- Editar, reenviar o regenerar las credenciales del AdminCliente desde el panel de ADMIN_CENTRAL —
  no hay pantalla para eso en esta historia.
- Exponer los campos `nombre`/`apellido_paterno`/`apellido_materno` en `TenantResource` — no
  pertenecen a la tabla `tenants`, solo se usan para crear el usuario del tenant.

## Criterios de aceptación

1. `POST /api/v1/admin/tenants` sin `email`, `nombre` o `apellido_paterno` responde `422`.
2. `POST /api/v1/admin/tenants` con datos válidos crea el tenant, aprovisiona su base, y además
   inserta en la tabla `usuarios` de esa base un registro con `rol = 'AdminCliente'`,
   `estado = 'Activo'`, el `email` capturado, y el `nombre`/`apellido_paterno` capturados.
3. La contraseña insertada en `usuarios.password` está hasheada, nunca en texto plano.
4. Tras crear el tenant con éxito, Mailpit (en local) recibe un correo dirigido al `email`
   capturado, con una contraseña en texto plano y el nombre comercial del tenant.
5. La contraseña recibida por correo, al hashearla con el mismo algoritmo, coincide con
   `usuarios.password`.
6. Si el job de creación del AdminCliente falla después de que el tenant ya existe, el tenant sigue
   existiendo (no se revierte) y el error queda registrado en el log del servidor.
7. El frontend muestra los campos `nombre`, `apellido_paterno` (requeridos) y `apellido_materno`
   (opcional) en `CrearTenantView.vue`, y el campo `email` ya no se muestra como opcional.
8. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. El usuario a crear es el AdminCliente (uno de los tres roles de `usuarios.rol` en la base del
   tenant) — el primer acceso del dueño/administrador del negocio recién dado de alta, no un
   Despachador ni un Conductor.
2. La creación de este AdminCliente ocurre automáticamente como parte del mismo flujo de alta de
   tenant (`POST /admin/tenants`), sin pantalla ni endpoint separado — se dispara justo después de
   que la base del tenant termina de migrarse.
3. El ADMIN_CENTRAL captura el email del AdminCliente en el mismo formulario de "Crear tenant"
   (reutilizando el campo `email` que ya existe para el tenant — mismo correo para ambos, sin
   duplicar el campo).
4. La contraseña inicial se genera automáticamente por el sistema (aleatoria, segura) — el
   ADMIN_CENTRAL no la escribe a mano.
5. La contraseña generada se entrega por correo electrónico al AdminCliente (mismo mecanismo
   SMTP/Mailpit ya definido en la spec 004) — no se muestra en pantalla al ADMIN_CENTRAL ni se
   expone en la respuesta de la API.
6. En el primer login no se obliga a cambiar la contraseña generada — se deja como un login normal,
   sin lógica de "forzar cambio de contraseña" en esta historia.
7. Esta historia no incluye el login en sí de AdminCliente/Despachador/Conductor (usuarios de
   tenant) — solo deja creado el primer usuario con sus credenciales. El login de tenant sigue
   pendiente, tal como ya lo dejó abierto la spec 004.
8. Si falla la creación del AdminCliente después de que el tenant y su base ya se crearon con
   éxito, se registra el error pero el tenant no se revierte (ya existe con su base migrada) —
   reintentar quedaría para otra historia.
9. Se agrega un job propio (`CrearAdminClienteInicial`) al pipeline de `TenantCreated` de
   `stancl/tenancy`, en vez de usar el job genérico `SeedDatabase` (que hoy está comentado y no
   sirve para datos dinámicos), para insertar este primer usuario en la tabla `usuarios` del tenant
   recién migrado.
10. No se crea ningún Despachador ni Conductor en esta historia — solo el AdminCliente.
11. Como `usuarios.nombre` y `usuarios.apellido_paterno` son columnas requeridas y el formulario de
    Crear tenant no capturaba datos de una persona (solo del negocio), se agregan campos nuevos y
    requeridos (`nombre`, `apellido_paterno`) más uno opcional (`apellido_materno`) al formulario,
    en vez de reutilizar `nombre_comercial` o `razon_social` como nombre de la persona.
