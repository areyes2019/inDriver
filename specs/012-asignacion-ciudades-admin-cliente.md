# Spec: Asignación de ciudades (Google Places) a cada AdminCliente, para centrar su mapa

## Historia de usuario

Como ADMIN_CENTRAL, quiero asignar a cada AdminCliente una o varias ciudades (buscadas y resueltas
con Google Places), para que al abrir su panel de control el mapa central del Panel aparezca
centrado/ajustado en esa(s) ciudad(es), en vez del centro fijo ficticio que usa hoy.

## Objetivo / Alcance

Dejar funcionando: una pantalla dentro del panel de ADMIN_CENTRAL para buscar ciudades (Google
Places, restringido a localidades) y asignárselas a un AdminCliente de un tenant; el guardado de
esa asignación en la base del tenant correspondiente; y que `MapaConductores.vue`, al abrir el
Panel, ajuste el encuadre del mapa a las ciudades del AdminCliente autenticado en vez del
centro/zoom fijo actual.

Es la continuación directa de `tenant/009-mapa.md`, que dejó explícitamente fuera de alcance
"Ubicación real del tenant como centro del mapa (sigue sin existir ese dato)", y de
`010-alta-admin-cliente-tenant.md`, que dejó creado el primer AdminCliente de cada tenant.

> **Ajuste hecho durante la implementación** (no estaba contemplado al escribir la spec original):
> la ruta `/panel` donde vive `MapaConductores.vue` era, antes de esta historia, exclusiva del rol
> Despachador (el router redirigía a cualquier AdminCliente a `/clientes`) — el AdminCliente no
> tenía forma de "abrir su panel". Se decidió (ver pregunta al usuario durante la implementación)
> abrir `/panel` también a AdminCliente, y que el encuadre por ciudades se calcule igual para
> ambos roles a partir de la unión de las ciudades de todos los AdminCliente del tenant
> (`ciudades_tenant`, ver "Decisión técnica"), en vez de depender de las ciudades propias de quien
> inició sesión — así Despachador y AdminCliente del mismo tenant ven siempre el mismo encuadre.

**No** incluye: filtrar pedidos/conductores por ciudad, que el propio AdminCliente edite sus
ciudades, ni tocar `zonas_servicio` (spec `tenant/015-configuracion-comisiones.md`), que es un
concepto distinto (cobertura operativa, no encuadre de mapa).

## Decisión técnica

- **Dónde vive la relación admin↔ciudad**: en la base de datos del tenant (no en la central).
  `Usuario` (AdminCliente) vive en la base del tenant; guardar ahí la relación evita cruzar dos
  bases físicas distintas y sigue el mismo patrón que `zonas_servicio`/`configuraciones_tenant`.
  Se agregan dos tablas nuevas a `database/migrations/tenant/`: `ciudades` (`id_ciudad`, `nombre`,
  `place_id` único, `lat`, `lng`, `bounds` json nullable) y la pivote `usuario_ciudades`
  (`id_usuario`, `id_ciudad`, únicos en conjunto, `cascadeOnDelete` en ambas FK).
- **Cómo escribe el ADMIN_CENTRAL en la base de un tenant**: se reutiliza el mismo mecanismo que ya
  usa `CrearAdminClienteInicial` (`tenancy()->initialize($tenant)` ... `tenancy()->end()` en
  `finally`), esta vez de forma síncrona dentro de un controlador nuevo (no un job): el
  ADMIN_CENTRAL necesita ver el resultado de inmediato en pantalla, no es un efecto secundario en
  segundo plano.
- **Buscador restringido a ciudades**: se agrega `searchCity(query)` a `GoogleProvider`/
  `MapService`, igual que el `searchAddress` que ya existe pero pidiendo
  `includedPrimaryTypes: ['(cities)']` a `AutocompleteSuggestion.fetchAutocompleteSuggestions`, para
  que solo sugiera localidades. Al elegir una sugerencia se resuelve con `place.location` +
  `place.viewport` (el área aproximada que Google reporta para esa ciudad), a diferencia de
  `resolveAddress` que hoy solo trae `formattedAddress`/`location`.
