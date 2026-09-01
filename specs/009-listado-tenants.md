# Spec: Listado, detalle, edición y cambio de estado de tenants

## Historia de usuario

Como ADMIN_CENTRAL, quiero ver la lista de todos los tenants del sistema con las acciones
correspondientes a lado de cada fila, para poder consultar su detalle, editar sus datos o
suspender/activar su acceso sin intervención manual en la base de datos.

## Objetivo / Alcance

Dejar funcionando: la tabla de tenants (con búsqueda dinámica y paginación), la pantalla de
detalle de un tenant, la edición de sus datos de alta, y la acción de suspender/activar su
`estado`. Es la continuación directa de `007-crear-tenants.md`, que dejó explícitamente fuera el
listado y la edición.

## Decisión técnica

Todos los ADMIN_CENTRAL tienen el mismo nivel de acceso a todos los tenants (sin cartera
asignada, según `db/01-base-de-datos.md`), así que "mis tenants" equivale a todos los tenants del
sistema. El filtrado y la paginación los resuelve el backend (no se cargan todos los tenants de
golpe en el navegador).

## Backend (Laravel)

- **Rutas** (`routes/api.php`, grupo `admin`, `middleware('auth:admin')`):
  - `GET /api/v1/admin/tenants` (`throttle:admin-tenants`) — lista paginada, con búsqueda.
  - `GET /api/v1/admin/tenants/{tenant}` (`throttle:admin-tenants`) — detalle de un tenant.
  - `PUT /api/v1/admin/tenants/{tenant}` (`throttle:admin-tenants`) — edita un tenant.
  - `PATCH /api/v1/admin/tenants/{tenant}/estado` (`throttle:admin-tenants`) — alterna
    Activo/Suspendido.
  - Las cuatro reutilizan el limitador `admin-tenants` ya definido en `AppServiceProvider` (20
    intentos por minuto por admin autenticado).
- **Controlador** `App\Http\Controllers\Admin\TenantController`:
  - `index(Request $request)`: pagina con `Tenant::query()`, ordenado por `created_at` descendente.
    Si llega `search`, filtra `nombre_comercial` con `LIKE %search%`. Tamaño de página fijo (15).
    Responde con `TenantResource::collection(...)` paginado.
  - `show(Tenant $tenant)`: responde `TenantResource`.
  - `update(Request $request, Tenant $tenant)`: valida igual que `store` (mismos campos:
    `nombre_comercial`, `razon_social`, `rfc`, `telefono`, `email`), pero la regla `unique` de
    `rfc`/`email` ignora el propio `id_tenant` (`Rule::unique(...)->ignore($tenant->id_tenant,
    'id_tenant')`). Actualiza y guarda; responde `200` con `TenantResource`. Inserta un registro en
    `logs_centrales` (`tipo = 'TENANT'`, `accion = 'EDICION'`, `descripcion` con el nombre
    comercial).
  - `cambiarEstado(Tenant $tenant)`: alterna `estado` entre `Activo` y `Suspendido` (si el tenant
    está en `Inactivo`, responde `422`, esa transición no aplica aquí) y fija `modo_estado =
    'MANUAL'`, siguiendo la regla ya definida en `db/01-base-de-datos.md`. Inserta un registro en
    `logs_centrales` (`tipo = 'TENANT'`, `accion = 'CAMBIO_ESTADO'`, `descripcion` con el estado
    nuevo). Responde `200` con `TenantResource`.
- **`TenantResource`**: sin cambios (ya excluye `database_password`); se reutiliza tal cual para
  `index`, `show`, `update` y `cambiarEstado`.

## Frontend (Vue 3)

- **Componente nuevo** `frontend/src/components/ui/UiConfirmDialog.vue`: modal de confirmación
  reutilizable que reemplaza el `confirm()` nativo del navegador. Recibe un mensaje a mostrar y
  avisa hacia afuera cuándo el usuario confirmó y cuándo canceló; mientras está abierto oscurece
  el fondo de la pantalla y se cierra únicamente con los botones del propio modal (no con Escape
  ni con clic fuera). Sigue la paleta y estilos ya usados en el proyecto (`brand-blue`/
  `brand-dark`, bordes redondeados), sin depender de ninguna librería externa de UI.
- **Vista nueva** `frontend/src/views/admin/tenants/ListaTenantsView.vue`:
  - Un campo de búsqueda arriba de la tabla que filtra por nombre comercial. Dispara la consulta
    al backend 300ms después de que el usuario deja de escribir (debounce), reiniciando a la
    página 1 en cada búsqueda nueva.
  - Tabla con columnas: nombre comercial, RFC, email, teléfono, estado, modo de estado. La tabla
    ocupa el 100% del ancho interior disponible del card (`UiCard`), sin `max-width` ni centrado
    propio (`mx-auto`); las columnas se distribuyen automáticamente según su contenido sobre ese
    ancho completo. En pantallas angostas conserva un ancho mínimo (`min-width`) para seguir siendo
    legible, con scroll horizontal dentro de su propio contenedor (no de la página completa).
  - Controles de paginación (anterior/siguiente + indicador de página).
  - Por fila, columna de acciones con dos botones (no enlaces de texto): "Ver detalle" (botón
    primario, con fondo sólido `brand-blue`, navega al detalle) y "Suspender"/"Activar" (botón
    secundario, con borde y sin fondo sólido, para distinguirse visualmente del primario; etiqueta
    según el `estado` actual; al hacer clic abre el modal `UiConfirmDialog` en vez del `confirm()`
    nativo del navegador; solo si el usuario confirma dentro del modal se llama al endpoint de
    cambio de estado, y la fila se refresca en éxito). Ambos botones siguen la paleta y esquinas
    redondeadas ya definidas en la guía de diseño base (`005-guia-diseno-base.md`), estilizados
    directamente con clases de Tailwind en esta vista, sin un componente `UiButton` nuevo.
