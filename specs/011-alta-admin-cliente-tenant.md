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
- La contraseña se genera con `App\Support\PasswordGenerada` (letras, números y un solo símbolo,
  sin diagonales), y se guarda hasheada (`Hash::make()`) — igual que cualquier contraseña del
  sistema. No se usa `Str::password()` directo porque su juego de símbolos incluye `\`, que el
  Markdown del correo de credenciales se come: el usuario recibe una contraseña distinta de la
  guardada y no puede entrar.
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

## Enmienda: la ruta de acceso en el correo de bienvenida

> Historia del dueño del producto: *"Quiero que el correo muestre también la ruta completa que el
> tenant usará y una explicación breve de lo que eso significa. Algo así como: Su ruta de acceso
> será http://localhost:5173/t/moto-express-celaya/login"*.

### Historia de usuario

Como AdminCliente recién dado de alta, quiero que el correo con mis credenciales me diga también
**a qué dirección entrar** y qué significa esa dirección, para no tener que adivinar ni pedirla,
y para saber que es la que debo compartir con mi equipo.

### Objetivo / Alcance

Hoy `CredencialesAdminCliente` manda nombre comercial, correo y contraseña, pero ningún enlace: el
AdminCliente recibe con qué entrar y no dónde. El correo de los demás usuarios del tenant
(`CredencialesUsuarioTenant`, spec `tenant/001`) sí lleva un botón "Ir al panel" con
`{FRONTEND_URL}/t/{slug}/login`. Esta enmienda lleva ese mismo enlace al correo del tenant nuevo y
le agrega una explicación breve.

Deja funcionando:

- La URL completa del panel del tenant (`{FRONTEND_URL}/t/{slug}/login`) en el correo de
  bienvenida, **dos veces**: como texto visible (para copiarla o guardarla) y como botón "Ir al
  panel" (para abrirla de un clic).
- Una explicación breve, en lenguaje llano y **antes** de las credenciales: esa dirección es
  exclusiva del negocio (la parte `/t/{slug}/` lo identifica), es la que debe usar siempre para
  entrar y la que debe compartir con sus despachadores y conductores; las credenciales del correo
  son las de su usuario administrador dentro de esa dirección.
- Un solo lugar que arma la URL, compartido por los dos correos de credenciales.

**No incluye:**

- Cambios al asunto, al saludo, a las credenciales ni al mecanismo SMTP/Mailpit del correo.
- Agregar la explicación al correo de usuarios del tenant (`CredencialesUsuarioTenant`): solo pasa
  a usar el helper; su texto no cambia.
- Reenviar el correo a tenants ya creados.
- Configurar una URL por tenant: la base sigue siendo `FRONTEND_URL` del servidor.

### Decisión técnica

- **La URL no se captura: se deriva.** El slug ya existe en el tenant desde que
  `TenantController@store` lo genera, y `CrearAdminClienteInicial` tiene el tenant completo en
  `$this->tenant`. Se pasa `$this->tenant->slug` como cuarto argumento a la notificación. La base
  es `config('app.frontend_url')` (`FRONTEND_URL`), la misma que ya usan CORS y el correo de
  recuperación de contraseña: en local `http://localhost:5173`, en producción la del servidor.
- **Helper `App\Support\UrlPanelTenant::login(string $slug): string`.** La línea
  `rtrim(config('app.frontend_url'), '/')."/t/{$slug}/login"` vivía dentro de
  `CredencialesUsuarioTenant`; al necesitarla un segundo correo, se extrae y ambos la llaman. Mismo
  criterio que `SaldoViajesTenant` y `ComisionesConductor`: una fórmula, un lugar. Si la ruta del
  login cambia (por ejemplo, dominio por tenant), se corrige una vez.
- **Orden del correo:** saludo → explicación con la URL como texto → credenciales (sin cambios,
  contraseña en code span) → botón "Ir al panel" → recomendación de cambiar la contraseña. Primero
  "dónde", luego "con qué".