- **El frontend ya manda la ciudad resuelta**: nombre, `place_id`, `lat`, `lng` y `bounds` viajan
  completos desde el navegador (donde se resolvieron con el SDK de Google) al backend, que solo los
  guarda — mismo patrón que hoy con la latitud/longitud de direcciones en pedidos; el backend nunca
  vuelve a llamar a Google.
- **Guardar reemplaza el conjunto completo (`sync`)**: el endpoint de asignación no tiene
  altas/bajas individuales; recibe el arreglo completo de ciudades del AdminCliente y sincroniza la
  pivote — más simple para una pantalla de chips "agregar/quitar" + botón "Guardar".
- **Encuadre con varias ciudades**: se agrega `fitToPositions(containerId, positions[])` al
  contrato `BaseProvider`/`MapService`, que arma un `google.maps.LatLngBounds` extendido con el
  punto (o el `viewport`, si existe) de cada ciudad y llama `map.fitBounds(bounds)` — reemplaza el
  `center`+`zoom` fijos de `GoogleProvider.initialize()` solo cuando quien llama pasa ciudades
  explícitas; sin ciudades, se sigue usando `DEFAULT_CENTER`/zoom 12 igual que hoy.
- **Cómo llega la asignación al Panel**: `UsuarioResource` (la respuesta de
  `POST /t/{slug}/login` y `GET /t/{slug}/me`) se extiende con un arreglo `ciudades` (las propias
  del usuario autenticado, si es AdminCliente — vacío para Despachador). Además, ambas respuestas
  agregan `ciudades_tenant`: la unión de las ciudades de **todos** los AdminCliente del tenant
  (`Ciudad::whereHas('usuarios', fn ($q) => $q->where('rol', 'AdminCliente'))`). `MapaConductores.vue`
  usa `ciudades_tenant` (no `ciudades`) para el encuadre, precisamente para que Despachador —que
  nunca tiene ciudades propias pero sí accede al Panel— vea el mismo encuadre que configuró su
  AdminCliente. `ciudades` se conserva tal cual porque sigue siendo lo que necesita la pantalla de
  ADMIN_CENTRAL para editar por-admin.
- **Bug preexistente que esta historia expuso y corrigió**: `GoogleProvider.drawRoute()` creaba
  `DirectionsRenderer` sin `preserveViewport`, que por defecto re-encuadra el mapa a la ruta que
  dibuja — cada ruta de un conductor (datos ficticios en CDMX) sobreescribía silenciosamente
  cualquier `fitBounds()` previo. Pasaba inadvertido porque el centro por defecto ya era CDMX. Se
  agregó `RouteOptions.preserveViewport` (default `false`, sin cambiar el comportamiento de
  `UiVistaPreviaRuta.vue`, que sí depende de que la ruta autoencuadre) y `MapaConductores.vue` lo
  pasa en `true` en sus llamadas a `drawRoute`.
- **`CiudadResource` expone también `place_id`** (no solo `id_ciudad`/`nombre`/`lat`/`lng`/`bounds`
  como se pensó originalmente): la pantalla de ADMIN_CENTRAL lo necesita para reconstruir el
  arreglo completo de ciudades de un AdminCliente al agregar una nueva antes de guardar (`sync`
  reemplaza el conjunto completo, ver arriba).

## Backend (Laravel)

- **Migraciones nuevas** en `backend/database/migrations/tenant/`: `create_ciudades_table` y
  `create_usuario_ciudades_table` (ver columnas en "Decisión técnica").
- **Modelo nuevo** `App\Models\Tenant\Ciudad` (tabla `ciudades`, PK `id_ciudad`, cast `bounds` a
  `array`).