- **Vista nueva** `frontend/src/views/admin/tenants/DetalleTenantView.vue`: muestra todos los
  campos de `TenantResource` en solo lectura, con un enlace a "Editar". El enlace queda separado de
  los datos con espacio adicional y una línea divisoria sutil por encima, siguiendo el patrón de
  la guía de diseño base (`005-guia-diseno-base.md`).
- **Vista nueva** `frontend/src/views/admin/tenants/EditarTenantView.vue`: mismo formulario que
  `CrearTenantView.vue` (mismos 5 campos), precargado con los datos actuales del tenant. Al
  enviar, llama a `PUT /admin/tenants/{id}`; en éxito muestra confirmación y redirige al detalle;
  en error muestra los mensajes de validación junto a cada campo (mismo patrón que
  `CrearTenantView.vue`), incluyendo el espacio adicional sobre el botón "Guardar cambios" definido
  en la guía de diseño base (`005-guia-diseno-base.md`).
- **Rutas** (`router/index.ts`), todas con `meta: { requiresAdminAuth: true }`:
  - `/admin/tenants` → `admin-tenants-lista`.
  - `/admin/tenants/:id` → `admin-tenants-detalle`.
  - `/admin/tenants/:id/editar` → `admin-tenants-editar`.
- **`DashboardView.vue`**: se agrega un enlace ("Ver tenants") hacia `/admin/tenants`, junto a los
  botones existentes.
- **Store/cliente HTTP**: igual que `CrearTenantView.vue`, se usa `lib/http.ts` directamente, sin
  store Pinia nuevo.

## Fuera de alcance

- Filtros adicionales (por estado, modo de estado, fecha) — solo búsqueda por nombre comercial.
- Ordenar la tabla por columnas distintas a fecha de alta.
- Editar `rfc` de forma que quede duplicado contra otro tenant (sigue validado como único,
  ignorando al propio tenant).
- Transición a `estado = Inactivo` desde esta pantalla (la acción solo alterna
  Activo/Suspendido).
- Regresar `modo_estado` a `AUTOMATICO` una vez que un ADMIN_CENTRAL lo puso en `MANUAL` — esa
  reversión queda para una historia futura.
- Eliminar tenants.
- Asignación de plan/suscripción, alta de ADMIN_CLIENTE, y campos de dirección del tenant —
  siguen fuera de alcance, igual que en la spec 007.

## Criterios de aceptación

1. `GET /api/v1/admin/tenants` sin sesión de admin responde `401`.
2. `GET /api/v1/admin/tenants` responde una página de tenants (máximo 15), ordenados por fecha de
   alta descendente, sin exponer `database_password`.
3. `GET /api/v1/admin/tenants?search=<texto>` responde solo los tenants cuyo `nombre_comercial`
   contiene el texto buscado.
4. `GET /api/v1/admin/tenants/{id}` responde el detalle del tenant; con un `id` inexistente
   responde `404`.
5. `PUT /api/v1/admin/tenants/{id}` con datos válidos actualiza el tenant y responde `200`.
6. `PUT /api/v1/admin/tenants/{id}` reenviando el mismo `rfc`/`email` que el tenant ya tenía no
   falla por duplicado; reenviando el `rfc`/`email` de otro tenant distinto responde `422`.
7. Tras una edición exitosa, existe un registro en `logs_centrales` con `tipo = 'TENANT'`,
   `accion = 'EDICION'` y el `id_tenant` correspondiente.
8. `PATCH /api/v1/admin/tenants/{id}/estado` sobre un tenant `Activo` lo deja `Suspendido` (y
   viceversa), fija `modo_estado = 'MANUAL'`, y responde `200`.
9. `PATCH /api/v1/admin/tenants/{id}/estado` sobre un tenant `Inactivo` responde `422`.
10. Tras un cambio de estado exitoso, existe un registro en `logs_centrales` con
    `tipo = 'TENANT'`, `accion = 'CAMBIO_ESTADO'` y el `id_tenant` correspondiente.
11. El frontend expone `/admin/tenants`, `/admin/tenants/:id` y `/admin/tenants/:id/editar`,
    protegidas por el mismo guard que `/admin`.
12. `DashboardView.vue` muestra un enlace hacia `/admin/tenants`.
13. En `/admin/tenants`, escribir en el buscador filtra la tabla por nombre comercial sin recargar
    la página completa, y la tabla pagina cuando hay más de 15 tenants.
