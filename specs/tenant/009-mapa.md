# Spec: Mapa de rastreo (columna central del Panel de Despachador)

## Historia de usuario

Como Despachador, quiero ver en un mapa la posición de los conductores activos al entrar a mi
Panel, para tener una vista geográfica inmediata de la operación.

## Objetivo / Alcance

Primera versión del componente `MapaConductores.vue`, columna central del layout de 3 columnas
descrito en la ampliación de `tenant/007-panel-despachador.md`. Usa Google Maps con datos ficticios
(fixture compartido con `tenant/010-drivers.md`), sin rastreo en tiempo real ni datos reales de
posición todavía.

Deja funcionando:

- `UiCard` con título "Mapa" ocupando la columna central.
- Mapa de Google centrado en una ciudad ficticia fija, con zoom fijo.
- Un marcador por cada conductor ficticio de `conductoresActivosFixture` (fixture compartido, ver
  spec 010), en la posición `latitud`/`longitud` que trae el fixture.
- Si la variable de entorno `VITE_GOOGLE_MAPS_API_KEY` no está configurada, se muestra un mensaje
  ("Configura la clave de Google Maps para ver el mapa") en vez de intentar cargar el mapa.

**No** incluye:

- Posiciones en tiempo real (websockets/polling) — los marcadores son estáticos, tomados del
  fixture al montar el componente.
- Marcadores de pedidos/puntos de recogida-entrega.
- Ruteo, cálculo de distancias ni clustering de marcadores.
- Click en un marcador para ver detalle del conductor (ver Fuera de alcance).

## Decisión técnica

### Por qué Google Maps y no otra librería

Es el requerimiento explícito de esta iteración del panel. Se integra con
`@googlemaps/js-api-loader`, que carga el script de Google de forma controlada (evita cargarlo dos
veces si el componente se remonta) y expone una promesa que resuelve cuando el mapa está listo para
usarse.

### Centro fijo, no la ubicación del tenant

No existe todavía ningún campo de ciudad/dirección/coordenadas a nivel tenant (fuera de alcance de
todas las specs anteriores). Se usa un centro fijo ficticio en el componente (ej. Ciudad de México,
`19.4326, -99.1332`) hasta que exista ese dato real.

### Clave de Google Maps ausente no rompe el panel

Como todavía no hay una clave real del proyecto, el componente valida
`import.meta.env.VITE_GOOGLE_MAPS_API_KEY` antes de intentar cargar el script; si falta, muestra un
mensaje en vez de una pantalla en blanco o un error de consola. Esto permite que el resto del panel
(Servicios, Conductores) funcione igual aunque el mapa todavía no esté configurado.

## Reglas de negocio

- El mapa se centra en coordenadas fijas ficticias y usa un zoom fijo (ej. 12), sin ajustar
  automáticamente el encuadre a los marcadores.
- Un marcador por conductor en `conductoresActivosFixture` (mismo fixture y mismo filtro de
  "activos" — `ONLINE`, `DISPONIBLE`, `OCUPADO` — que usa `tenant/010-drivers.md`).
- Los marcadores son estáticos: se pintan una sola vez al montar el componente, no se mueven ni se
  actualizan después.
- Sin clave de Google Maps configurada, no se intenta cargar el script — se muestra el mensaje de
  configuración pendiente.

## Datos ficticios (fixture)

Reusa `conductoresActivosFixture` de `frontend/src/fixtures/panelDespachador.ts` (mismo fixture que
`tenant/010-drivers.md`, ver esa spec para su forma completa), que incluye `latitud`/`longitud` por
conductor.

## Frontend (Vue 3)

- **Componente nuevo** `frontend/src/components/panel/MapaConductores.vue`:
  `<script setup lang="ts">`, usa `@googlemaps/js-api-loader` (`Loader`) para cargar el script con
  la API key de `import.meta.env.VITE_GOOGLE_MAPS_API_KEY`; si la variable no existe, no llama al
  loader y renderiza el mensaje de configuración pendiente; al resolver el loader, crea el mapa en
  un `<div ref>` centrado en las coordenadas fijas, y agrega un `google.maps.Marker` por conductor
  de `conductoresActivosFixture`. Envuelve todo en `UiCard` (`title="Mapa"`).
- **Dependencia nueva** en `frontend/package.json`: `@googlemaps/js-api-loader` (+
  `@types/google.maps` como dev dependency, para tipado).
- **Variable de entorno nueva**: `VITE_GOOGLE_MAPS_API_KEY`, documentada en el archivo de ejemplo de
  variables de entorno del frontend, sin valor real versionado.
- Se monta desde `PanelView.vue` en la columna central del grid de 3 columnas.

## Fuera de alcance

- Rastreo en tiempo real de posiciones.
- Marcadores de pedidos (recogida/entrega).
- Ruteo, distancias, clustering.
- Click/interacción sobre los marcadores (info window, resaltar en la lista de conductores, etc.).
- Ubicación del tenant como centro del mapa (no existe ese dato todavía).
- Conseguir/gestionar la clave real de Google Maps — queda como pendiente operativo, no de código.

## Criterios de aceptación

1. Con `VITE_GOOGLE_MAPS_API_KEY` configurada, la columna central muestra un mapa de Google centrado
   en las coordenadas fijas, con un marcador por cada conductor ficticio activo.
2. Sin `VITE_GOOGLE_MAPS_API_KEY` configurada, la columna central muestra el mensaje de
   configuración pendiente, sin errores en consola ni pantalla en blanco.
3. El componente no hace ninguna petición HTTP propia más allá de la carga del script de Google
   Maps.
4. ESLint/Prettier corren sin errores.

## Supuestos asumidos (registro completo)

1. Proveedor: Google Maps, vía `@googlemaps/js-api-loader`.
2. Centro fijo ficticio (no la ubicación real del tenant, que no existe como dato todavía).
3. Marcadores estáticos de conductores activos ficticios, reusando el mismo fixture que la lista de
   conductores (010).
4. Sin marcadores de pedidos, sin ruteo, sin cálculo de distancias.
5. Requiere `VITE_GOOGLE_MAPS_API_KEY`; si falta, se muestra un mensaje en vez de fallar
   silenciosamente o con error de consola.
6. Sin interacción (click en marcador, info window, resaltar conductor en la lista).
7. El componente vive en `frontend/src/components/panel/MapaConductores.vue`.