- **`App\Models\Tenant\Usuario`**: se agrega la relación `ciudades(): BelongsToMany` contra
  `Ciudad`, tabla pivote `usuario_ciudades`.
- **Controlador nuevo** `App\Http\Controllers\Admin\AdminClienteCiudadController`:
  - `index(Tenant $tenant)`: inicializa tenancy, devuelve los `Usuario` con `rol = 'AdminCliente'`
    de ese tenant junto con sus `ciudades` cargadas, para que el ADMIN_CENTRAL elija a quién
    asignar.
  - `update(Request $request, Tenant $tenant, int $idUsuario)`: valida un arreglo `ciudades` (cada
    elemento con `place_id`, `nombre`, `lat`, `lng`, `bounds` opcional), inicializa tenancy, hace
    `firstOrCreate` de cada ciudad por `place_id` en la tabla `ciudades` del tenant, y `sync()` de
    los IDs resultantes en la relación del `Usuario` indicado. Responde con el usuario y sus
    ciudades actualizadas.
  - Ambas acciones **resuelven el `UsuarioResource`/`ResourceCollection` a un arreglo plano
    (`->resolve()`) dentro del `try`**, antes del `finally { tenancy()->end(); }` — descubierto en
    pruebas end-to-end: si se devuelve el resource sin resolver, Laravel lo serializa después de
    que el método retorna (al construir la respuesta HTTP), momento en el que la conexión `tenant`
    ya no existe y cualquier atributo con cast (ej. `ultimo_acceso`) truena con
    `Database connection [tenant] not configured.`. Mismo cuidado aplicaría a cualquier
    controlador futuro que combine `tenancy()->initialize()/end()` manual con Eloquent Resources.
- **Rutas nuevas** en `backend/routes/api.php`, dentro del grupo `admin` + `auth:admin` +
  `throttle:admin-tenants` (junto a las rutas de `TenantController`):
  - `GET /admin/tenants/{tenant}/admins-cliente`
  - `PUT /admin/tenants/{tenant}/admins-cliente/{idUsuario}/ciudades`
- **`App\Http\Resources\Tenant\UsuarioResource`**: agrega
  `'ciudades' => CiudadResource::collection($this->whenLoaded('ciudades'))`.
- **Recurso nuevo** `App\Http\Resources\Tenant\CiudadResource`: expone `id_ciudad`, `nombre`,
  `place_id`, `lat`, `lng`, `bounds`.
- **`App\Http\Controllers\Tenant\AuthController@login` y `@me`**: delegan a un método privado
  `respuestaUsuario(Usuario $usuario): array` que carga `ciudades`, resuelve el `UsuarioResource`, y
  agrega `ciudades_tenant` (ver "Decisión técnica").

## Frontend (Vue 3)

- **`frontend/src/services/maps/GoogleProvider.ts` / `MapService.ts`**: método nuevo `searchCity`
  (autocompletado restringido a ciudades) y `resolveCity` (trae `location` + `viewport`); método
  nuevo `fitToPositions` en el contrato `BaseProvider` para ajustar el encuadre a varios puntos.
- **Componente nuevo** `UiCiudadAutocomplete.vue` (mismo patrón que
  `frontend/src/components/ui/UiAddressAutocomplete.vue`, pero llamando a `searchCity`/
  `resolveCity`), usado solo en la pantalla de asignación de ADMIN_CENTRAL.
- **`frontend/src/views/admin/tenants/DetalleTenantView.vue`**: se agrega una tarjeta
  "Administradores y ciudades" que lista los AdminCliente del tenant
  (`GET /admin/tenants/{id}/admins-cliente`) y, por cada uno, `UiCiudadAutocomplete` + chips con las
  ciudades ya asignadas (cada chip con una "x" para quitarla) y un botón "Guardar" que llama a
  `PUT /admin/tenants/{id}/admins-cliente/{idUsuario}/ciudades` con el arreglo completo vigente.
