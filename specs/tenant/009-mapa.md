# Spec: Infraestructura de Google Maps — servicio central, autocompletado y rutas reales

## Historia de usuario

Historia técnica (no ligada a un solo rol de negocio): queremos dejar toda la funcionalidad de
Google Maps lista y centralizada para el resto del proyecto:

1. Autocompletado de direcciones al capturar un pedido.
2. Dibujo de rutas (polyline) reales por calles entre dos puntos.
3. Mapas reutilizables en cualquier pantalla de la app que los necesite, sin duplicar código.

Como consecuencia directa, el Despachador ve en su Panel el mapa de conductores activos (spec
original 009) con la ruta hacia el pedido que cada conductor tiene asignado, y quien captura un
pedido (AdminCliente/Despachador) ve sugerencias de dirección y una vista previa de la ruta con
distancia/tiempo estimado.

## Objetivo / Alcance

**A. Servicio de mapas centralizado**

Un único punto de acceso a Google Maps para toda la app, en `frontend/src/services/maps/`:

- `MapService.ts` — fachada + instancia única (singleton) que expone las operaciones que
  cualquier pantalla necesita: inicializar un mapa, poner/actualizar/quitar marcadores, dibujar
  una ruta real o una línea recta de respaldo, centrar el mapa.
- `BaseProvider.ts` — el "contrato": la lista de operaciones que cualquier proveedor de mapas debe
  saber hacer, separada de cómo Google específicamente las hace.
- `GoogleProvider.ts` — la implementación concreta con Google Maps (carga del SDK, marcadores,
  Directions API con fallback a polyline, autocompletado vía Places).

Hoy no existe ningún archivo en `frontend/src/services/`; esta spec lo crea desde cero. Ningún
componente Vue habla con Google directamente — todos pasan por `MapService`.

**B. Autocompletado de direcciones**

Un componente reutilizable de campo de dirección con sugerencias de Google, usado en los tres
lugares que hoy capturan una dirección de recogida/entrega como texto libre:

- `CrearPedidoView.vue` / `EditarPedidoView.vue` (spec 006) — campos `direccion_recogida`,
  `direccion_entrega`.
- `NuevaEntregaPanel.vue` (spec 011) — mismos campos.

Al escribir, se muestran sugerencias de Google; al elegir una, se completa el texto y se rellenan
también los campos de latitud/longitud existentes en esos formularios. El campo sigue aceptando
texto libre (no obliga a elegir una sugerencia) — mismo comportamiento de hoy, solo que ahora
ayuda a escribir.

**C. Ruta real (Directions API) con respaldo de línea recta**

Se usa en dos lugares, ambos parte de esta misma historia:

- **Formulario de pedido** (Crear/Editar Pedido y Nueva Entrega): un mapa pequeño de vista previa
  dentro del formulario dibuja la ruta real entre recogida y entrega, con un texto tipo
  "12.3 km · 18 min". Es solo referencia visual — no se usa para calcular ningún importe
  automáticamente.
- **Mapa de conductores** (spec 009 original, ver punto D): ruta desde la posición del conductor
  hasta el punto correspondiente de su pedido asignado.

Si Google no puede calcular la ruta (cuota excedida, sin ruta por calles, error de red), se dibuja
una línea recta simple entre los dos puntos como respaldo, sin texto de distancia/tiempo.

**D. Mapa de conductores (lo que ya describía esta spec) — se construye ahora sobre el servicio nuevo**

Primera versión del componente `MapaConductores.vue`, columna central del layout de 3 columnas
descrito en `tenant/007-panel-despachador.md`. Usa datos ficticios (fixture compartido con
`tenant/010-drivers.md`), sin rastreo en tiempo real:

- `UiCard` con título "Mapa" ocupando la columna central.
- Mapa centrado en una ciudad ficticia fija, con zoom fijo. **Corrección** (ver
  `011-asignacion-ciudades-admin-cliente.md`): si el tenant tiene ciudades asignadas a algún
  `AdminCliente`, el mapa ajusta su encuadre (`fitBounds`) a esas ciudades en vez de usar el centro
  fijo; sin ciudades asignadas, el comportamiento original de esta spec no cambia.
- Un marcador por cada conductor ficticio activo de `conductoresActivosFixture`.
- Si el conductor tiene un pedido asignado (ver extensión del fixture en "Decisión técnica"), se
  dibuja la ruta real desde la posición del conductor hasta el punto de recogida (si todavía no
  llega a recoger) o hasta el punto de entrega (si ya va en camino).