14. En `/admin/tenants`, cada fila muestra dos botones (no enlaces de texto) visualmente distintos
    entre sí: "Ver detalle" (primario, fondo sólido) y "Suspender"/"Activar" (secundario, con
    borde, según su estado actual); esta última abre el modal `UiConfirmDialog` (no el `confirm()`
    nativo del navegador) y solo ejecuta el cambio de estado si el usuario confirma dentro del
    modal.
15. Cancelar dentro del modal de confirmación lo cierra sin llamar al endpoint de cambio de estado
    y sin alterar el estado del tenant en la tabla.
16. En `/admin/tenants/:id/editar`, el formulario carga con los datos actuales del tenant; al
    guardar muestra confirmación y regresa al detalle; los errores de validación se muestran junto
    a cada campo.
17. El botón "Guardar cambios" se ve claramente separado del campo anterior (o del mensaje de
    error/éxito), no pegado a él.
18. En `/admin/tenants/:id`, el enlace "Editar" se ve claramente separado de los datos del tenant
    (espacio adicional y línea divisoria), no pegado al último dato mostrado.
19. En `/admin/tenants`, la tabla ocupa el 100% del ancho interior del card (sin franjas vacías a
    los lados) en pantallas anchas, y sigue siendo legible con scroll horizontal propio en
    pantallas angostas.
20. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. "Usuario" es el ADMIN_CENTRAL; no aplica a AdminCliente/Despachador/Conductor.
2. "Mis tenants" equivale a todos los tenants del sistema, ya que todos los ADMIN_CENTRAL tienen
   el mismo nivel de acceso, sin cartera asignada.
3. El listado se accede desde un enlace nuevo en `DashboardView.vue`, junto al de "Crear tenant".
4. La tabla muestra nombre comercial, RFC, email, teléfono, estado y modo de estado — no fechas de
   vencimiento ni datos de dirección o de la base de datos del tenant.
5. Los tenants se listan ordenados por fecha de alta más reciente primero.
6. El listado usa paginación resuelta en el backend (15 por página), no carga todo de una vez.
7. La búsqueda es dinámica (se dispara mientras el usuario escribe, con una pequeña pausa de 300ms
   para no saturar el servidor) y busca únicamente por nombre comercial.
8. Las acciones junto a cada fila son "Ver detalle" y "Suspender"/"Activar"; no incluyen eliminar.
9. "Ver detalle" abre una pantalla de solo lectura con los datos completos del tenant, con un
   enlace a "Editar" desde ahí.
10. La edición cubre los mismos campos del alta (`nombre_comercial`, `razon_social`, `rfc`,
    `telefono`, `email`); no incluye dirección ni campos de la base de datos del tenant.
11. Cambiar el estado (Suspender/Activar) pide confirmación mediante un modal propio del proyecto
    (`UiConfirmDialog`), no el `confirm()` nativo del navegador, antes de ejecutarse; solo alterna
    entre Activo y Suspendido (no llega a Inactivo desde aquí), y fija `modo_estado = MANUAL`
    siguiendo la regla ya definida en `db/01-base-de-datos.md`.
12. Tanto la edición como el cambio de estado se registran en `logs_centrales`, siguiendo el mismo
    patrón de auditoría que ya usa la spec 007 para el alta.
13. El modal de confirmación (`UiConfirmDialog`) se construye como componente reutilizable en
    `components/ui/`, pensado para futuras pantallas que necesiten confirmación, no solo para el
    cambio de estado de tenants.
14. (Corrección) "Ver detalle" y "Suspender"/"Activar" se muestran como botones reales (con fondo
    o borde visible), no como enlaces de texto subrayado. "Ver detalle" usa el estilo de botón
    primario (fondo sólido `brand-blue`) y "Suspender"/"Activar" usa un estilo secundario (borde,
    sin fondo sólido) para que ambos se distingan a simple vista, siguiendo la paleta y esquinas
    redondeadas de la guía de diseño base (`005-guia-diseno-base.md`). No se crea un componente
    `UiButton` reutilizable nuevo; los estilos se aplican directamente en `ListaTenantsView.vue`.
15. El botón "Guardar cambios" de `EditarTenantView.vue` lleva un espacio adicional sobre el campo
    anterior (o el mensaje de error/éxito), siguiendo el mismo patrón de formularios de la guía de
    diseño base (005) ya usado en `CrearTenantView.vue`.
16. El enlace "Editar" de `DetalleTenantView.vue` sigue el mismo patrón de separación de acciones
    de la guía de diseño base (005): espacio adicional y línea divisoria sutil por encima, para no
    verse pegado al último dato del tenant.
17. La tabla de `ListaTenantsView.vue` ocupa el 100% del ancho interior del card, sin `max-width`
    ni centrado propio; solo conserva un ancho mínimo para pantallas angostas, resuelto con scroll
    horizontal en su propio contenedor (no un ancho fijo de layout). Las columnas se distribuyen
    automáticamente según su contenido sobre ese ancho completo, sin anchos fijos por columna.