- **`frontend/src/components/panel/MapaConductores.vue`**: al montar, si el usuario autenticado del
  tenant (ya disponible vía `/t/{slug}/me`) trae `ciudades_tenant` no vacías, llama
  `mapService.fitToPositions(CONTAINER_ID, puntos)` antes de pintar los marcadores de conductores;
  si viene vacío, se conserva `mapService.initialize(CONTAINER_ID, { zoom: 12 })` tal como hoy.
  Además, sus llamadas a `mapService.drawRoute` pasan `{ preserveViewport: true }` (ver bug
  corregido en "Decisión técnica").
- **`frontend/src/router/index.ts`**: el guard de `tenant-panel` dejó de redirigir a AdminCliente a
  `/clientes` — ahora entran tanto `Despachador` como `AdminCliente` (cualquier otro rol futuro
  sigue redirigiendo).
- **`frontend/src/layouts/TenantLayout.vue`**: se agrega el ítem "Panel" a la barra de navegación
  también para AdminCliente (antes solo lo veía Despachador), como primer ítem, igual que ya lo
  tenía Despachador.

## Reglas de negocio

1. Un AdminCliente puede tener 0, 1 o varias ciudades asignadas.
2. Solo el ADMIN_CENTRAL asigna, edita o quita ciudades; el AdminCliente no tiene pantalla para
   modificarlas.