- Si falta `VITE_GOOGLE_MAPS_API_KEY`, se muestra un mensaje en vez de intentar cargar el mapa.

**No incluye (se mantiene fuera de alcance):**

- Rastreo en tiempo real de posiciones (websockets/polling) — marcadores y rutas se pintan al
  montar, tomados del fixture.
- Clustering de marcadores, click en marcador para ver detalle.
- Usar la distancia/tiempo estimado de la ruta para calcular automáticamente el importe del
  pedido.
- Validar que una dirección autocompletada caiga dentro de una zona de cobertura/geofence
  (spec 015 — es un tema aparte).
- Implementar un proveedor de mapas alternativo (ej. Leaflet) — solo se deja preparado el
  contrato (`BaseProvider`) para que sea más fácil hacerlo en el futuro; Google sigue siendo el
  único proveedor real.
- ~~Ubicación real del tenant como centro del mapa (sigue sin existir ese dato).~~ Implementado en
  `011-asignacion-ciudades-admin-cliente.md`: ciudades (Google Places) asignadas por ADMIN_CENTRAL
  a cada `AdminCliente`, usadas para el encuadre.

## Decisión técnica

### Por qué un servicio central en vez de que cada componente cargue Google Maps

Hoy no existe ningún mapa construido en código (`MapaConductores.vue` de esta misma spec tampoco
se había implementado). Si cada pantalla que necesita un mapa (mapa de conductores, vista previa
de ruta en 2 formularios distintos, autocompletado en 3 campos) cargara el SDK de Google por su
cuenta, se repetiría la misma lógica de carga, manejo de errores y de la API key en cada lugar.
`MapService` centraliza eso: cualquier componente pide "dame un mapa aquí" o "dame sugerencias
para este texto" y el servicio resuelve el resto.

### Por qué se separa un contrato (`BaseProvider`) del proveedor real (`GoogleProvider`)

Es preparación a futuro, no una necesidad inmediata: si algún día se quisiera cambiar de proveedor
de mapas, solo habría que implementar `BaseProvider` con otra librería e intercambiarlo dentro de
`MapService`, sin tocar los componentes Vue que ya lo usan. Sigue el patrón Strategy: `BaseProvider`
define qué debe saber hacer un proveedor (inicializar mapa, marcadores, rutas, autocompletar,
centrar); `GoogleProvider` es la única estrategia implementada por ahora.

### Autocompletado: no reemplaza el campo de texto libre

El campo sigue siendo un input de texto normal — el autocompletado solo agrega una lista de
sugerencias mientras se escribe. Si el usuario no elige ninguna sugerencia, el texto que escribió
se guarda tal cual (mismo comportamiento actual, donde `direccion_recogida`/`direccion_entrega` son
texto libre sin validar contra Google). Al elegir una sugerencia sí se rellenan automáticamente
los campos de latitud/longitud ya existentes en esos formularios.

### Ruta real con respaldo: cuándo se dibuja cada una

`MapService` intenta primero `GoogleProvider` → Directions API (ruta real por calles). Si la
respuesta no es exitosa (cuota, sin ruta encontrada, error de red) o la promesa se rechaza, dibuja
en su lugar una `Polyline` recta entre los mismos dos puntos, sin texto de distancia/tiempo (ese
texto solo se muestra cuando la ruta real sí se pudo calcular). El componente que pidió la ruta no
necesita saber cuál de las dos se dibujó — recibe el resultado (`{distancia, duracion} | null`) y
decide si muestra el texto.

### Extensión de los fixtures para poder dibujar la ruta del conductor a su pedido

`ConductorActivoFicticio` (spec 010, `frontend/src/fixtures/panelDespachador.ts`) hoy no tiene
ningún dato de pedido asignado — no hay forma de saber a dónde debería ir la ruta. Se le agrega un
campo opcional:

```ts
export interface ConductorActivoFicticio {
  // ...campos existentes (id, nombre, estado, vehiculo_placa, latitud, longitud)
  pedidoAsignado?: {
    numero_pedido: string
    estado: 'TOMADO' | 'ARRIBADO' | 'EN_CAMINO' | 'ARRIBADO_A_ENTREGA'
    direccion_recogida: string
    latitud_recogida: number
    longitud_recogida: number
    direccion_entrega: string
    latitud_entrega: number
    longitud_entrega: number
  }
}
```

Solo algunos conductores del fixture traen `pedidoAsignado` (los demás, `undefined` — sin ruta que
dibujar). El destino de la ruta se elige según `pedidoAsignado.estado`: `TOMADO`/`ARRIBADO` → va
hacia la recogida; `EN_CAMINO`/`ARRIBADO_A_ENTREGA` → va hacia la entrega.