- **Prueba del contenido.** `TenantTest` solo afirmaba que la notificación se envía; se le agrega
  la verificación de que el correo renderizado (`toMail()->render()`) contiene la URL con el slug
  del tenant creado y la frase de explicación.

### Reglas de negocio (de esta enmienda)

- **RN-E1** La URL del correo es exactamente `{FRONTEND_URL}/t/{slug}/login`, con el slug del
  tenant recién creado y sin barra final duplicada.
- **RN-E2** La URL aparece como texto visible y como botón; ambas idénticas.
- **RN-E3** La explicación precede a las credenciales; las credenciales no cambian.
- **RN-E4** Solo afecta a altas nuevas; no hay reenvío.

### Backend (Laravel) — cambios de esta enmienda

- **`App\Support\UrlPanelTenant`** (nuevo): `login(string $slug): string`.
- **`App\Notifications\CredencialesAdminCliente`**: constructor gana `string $slug`; `toMail()`
  agrega la línea de explicación con la URL en texto, el `->action('Ir al panel', $url)` y
  conserva el resto.
- **`App\Notifications\CredencialesUsuarioTenant`**: reemplaza su línea de URL por el helper. Sin
  cambio de texto.
- **`App\Jobs\CrearAdminClienteInicial`**: pasa `$this->tenant->slug` a la notificación.
- **`tests/Feature/Admin/TenantTest.php`**: en la prueba de alta, además de
  `assertSentOnDemand`, renderiza la notificación capturada y afirma que contiene
  `http://localhost:5173/t/{slug}/login` y "Tu dirección de acceso".

### Frontend (Vue 3)

Ninguno.

### Criterios de aceptación (de esta enmienda)

1. Al crear un tenant con slug `cafe-luna`, el correo recibido en Mailpit contiene el texto
   `http://localhost:5173/t/cafe-luna/login` y un botón "Ir al panel" que abre esa misma URL.
2. El correo explica, antes de las credenciales, que esa dirección es la del negocio y la que debe
   compartir con su equipo.
3. Correo y contraseña siguen apareciendo igual que antes; la contraseña recibida sigue
   coincidiendo con `usuarios.password`.
4. El correo de usuarios del tenant (`CredencialesUsuarioTenant`) produce exactamente la misma URL
   que antes.
5. `TenantTest` y `UsuarioTest` (o el que cubra `CredencialesUsuarioTenant`) pasan; Pint sin
   errores.

### Supuestos asumidos (continúa el registro de arriba)

12. La ruta se construye con el mismo patrón que ya usa el correo de usuarios del tenant:
    `{FRONTEND_URL}/t/{slug}/login`. No se captura ni configura nada nuevo por tenant.
13. La ruta aparece dos veces: como texto completo visible y como botón "Ir al panel".
14. La explicación dice que la dirección es exclusiva del negocio (la parte `/t/{slug}/` lo
    identifica), que es la que debe usar siempre para entrar y compartir con sus despachadores y
    conductores, y que las credenciales del correo son las de su usuario administrador ahí.
15. La explicación va antes de las credenciales; las credenciales se mantienen exactamente igual.
16. El resto del correo no cambia: asunto, saludo con el nombre comercial, remitente/SMTP.
17. Solo se modifica el correo del AdminCliente inicial (esta spec). El correo de los demás
    usuarios del tenant (spec `tenant/001`) ya tiene el botón; no se le agrega la explicación.
18. No se reenvía el correo a tenants ya creados; aplica solo a altas nuevas.
19. El slug se toma del tenant recién creado; no se pide en el formulario.

### Adiciones técnicas aceptadas

1. Pasar el `slug` a la notificación desde `CrearAdminClienteInicial`.
2. Helper `App\Support\UrlPanelTenant::login()` compartido por los dos correos de credenciales.
3. Prueba del contenido renderizado del correo en `TenantTest`.
4. Esta enmienda, al final de la spec, en vez de reescribirla.
