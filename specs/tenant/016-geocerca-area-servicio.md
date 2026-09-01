# Spec: Geocerca de área de servicio (AdminCliente) — acota el autocompletado de direcciones

## Historia de usuario

Como AdminCliente y dueño de flotilla, quiero dibujar en un mapa una geocerca para establecer el
área de servicio de mi flotilla, para que el autocompletado de direcciones de Google en los
formularios de pedido solo sugiera direcciones dentro de esa área.

## Objetivo / Alcance

Es la continuación directa de la parte D de `tenant/015-configuracion-comisiones.md` (zonas de
cobertura con geofence), que dejó explícitamente pendiente, sin spec numerada, "el `DrawingManager`
de Google Maps en sí" para dibujar el polígono sobre un mapa. Esta historia:

1. Agrega el picker visual de polígono a la pestaña **Zonas de cobertura** de
   `ConfiguracionView.vue` — hoy esa pestaña solo administra nombre/descripción/estado.
2. Usa esas geocercas para **acotar** las sugerencias de Google en el autocompletado de dirección
   de pedidos (`CrearPedidoView.vue`, `EditarPedidoView.vue` de `tenant/006-crud-pedidos.md`, y
   `NuevaEntregaPanel.vue` de `011-alta-admin-cliente-tenant.md`), extendiendo `UiAddressAutocomplete`
   (`tenant/009-mapa.md`).