### Librerías de Google que se cargan

Se agrega `places` a la carga del SDK (para el autocompletado). El cálculo de rutas
(`DirectionsService`/`DirectionsRenderer`) es parte del núcleo de la Maps JavaScript API y no
requiere una librería aparte. No cambia nada visual de lo que ya existía.

## Reglas de negocio

- El campo de dirección con autocompletado nunca obliga a elegir una sugerencia; el texto libre
  sigue siendo un valor válido.
- Elegir una sugerencia rellena latitud/longitud; si el usuario edita el texto después sin elegir
  una sugerencia nueva, esos valores de latitud/longitud no se vuelven a tocar (igual que hoy, son
  campos editables por separado).
- La ruta real se recalcula cada vez que ambas direcciones (recogida y entrega) tienen datos
  suficientes para ubicarlas en el mapa.
- Si la ruta real falla, se dibuja una línea recta de respaldo; nunca se deja el mapa sin ningún
  trazo si ambos puntos son válidos.
- En el mapa de conductores, solo se dibuja ruta para conductores con `pedidoAsignado`; el destino
  (recogida o entrega) depende del estado de ese pedido, según lo descrito arriba.
- ~~El mapa se centra en coordenadas fijas ficticias y usa un zoom fijo (ej. 12), sin ajustar
  automáticamente el encuadre a los marcadores.~~ Corrección (spec 011): con ciudades asignadas,
  el mapa sí ajusta el encuadre a ellas (`fitBounds`); sin ciudades, se mantiene el centro/zoom
  fijos aquí descritos. Como parte de esa corrección se encontró que `drawRoute` (Directions API)
  reencuadraba el mapa a cada ruta dibujada por defecto, pisando cualquier `fitBounds` previo —
  `MapaConductores.vue` ahora pasa `{ preserveViewport: true }` en sus llamadas a `drawRoute` para
  evitarlo; `UiVistaPreviaRuta.vue` no cambia (sigue sin pasar esa opción, porque ahí sí se quiere
  que la ruta autoencuadre su propio mapa pequeño).
- Sin `VITE_GOOGLE_MAPS_API_KEY` configurada, ningún componente que use `MapService` intenta cargar
  el SDK — todos muestran el mismo tipo de mensaje de configuración pendiente.

## Datos ficticios (fixture)

Reusa y extiende `conductoresActivosFixture` de `frontend/src/fixtures/panelDespachador.ts` (mismo
fixture que `tenant/010-drivers.md`), agregando el campo opcional `pedidoAsignado` descrito en
"Decisión técnica". Al menos 2 de los conductores ficticios del fixture deben traer
`pedidoAsignado`, cubriendo un caso "hacia recogida" y un caso "hacia entrega".

## Frontend (Vue 3)

- **Servicio nuevo** `frontend/src/services/maps/MapService.ts`, `BaseProvider.ts`,
  `GoogleProvider.ts` — ver "Decisión técnica". `MapService` es la única puerta de entrada; ningún
  componente importa `GoogleProvider` directamente.
- **Componente nuevo** `frontend/src/components/ui/UiAddressAutocomplete.vue`: input de texto que
  envuelve `MapService` para pedir sugerencias de Google mientras el usuario escribe; emite
  `update:modelValue` (texto) y `select` (con lat/lng cuando el usuario elige una sugerencia). Se
  usa para reemplazar los inputs planos de `direccion_recogida`/`direccion_entrega` en
  `CrearPedidoView.vue`, `EditarPedidoView.vue` y `NuevaEntregaPanel.vue`.
- **Componente nuevo** `frontend/src/components/ui/UiVistaPreviaRuta.vue`: recibe las coordenadas
  de recogida y entrega por props, muestra un mapa pequeño con la ruta (real o línea recta de
  respaldo) vía `MapService`, y el texto de distancia/tiempo cuando la ruta real sí se calculó. Se
  monta en los mismos tres formularios de arriba.
- **Componente nuevo** `frontend/src/components/panel/MapaConductores.vue`: usa `MapService` para
  inicializar el mapa, agregar un marcador por conductor de `conductoresActivosFixture`, y dibujar
  la ruta de cada conductor con `pedidoAsignado` hacia su destino correspondiente. Envuelto en
  `UiCard` (`title="Mapa"`); muestra el mensaje de configuración pendiente si falta la API key. Se
  monta desde `PanelView.vue` en la columna central del grid de 3 columnas.