3. Guardar ciudades reemplaza el conjunto completo (no existe una acción de API para "agregar una
   sola"; el frontend arma la lista completa y el backend la sincroniza).
4. Sin ciudades asignadas, el mapa se comporta igual que hoy (spec `tenant/009-mapa.md`): centro y
   zoom fijos.
5. Con una o más ciudades asignadas, el mapa ajusta automáticamente el encuadre (`fitBounds`) para
   mostrarlas todas juntas al abrir el panel.
6. La asignación no filtra ni oculta ningún dato (pedidos, conductores, servicios) — es puramente
   visual, sobre el encuadre inicial del mapa.
7. El cambio de ciudades se refleja la siguiente vez que se abre o recarga el panel; no hay
   notificación en tiempo real a una sesión ya abierta.
8. `/panel` (con el mapa) es accesible tanto para AdminCliente como para Despachador del tenant, y
   ambos ven el mismo encuadre (la unión de ciudades de todos los AdminCliente del tenant) — no es
   una vista personalizada por usuario.

## Fuera de alcance

- Filtrar pedidos/conductores/servicios por ciudad — el mapa sigue mostrando todo lo que ya
  muestra hoy (fixture completo), solo cambia el encuadre inicial.
- Que el propio AdminCliente edite sus ciudades desde su panel.
- Notificar en tiempo real (websocket) un cambio de ciudades a una sesión ya abierta.
- Asignar ciudades a Despachador o Conductor — solo a AdminCliente.
- Relacionar esto con `zonas_servicio`/geofencing (spec `tenant/015-configuracion-comisiones.md`) —
  son conceptos distintos: zona de cobertura operativa vs. ciudad para centrar el mapa.
- Un selector para elegir "cuál ciudad ver primero" cuando hay varias — siempre se muestran todas
  ajustadas en un solo encuadre.
- Asignar una ciudad por defecto a los AdminCliente que ya existan hoy — quedan sin ciudades hasta
  que el ADMIN_CENTRAL las capture manualmente.

## Criterios de aceptación

1. `GET /admin/tenants/{tenant}/admins-cliente` devuelve solo usuarios con `rol = 'AdminCliente'`
   de ese tenant, cada uno con su arreglo `ciudades` (vacío si no tiene ninguna).
2. `PUT /admin/tenants/{tenant}/admins-cliente/{idUsuario}/ciudades` con un arreglo de ciudades
   válido reemplaza las ciudades asignadas; volver a consultar ese mismo AdminCliente refleja
   exactamente ese arreglo.
3. Enviar la misma ciudad (mismo `place_id`) para dos AdminCliente del mismo tenant no duplica el
   registro en `ciudades` — se reutiliza el ya existente.
4. `POST /t/{slug}/login` y `GET /t/{slug}/me` devuelven el arreglo `ciudades` del usuario
   autenticado y `ciudades_tenant` (unión de las de todos los AdminCliente del tenant).
5. En `DetalleTenantView.vue`, buscar una ciudad muestra sugerencias restringidas a ciudades (no
   direcciones); elegir una la agrega como chip; "Guardar" persiste el cambio.
6. Tanto AdminCliente como Despachador ven "Panel" en la barra de navegación y, al entrar, el mismo
   mapa encuadrado según `ciudades_tenant` (verificado en navegador con ambos roles).
7. Con al menos una ciudad asignada a algún AdminCliente del tenant, al entrar al panel (cualquiera
   de los dos roles) el mapa abre encuadrando esas ciudades, no en el centro/zoom fijo anterior; las
   rutas de conductores que se dibujan después no vuelven a mover ese encuadre.
8. Sin ninguna ciudad asignada en el tenant, el mapa se comporta igual que antes de esta spec.
9. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. "AdminCliente" es el usuario con `rol = 'AdminCliente'` dentro de la base de un tenant (no
   Despachador ni Conductor); un tenant puede tener más de uno.
2. Un AdminCliente puede tener una o varias ciudades asignadas simultáneamente.
3. La ciudad se busca con un autocompletado tipo Google Places restringido a resultados de tipo
   ciudad/localidad, no direcciones completas.
4. Al guardar una ciudad se conservan su nombre y coordenadas (lat/lng, y el área/`viewport` que
   Google reporte) para poder encuadrar el mapa.
5. La asignación solo afecta el centrado/zoom inicial del mapa; no filtra ni restringe ningún dato
   que ve el AdminCliente.
6. Con varias ciudades asignadas, el mapa se centra ajustando el encuadre para mostrarlas todas,
   en vez de elegir una sola por defecto.
7. Un AdminCliente sin ciudades asignadas conserva el comportamiento actual (centro/zoom fijo).
8. Solo el ADMIN_CENTRAL crea/edita/quita las ciudades asignadas a un AdminCliente.
9. El cambio de ciudades se refleja hasta que el AdminCliente abra o recargue su panel — sin
   tiempo real.
10. La gestión se hace desde una pantalla ya existente del panel de ADMIN_CENTRAL
    (`DetalleTenantView.vue`, el detalle de un tenant), no un módulo aparte.
11. Las ciudades y la relación admin↔ciudad se guardan en la base del propio tenant (no en la base
    central), siguiendo el mismo patrón que `zonas_servicio`/`configuraciones_tenant`.
12. El ADMIN_CENTRAL escribe en la base de un tenant reutilizando `tenancy()->initialize()`/
    `tenancy()->end()`, igual que ya lo hace `CrearAdminClienteInicial`, pero de forma síncrona en
    el controlador (no un job) porque el resultado se necesita de inmediato en pantalla.
13. Guardar ciudades es un reemplazo completo del conjunto (`sync`), no altas/bajas individuales
    por endpoint separado.
14. El encuadre a varias ciudades usa `fitBounds` sobre los puntos/áreas de todas las ciudades
    asignadas, reemplazando el `center`+`zoom` fijos solo cuando existen ciudades asignadas.
15. `/panel` deja de ser exclusiva de Despachador — se abre también a AdminCliente, y el encuadre
    por ciudades es el mismo para ambos roles (unión de ciudades de todos los AdminCliente del
    tenant), en vez de ser una vista personalizada por usuario — decisión tomada durante la
    implementación al descubrir que AdminCliente no tenía antes ninguna forma de llegar al mapa.