3. Corrige, tras la primera implementación, dos problemas detectados en uso real (ver "Corrección
   hecha durante la implementación" en "Decisión técnica"): el mapa del picker abría siempre
   centrado en un punto fijo de CDMX en vez de en las ciudades reales del tenant, y el flujo de alta
   pedía datos de más (descripción) antes de poder dibujar la geocerca.

**No incluye:**

- Validar si un pedido cae dentro o fuera de una zona al crearlo — sigue fuera de alcance, como ya
  decía `tenant/015`.
- Una geocerca distinta por `AdminCliente` — el área de servicio es del tenant completo (ver
  "Decisión técnica").
- Notificar en tiempo real un cambio de geocerca a una sesión ya abierta con el formulario de
  pedido abierto.
- Restringir el polígono para que caiga estrictamente dentro de las ciudades asignadas al tenant —
  el encuadre por ciudades (ver corrección abajo) es solo una ayuda visual para ubicar el mapa, no
  una validación de los vértices que se pueden dibujar.

## Decisión técnica

### Google solo restringe por rectángulo (o círculo), nunca por la forma exacta del polígono

El autocompletado de Google (`AutocompleteSuggestion.fetchAutocompleteSuggestions`) acepta un
parámetro `locationRestriction`, pero solo como un rectángulo (`{ north, south, east, west }`) —no
como una forma irregular. Para acotar sugerencias con la forma exacta del polígono habría que pedir
las coordenadas de cada sugerencia antes de mostrarla (una llamada extra a Google por cada
sugerencia, mientras el usuario todavía está escribiendo), lo cual es más lento y gasta cuota. Se
usa en su lugar el **rectángulo que envuelve todas las zonas activas** como filtro: rápido (va en la
misma llamada que ya se hace hoy) y suficiente para el objetivo, aceptando que en las esquinas del
rectángulo pueda colarse alguna sugerencia fuera del polígono exacto.

### El rectángulo se calcula en el backend, reutilizando el patrón de `ciudades_tenant`

Igual que `012-asignacion-ciudades-admin-cliente.md` agregó `ciudades_tenant` a la respuesta de
`POST /t/{slug}/login` y `GET /t/{slug}/me` (unión de ciudades de todos los `AdminCliente`, para que
Despachador vea el mismo encuadre), esta historia agrega `cobertura_bounds`: el rectángulo que
envuelve los vértices de **todas** las zonas de cobertura con `estado = 'Activo'` y `poligono` no
nulo del tenant. Se calcula una sola vez en el backend (no en cada tecla que el usuario escribe) y
se recalcula solo cuando cambian las zonas (se recarga en el próximo login/`me`, sin tiempo real —
mismo criterio que ya aceptó `tenant/015` para el resto de la configuración).

`cobertura_bounds` es `null` si no hay ninguna zona activa con polígono — en ese caso el
autocompletado no manda `locationRestriction` y se comporta exactamente igual que hoy.

### Geocerca a nivel tenant, no por `AdminCliente`

`zonas_servicio` ya es una tabla sin relación a un `Usuario` específico (a diferencia de
`ciudades`/`usuario_ciudades` de la spec 012) — es una configuración del tenant completo, accesible
solo por el rol `AdminCliente` pero compartida por todos los que tenga el tenant. Esta historia no
cambia ese modelo: no se agrega ninguna relación admin↔zona.

### Corrección hecha durante la implementación: `DrawingManager` está deprecado

Lo que decía originalmente esta sección (usar `google.maps.drawing.DrawingManager` para el modo
"dibujar desde cero") **ya no es cierto**: `@types/google.maps` (v3.66) marca `DrawingManager` como
`@deprecated` — "ya no está disponible en la Maps JavaScript API a partir de la versión 3.65" — y su
tipo quedó como una clase vacía sin constructor ni métodos, confirmando que Google lo retiró. En su
lugar, el polígono se arma a mano: un `google.maps.Polygon` editable (que sigue funcionando, es
parte del núcleo de `maps`, no de `drawing`) que arranca vacío, y cada clic sobre el mapa le agrega
un vértice a su `path` mientras no se esté editando una zona ya existente. No se agrega la librería
`drawing` al SDK (`loadSdk()` se queda con `maps`, `places` y `routes`, sin cambios respecto a
`tenant/009-mapa.md`).

### Corrección hecha tras la primera implementación: el mapa abría en un centro fijo (CDMX), no en las ciudades del tenant

En la primera versión, `abrirDibujoZona()` llamaba `mapService.initialize(containerId, { zoom: 12 })`
sin `center`, así que el mapa siempre arrancaba en `DEFAULT_CENTER` (CDMX, hardcodeado en
`GoogleProvider.ts`) sin importar dónde opera el tenant. Para un tenant fuera de CDMX esto hacía
inutilizable el picker en la práctica: el usuario tenía que descubrir por su cuenta, con pan manual,
dónde quedaba su zona real antes de poder dibujar algo útil.

La corrección reutiliza el mismo dato que ya usa `MapaConductores.vue` (spec 012):
`ciudades_tenant` (la unión de las ciudades asignadas por ADMIN_CENTRAL a todos los `AdminCliente`
del tenant, ya expuesta en `POST /t/{slug}/login` y `GET /t/{slug}/me`). Después de
`mapService.initialize()`, si `ciudades_tenant` no está vacío, se llama
`mapService.fitToPositions(containerId, puntos)` para que el mapa se ajuste a esas ciudades — si hay
varias, el encuadre las muestra todas juntas, permitiendo dibujar un polígono que se extienda de una
ciudad a otra. Sin ninguna ciudad asignada en el tenant, se conserva el comportamiento anterior
(centro/zoom fijo) como respaldo, igual que ya hace `MapaConductores.vue` en ese mismo caso.

Se usa `ciudades_tenant` (todas las del tenant) y no las ciudades propias de quien está dibujando,
por la misma razón que ya justificaba usarlo en spec 012: la geocerca es del tenant completo, no de
un `AdminCliente` en particular, así que el encuadre debe ser el mismo sin importar cuál
`AdminCliente` la esté dibujando o editando.

### Corrección hecha tras la primera implementación: el clic sobre el mapa nunca agregaba vértices (causa real, distinta del encuadre)

Validando en el navegador (login real como `AdminCliente` de un tenant con ciudades asignadas fuera
de CDMX) se confirmó que el reporte de "el mapa no permite dibujar nada" **no** era un efecto del
centro fijo — era un error de JavaScript no capturado (`Cannot read properties of undefined (reading
'addListener')`) que se disparaba de forma síncrona dentro de `enablePolygonDrawing`, antes incluso
de que se pudiera hacer clic: `new google.maps.Polygon({ paths: options.initialPoints ?? [], ... })`
recibía `paths: []` (arreglo vacío) al dibujar una geocerca desde cero (sin `initialPoints`), y en
esta versión de la Maps JavaScript API eso deja `polygon.getPath()` en `undefined` de forma
permanente — confirmado en el navegador probando `new google.maps.Polygon({ paths: [] })` contra
`new google.maps.Polygon({})` (sin la propiedad `paths`): solo el primero rompe `getPath()`. Como
`enablePolygonDrawing` llama `polygon.getPath()` para engancharle los listeners (`insert_at`,
`remove_at`, `set_at`) antes de registrar el clic sobre el mapa, la excepción interrumpía la función
completa y el listener de clic **nunca se llegaba a crear** — de ahí que ningún clic agregara jamás
un vértice. Al **editar** una zona con polígono ya guardado, `initialPoints` no está vacío, así que
el bug no se manifestaba — por eso el picker parecía funcionar "a medias" (cargar geocercas
existentes sí, pero jamás dibujar una desde cero).

La corrección es no pasarle `paths: []` a `google.maps.Polygon`: se omite la propiedad `paths` por
completo cuando no hay `initialPoints`, dejando que la API use su propio valor por defecto (que sí
deja `getPath()` en un `MVCArray` válido y vacío).

### Corrección hecha tras la primera implementación: flujo de alta simplificado (sin descripción, dibujar antes de nombrar)

La primera versión pedía nombre y descripción en un formulario aparte, creaba la zona vacía (sin
polígono) al enviarlo, y solo después —con una acción distinta, "Dibujar geocerca", por fila de la
tabla— se abría el mapa para agregarle el polígono con un segundo `PUT`. En el uso real esto
resultó confuso: el usuario llegaba al mapa (mal centrado, ver corrección anterior) sin entender por
qué no podía simplemente dibujar de una vez.

El flujo se invierte: crear una geocerca nueva empieza por el mapa. Un botón **"Nueva geocerca"**
abre directamente un mapa vacío (ajustado a `ciudades_tenant`, ver arriba) para dibujar el polígono;
al terminar, se captura solo el **nombre** y "Guardar" crea la zona ya completa
(`POST /t/{slug}/zonas-cobertura` con `{ nombre, poligono }`) en un único paso — ya no existe el
paso intermedio de crear una zona sin geocerca. El campo **Descripción** se quita del formulario y
de la tabla: no aporta nada al caso de uso real (identificar y dibujar un área), y la columna
`zonas_servicio.descripcion` se queda en la base de datos tal cual (nullable, sin migración) por si
se reutiliza más adelante, simplemente deja de leerse/escribirse desde esta pantalla.

Una zona creada antes de esta corrección que ya tenga polígono y/o descripción no se toca ni se
migra: se sigue mostrando y editando con normalidad (la descripción existente no se borra, solo deja
de ser editable desde la UI). Una zona antigua que se haya quedado sin polígono conserva la acción
"Dibujar geocerca" (mismo botón que "Editar geocerca", según tenga o no `poligono` ya guardado) para
agregárselo cuando se quiera, usando `PUT` como ya hacía.

### Nuevo método en el contrato de mapas: dibujar/editar un polígono

Se agrega a `BaseProvider`/`MapService`/`GoogleProvider` (`frontend/src/services/maps/`), con el
mismo patrón `containerId` que ya usa el resto del contrato (`drawRoute`, `fitToPositions`, etc.):

- `enablePolygonDrawing(containerId, options: { initialPoints?: LatLngLike[], onChange: (points: LatLngLike[]) => void })`
  — si no hay `initialPoints`, arranca un polígono editable vacío y escucha clics sobre el mapa para
  ir agregándole vértices (dibujar desde cero); si los hay, carga ese mismo tipo de polígono editable
  ya con esos vértices, sin escuchar más clics (editar una zona existente). En ambos casos el
  polígono queda **editable** (arrastrar vértices, agregar puntos sobre una arista) y `onChange` se
  dispara con el arreglo de puntos actualizado cada vez que cambia, para que el componente Vue
  mantenga el estado que se envía al backend en "Guardar".
- `disablePolygonDrawing(containerId)` — quita los listeners y el polígono editable del mapa (al
  salir de la pestaña o después de guardar).

Internamente, `GoogleProvider` usa un único `google.maps.Polygon` con `editable: true` tanto para
dibujar desde cero como para editar (ver "Corrección hecha durante la implementación" arriba —
`DrawingManager` no se usa por estar deprecado).

### `searchAddress` acepta un rectángulo opcional

`MapService.searchAddress(query, bounds?)` y `GoogleProvider.searchAddress(query, bounds?)` reciben
un segundo parámetro opcional `bounds: LatLngBoundsLike | null`. Cuando viene, se pasa como
`locationRestriction` a `fetchAutocompleteSuggestions`. `searchCity` **no** cambia — la búsqueda de
ciudades de la spec 012 es para el ADMIN_CENTRAL asignando ciudades a nivel país/región, no tiene
relación con el área de servicio de un tenant.

## Reglas de negocio

1. Al crear una geocerca nueva, el polígono es obligatorio (mínimo 3 vértices): el flujo de alta
   dibuja primero y nombra/guarda después, ya no existe la opción de crear una zona vacía sin
   geocerca desde la pantalla. Zonas creadas antes de esta corrección que no tengan polígono siguen
   existiendo y se les puede agregar uno después con "Dibujar geocerca".
2. La descripción ya no se captura ni se muestra en esta pantalla; la columna en base de datos sigue
   existiendo (nullable) para las zonas que ya la tenían.
3. El mapa del picker se ajusta (`fitBounds`) a `ciudades_tenant` (todas las ciudades asignadas por
   ADMIN_CENTRAL a los `AdminCliente` del tenant) al abrirse, tanto para crear como para editar una
   geocerca. Sin ninguna ciudad asignada en el tenant, se usa el centro/zoom fijo de respaldo.
4. La restricción de Google usa el rectángulo que envuelve **todas** las zonas `Activo` con polígono
   del tenant, no cada polígono por separado.
5. Sin ninguna zona `Activo` con polígono, el autocompletado no restringe nada — mismo comportamiento
   que antes de esta historia.
6. El campo de dirección nunca bloquea texto libre, aunque quede fuera de la geocerca — esta
   historia solo filtra qué sugerencias de Google aparecen, no valida lo que el usuario escribe a
   mano (mismo criterio ya establecido en `tenant/009-mapa.md`).
7. Solo el rol `AdminCliente` dibuja, edita o elimina geocercas (mismo permiso que ya protege
   `ZonaCoberturaController`).
8. El rectángulo de restricción se recalcula cada vez que se guarda un polígono, y se refleja la
   próxima vez que se abre o recarga un formulario de pedido — no hay actualización en tiempo real a
   una sesión con el formulario ya abierto.

## Backend (Laravel)

- **`App\Models\Tenant\ZonaServicio`**: método estático `boundsDeZonasActivas(): ?array` —
  recorre `ZonaServicio::where('estado', 'Activo')->whereNotNull('poligono')->get()`, junta todos los
  puntos `{lat, lng}` de todos los polígonos, y devuelve `{ north, south, east, west }` con los
  extremos, o `null` si no hay ningún punto.
- **`App\Http\Controllers\Tenant\AuthController::respuestaUsuario()`**: agrega
  `$data['cobertura_bounds'] = ZonaServicio::boundsDeZonasActivas();` — mismo lugar donde ya se
  agrega `ciudades_tenant` (spec 012), expuesto igual en `POST /t/{slug}/login` y `GET /t/{slug}/me`.
- No se agrega ninguna migración: `zonas_servicio.poligono` y `zonas_servicio.descripcion` ya
  existen desde `tenant/015`; la corrección de "sin descripción" es solo de UI, no toca la base de
  datos.
- `ZonaCoberturaController` no cambia — su validación (`nombre` requerido, `descripcion` nullable,
  `poligono` nullable con mínimo 3 puntos si viene) ya cubre lo que el nuevo flujo envía: el mínimo
  de 3 vértices antes de guardar lo sigue exigiendo el frontend, igual que ya hacía.

## Frontend (Vue 3)

- **`frontend/src/services/maps/types.ts`**: el rectángulo reutiliza `LatLngBoundsLike` (ya usado
  por `ResolvedCity.bounds`/`FitTarget.bounds`); se agrega el tipo `PolygonDrawOptions`
  (`{ initialPoints?: LatLngLike[], onChange: (points: LatLngLike[]) => void }`) para la firma de
  `enablePolygonDrawing`.
- **`frontend/src/services/maps/GoogleProvider.ts`**:
  - `loadSdk()` no cambia su lista de librerías (`maps`, `places`, `routes`) — `DrawingManager` no
    se usa (ver "Corrección hecha durante la implementación").
  - `searchAddress(query, bounds?)`: si `bounds` viene, se pasa como `locationRestriction` a
    `fetchAutocompleteSuggestions`.
  - `enablePolygonDrawing`: al construir el `google.maps.Polygon`, ya no pasa `paths: options.initialPoints ?? []`
    (arreglo vacío) — omite la propiedad `paths` por completo cuando no hay `initialPoints`, para no
    disparar el bug de `getPath()` descrito en "Decisión técnica" que impedía agregar vértices al
    dibujar una geocerca desde cero.
- **`BaseProvider.ts`** / **`MapService.ts`**: agregan las firmas de `searchAddress` (con el segundo
  parámetro opcional) y de `enablePolygonDrawing`/`disablePolygonDrawing`.
- **`frontend/src/stores/tenantAuth.ts`**: `UsuarioTenant` agrega `cobertura_bounds: LatLngBoundsLike | null`
  (el picker de geocerca lee además `ciudades_tenant`, que ya agregó la spec 012 al mismo tipo).
- **`frontend/src/components/ui/UiAddressAutocomplete.vue`**: prop nueva opcional `bounds?:
  LatLngBoundsLike | null`, se pasa como segundo argumento a `mapService.searchAddress(value,
  bounds)`.
- **`CrearPedidoView.vue`, `EditarPedidoView.vue`, `NuevaEntregaPanel.vue`**: los dos
  `UiAddressAutocomplete` (recogida y entrega) reciben `:bounds="tenantAuth.usuario?.cobertura_bounds
  ?? null"`.
- **`views/tenant/configuracion/ConfiguracionView.vue`**, pestaña "Zonas de cobertura":
  - El `UiAlert` de aviso se muestra solo si falta `VITE_GOOGLE_MAPS_API_KEY`.
  - Se quita el campo "Descripción" del formulario de alta y de la columna de la tabla.
  - El formulario de alta ("Nombre" + "Descripción" + "Agregar zona") se reemplaza por un botón
    **"Nueva geocerca"** que abre de una vez el mapa (mismo patrón de fila expandible que ya usa
    "Editar geocerca") para dibujar el polígono; un campo "Nombre" junto al botón "Guardar geocerca"
    hace `POST /t/{slug}/zonas-cobertura` con `{ nombre, poligono }` en un solo paso.
  - Cada fila de la tabla conserva la acción **"Dibujar geocerca"** (si `poligono` es `null`, para
    zonas antiguas sin polígono) o **"Editar geocerca"** (si ya tiene) que despliega la misma fila
    con mapa embebido y llama `enablePolygonDrawing` con los puntos actuales si los hay; "Guardar"
    hace `PUT /t/{slug}/zonas-cobertura/{zona}` con `{ nombre, poligono }` (ya sin `descripcion`);
    "Cancelar" llama `disablePolygonDrawing` sin guardar.
  - Tanto al crear como al editar, después de `mapService.initialize(containerId, { zoom: 12 })` se
    llama `mapService.fitToPositions(containerId, puntos)` con los puntos de
    `tenantAuth.usuario?.ciudades_tenant` si no viene vacío (ver "Corrección hecha durante la
    implementación" — encuadre por ciudades del tenant, no CDMX fijo).
  - Las acciones "Activar"/"Desactivar" y "Eliminar" de cada zona no cambian.
  - La columna "Estado" usa `UiBadge` (`color="green"` para `Activo`, `color="orange"` para
    `Inactivo`), siguiendo `003-actualizacion-guia-diseno.md`.
  - El botón de la acción de geocerca usa el ícono de Iconify `fluent-color:pin-24` (no existe un
    ícono de pin en `flat-color-icons`, así que se usa la colección de respaldo, mismo criterio que
    ya documentó la spec 003) junto al texto.
- **`frontend/scripts/generate-icon-data.mjs`**: agrega `'pin-24'` a `FLUENT_COLOR_ICON_NAMES` y se
  corre `npm run icons:build` para regenerar `assets/icon-data.json` (mismo mecanismo que ya usa la
  spec 003).

## Fuera de alcance

- Validar si un pedido cae dentro o fuera de una zona al crearlo (sigue fuera de alcance, igual que
  `tenant/015`).
- Una geocerca por `AdminCliente` individual — sigue siendo una configuración del tenant completo.
- Filtrado con la forma exacta del polígono (solo se usa el rectángulo que lo envuelve, ver
  "Decisión técnica").
- Notificación en tiempo real de un cambio de geocerca a una sesión con el formulario de pedido ya
  abierto.
- Cualquier cambio a `searchCity`/`UiCiudadAutocomplete` (spec 012) — es un flujo distinto
  (ADMIN_CENTRAL asignando ciudades), no se toca en esta historia.
- Restringir o validar que los vértices del polígono caigan dentro de las ciudades asignadas al
  tenant — el encuadre por ciudades es solo una ayuda visual, no una validación.
- Migrar o eliminar la columna `zonas_servicio.descripcion` — se queda en la base de datos aunque
  la UI deje de usarla.

## Criterios de aceptación

1. Al abrir "Nueva geocerca" (o "Dibujar/Editar geocerca" de una zona existente), si el tenant tiene
   al menos una ciudad asignada (`ciudades_tenant`), el mapa abre ajustado a esas ciudades en vez de
   un centro fijo en CDMX; con varias ciudades, el encuadre las muestra todas juntas.
2. Sin ninguna ciudad asignada en el tenant, el mapa abre con el centro/zoom fijo de respaldo, igual
   que antes de esta corrección.
3. Se puede hacer clic sobre el mapa para ir agregando vértices (mínimo 3) al dibujar una geocerca
   desde cero — validado manualmente en el navegador (login real como `AdminCliente`), corrigiendo
   el bug real reportado (`polygon.getPath()` quedaba `undefined` por pasar `paths: []`, ver
   "Decisión técnica").
4. "Nueva geocerca" pide únicamente el nombre (no descripción) y crea la zona con nombre + polígono
   en un solo guardado.
5. Una zona con polígono ya guardado muestra "Editar geocerca": se abre el mapa con esos vértices
   cargados y editables (se pueden arrastrar), sin pedir descripción, y "Guardar" persiste los
   cambios.
6. "Activar"/"Desactivar" y "Eliminar" siguen funcionando igual que antes de esta corrección.
7. Con al menos una zona `Activo` con polígono en el tenant, el autocompletado de dirección en
   `CrearPedidoView.vue`, `EditarPedidoView.vue` y `NuevaEntregaPanel.vue` (recogida y entrega) solo
   devuelve sugerencias de Google dentro del rectángulo que envuelve esas zonas.
8. Sin ninguna zona `Activo` con polígono, el autocompletado de esos mismos formularios se comporta
   igual que antes de esta historia (sin restricción).
9. El campo de dirección sigue aceptando texto libre sin obligar a elegir una sugerencia.
10. `POST /t/{slug}/login` y `GET /t/{slug}/me` devuelven `cobertura_bounds` (el rectángulo, o `null`
    si no hay zonas activas con polígono).
11. La columna "Estado" de la tabla de zonas usa `UiBadge` con los colores `green`/`orange`.
12. ESLint/Prettier (frontend) y Pint/tests de backend existentes corren sin errores.

## Supuestos asumidos (registro completo)

1. Spec numerada como `tenant/016-geocerca-area-servicio.md`, continuación directa de
   `tenant/015-configuracion-comisiones.md` (parte D, geofence pendiente) y de
   `tenant/009-mapa.md` (`MapService`/`GoogleProvider`).
2. "Dibujar la geocerca" ocurre dentro de la pestaña ya existente "Zonas de cobertura" de
   `ConfiguracionView.vue` — no es una pantalla nueva.
3. Puede haber una o varias zonas de cobertura por tenant; el área de servicio total es la unión de
   todas las zonas `Activo`.
4. Las zonas `Inactivo` se ignoran para restringir el autocompletado, aunque sigan existiendo como
   registro.
5. La geocerca aplica al mismo componente `UiAddressAutocomplete` usado en `CrearPedidoView.vue`,
   `EditarPedidoView.vue` (spec 006) y `NuevaEntregaPanel.vue` (spec 011), para `direccion_recogida`
   y `direccion_entrega`.
6. "Determinar los places que se muestran" = filtrar las sugerencias de Google: las que caen fuera
   de la geocerca no aparecen en la lista, sin advertencia ni deshabilitado visual.
7. Sin ninguna zona `Activo` con polígono, el autocompletado se comporta igual que hoy, sin
   restricción geográfica.
8. El campo sigue sin obligar a elegir una sugerencia; el texto libre fuera de la geocerca se guarda
   igual, sin bloqueo ni validación.
9. Dibujar = clic sobre el mapa para agregar vértices (mínimo 3, ya validado en backend), con
   vértices arrastrables antes de guardar.
10. Se puede editar el polígono de una zona ya creada (se abre con sus vértices actuales) y volver a
    guardarlo, reemplazando el anterior.
11. La geocerca es a nivel tenant (todas sus zonas), no una por cada `AdminCliente` individual,
    aunque el tenant pueda tener varios `AdminCliente` (spec 012).
12. Esta historia no valida si un pedido cae dentro o fuera de una zona al crearlo — sigue fuera de
    alcance, igual que ya decía la spec 015.
13. La restricción usa el rectángulo (bounding box) que envuelve todas las zonas activas, no el
    polígono exacto — límite de la API de Google (`locationRestriction` solo acepta rectángulo o
    círculo); se acepta el margen de error en los bordes del rectángulo.
14. El picker visual del polígono **no** usa `google.maps.drawing.DrawingManager` — está deprecado
    desde la versión 3.65 de la Maps JavaScript API (confirmado en `@types/google.maps`, que lo dejó
    como una clase vacía). Se dibuja a mano con un `google.maps.Polygon` editable y clics sobre el
    mapa, sin agregar la librería `drawing` ni ninguna dependencia npm nueva.
15. `MapService`/`BaseProvider`/`GoogleProvider` se extienden con `enablePolygonDrawing`/
    `disablePolygonDrawing`, siguiendo el mismo patrón `containerId` que el resto del contrato.
16. El rectángulo (`cobertura_bounds`) se calcula en el backend, no en el navegador, reutilizando el
    mismo patrón que ya usa `ciudades_tenant` en `AuthController::respuestaUsuario()`; se expone en
    `POST /t/{slug}/login` y `GET /t/{slug}/me`.
17. El estado de cada zona en la tabla de "Zonas de cobertura" usa `UiBadge` (`green`/`orange`) en
    vez de las clases sueltas de Tailwind, siguiendo `003-actualizacion-guia-diseno.md`.
18. Se usa el ícono `fluent-color:pin-24` (colección de respaldo — `flat-color-icons` no tiene
    ningún ícono de pin/ubicación) para la acción "Dibujar/editar geocerca", agregado a
    `FLUENT_COLOR_ICON_NAMES` en `generate-icon-data.mjs`, mismo mecanismo que ya usó la spec 003.
19. "Dueño de flotilla" es el mismo rol `AdminCliente` ya existente — no se crea un rol nuevo.
20. **(Corrección post-implementación)** El centro fijo en CDMX (`DEFAULT_CENTER` de
    `GoogleProvider.ts`) era un defecto real, no un comportamiento aceptado: se corrige para que el
    mapa del picker se ajuste (`fitBounds`) a `ciudades_tenant` (spec 012) al abrirse, tanto para
    crear como para editar una geocerca.
21. Se usa `ciudades_tenant` (unión de ciudades de **todos** los `AdminCliente` del tenant) y no las
    ciudades propias de quien dibuja, para que el encuadre sea el mismo sin importar cuál
    `AdminCliente` abra el picker — consistente con que la geocerca es del tenant completo, no de un
    admin individual (regla ya establecida en esta misma spec).
22. Si un polígono se dibuja abarcando dos o más de las ciudades asignadas al tenant, no hay ninguna
    validación que lo impida ni lo recorte — el encuadre por ciudades solo ayuda a ubicar el mapa
    visualmente.
23. **(Corrección post-implementación)** El campo "Descripción" deja de capturarse en el flujo de
    alta/edición de una geocerca — no aporta al caso de uso (identificar y dibujar un área). La
    columna `zonas_servicio.descripcion` se queda en la base de datos (nullable, sin migración) para
    las zonas que ya la tenían.
24. **(Corrección post-implementación)** El flujo de alta se invierte: se dibuja el polígono primero
    (botón "Nueva geocerca" abre el mapa de inmediato) y se nombra/guarda después, en un solo
    `POST` con `{ nombre, poligono }` — ya no existe el paso intermedio de crear una zona vacía sin
    geocerca y editarla después por separado.
25. Las acciones de editar (agregar/mover vértices y renombrar) y eliminar una zona, que la
    corrección pedía explícitamente, ya existían en la implementación original
    (`ZonaCoberturaController@update`/`@destroy`, botones "Editar geocerca"/"Eliminar" en la tabla)
    — se conservan sin cambios de comportamiento, solo sin el campo descripción.
26. **(Corrección post-implementación, causa real encontrada en navegador)** El reporte de que el
    mapa "no permitía dibujar nada" no era un efecto del centro fijo: era una excepción no capturada
    dentro de `enablePolygonDrawing` — `new google.maps.Polygon({ paths: [] })` (arreglo vacío, el
    valor que se pasaba al dibujar desde cero) deja `polygon.getPath()` en `undefined` de forma
    permanente en esta versión de la Maps JavaScript API, y como esa función se llama antes de
    registrar el listener de clic sobre el mapa, la excepción impedía que ese listener se llegara a
    crear. Se corrige omitiendo la propiedad `paths` cuando no hay `initialPoints`, en vez de
    pasarle un arreglo vacío. Confirmado en el navegador: crear una geocerca desde cero, hacer 3
    clics y guardarla funciona de punta a punta después de esta corrección.