- **Dependencia nueva** en `frontend/package.json`: `@googlemaps/js-api-loader` (+
  `@types/google.maps` como dev dependency).
- **Variable de entorno**: `VITE_GOOGLE_MAPS_API_KEY` (ya prevista), documentada en el archivo de
  ejemplo de variables de entorno del frontend, sin valor real versionado.

## Fuera de alcance

- Rastreo en tiempo real de posiciones.
- Clustering de marcadores, click/interacción sobre marcadores (info window, resaltar en lista).
- Usar la distancia/tiempo estimado de la ruta para calcular automáticamente algún importe del
  pedido — queda como referencia visual.
- Validar que una dirección esté dentro de una zona de cobertura/geofence (spec 015).
- Implementar un segundo proveedor de mapas (Leaflet u otro) — solo se prepara el contrato.
- ~~Ubicación real del tenant como centro del mapa.~~ Implementado en
  `011-asignacion-ciudades-admin-cliente.md`.
- Conseguir/gestionar la clave real de Google Maps — pendiente operativo, no de código.

## Criterios de aceptación

1. Ningún componente Vue importa `GoogleProvider` directamente; todos pasan por `MapService`.
2. En los campos de dirección de `CrearPedidoView.vue`, `EditarPedidoView.vue` y
   `NuevaEntregaPanel.vue`, escribir texto muestra sugerencias de Google; elegir una completa el
   campo y rellena latitud/longitud; no elegir ninguna conserva el texto libre escrito.
3. En esos mismos tres formularios, con recogida y entrega capturadas, se muestra un mapa de vista
   previa con la ruta real y un texto de distancia/tiempo; si Directions falla, se muestra una
   línea recta sin texto de distancia/tiempo, sin error visible para el usuario.
4. Con `VITE_GOOGLE_MAPS_API_KEY` configurada, la columna central del Panel muestra el mapa de
   conductores con un marcador por conductor ficticio activo, y una ruta dibujada para cada
   conductor con `pedidoAsignado` (hacia recogida o entrega según su estado).
5. Sin `VITE_GOOGLE_MAPS_API_KEY` configurada, cualquier componente que use `MapService` muestra el
   mensaje de configuración pendiente, sin errores en consola ni pantalla en blanco.
6. Ningún componente hace peticiones HTTP propias más allá de las que dispara el SDK de Google.
7. ESLint/Prettier corren sin errores.

## Supuestos asumidos (registro completo)

1. Se crea un servicio de mapas centralizado (`MapService`, Facade + Singleton) que reemplaza
   cualquier carga directa del SDK de Google en componentes individuales.
2. Se separa un contrato (`BaseProvider`, patrón Strategy) del proveedor real (`GoogleProvider`),
   como preparación a futuro para poder cambiar de proveedor sin tocar los componentes Vue.
3. El autocompletado de direcciones se agrega a los campos de recogida/entrega de
   `CrearPedidoView.vue`, `EditarPedidoView.vue` (spec 006) y `NuevaEntregaPanel.vue` (spec 011),
   sin obligar a elegir una sugerencia — el texto libre sigue siendo válido.
4. El polyline incluye la ruta real por calles (Directions API), no solo una línea recta —
   corrección sobre la asunción inicial.
5. La ruta real se dibuja tanto en el formulario de pedido (recogida→entrega, con distancia/tiempo
   como referencia visual) como en el mapa de conductores (hacia el pedido asignado).
6. Si la Directions API falla, se dibuja una línea recta de respaldo, sin texto de
   distancia/tiempo.
7. Esta historia deja lista la infraestructura para que futuras pantallas la reutilicen; no agrega
   mapas a pantallas que hoy no los tienen, salvo el mapa de conductores (parte original de esta
   spec) y las vistas previas de ruta en los formularios ya mencionados.
8. Se sigue requiriendo `VITE_GOOGLE_MAPS_API_KEY`; sin ella, todo componente que use `MapService`
   muestra el mismo tipo de mensaje de configuración pendiente.
9. El autocompletado no valida cobertura/geofence (spec 015, fuera de alcance).
10. No se implementa ningún proveedor de mapas alternativo — Google sigue siendo el único real.
11. `conductoresActivosFixture` (spec 010) se extiende con un campo opcional `pedidoAsignado` para
    poder dibujar la ruta del conductor hacia recogida o entrega según el estado de ese pedido.
12. Ni `MapaConductores.vue` ni el fixture con `pedidoAsignado` existían como código antes de esta
    spec — se construyen directamente sobre el servicio nuevo, sin necesidad de migrar nada.
