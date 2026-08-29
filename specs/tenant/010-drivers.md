# Spec: Conductores activos (columna derecha del Panel de Despachador)

## Historia de usuario

Como Despachador, quiero ver qué conductores están activos (en línea, disponibles u ocupados) al
entrar a mi Panel, para tener una idea rápida de la capacidad operativa disponible.

## Objetivo / Alcance

Primera versión del componente `ConductoresActivos.vue`, columna derecha del layout de 3 columnas
descrito en la ampliación de `tenant/007-panel-despachador.md`. Usa datos ficticios (fixture
compartido con `tenant/009-mapa.md`), sin conectarse todavía a ningún endpoint real de estado en
vivo de conductores (tabla `conductor_estado`, inciso 14 de `db/02-base-de-datos.md`, sin CRUD
propio todavía).

Deja funcionando:

- `UiCard` con título "Conductores activos" en la columna derecha del panel.
- Lista de conductores ficticios tomados de `conductoresActivosFixture`, filtrados a los estados
  `ONLINE`, `DISPONIBLE` y `OCUPADO` (se excluyen `OFFLINE`, `DESCANSO`, `FUERA_DE_SERVICIO`).
- Cada ítem muestra: nombre del conductor, badge de estado, y placa del vehículo asignado — con
  `UiPersonListItem` (mismo componente que ya existe en `components/ui/`).
- Estado vacío ("No hay conductores activos") si el fixture filtrado queda vacío.

**No** incluye:

- Conexión a un endpoint real de estado de conductores (no existe todavía ningún CRUD para
  `conductor_estado`).
- Click, filtros, búsqueda ni acciones sobre los ítems.
- Paginación o scroll infinito.

## Decisión técnica

### Por qué "activos" excluye descanso y fuera de servicio

`conductor_estado.estado` (inciso 14 de `db/02-base-de-datos.md`) define el enum `ONLINE, OFFLINE,
DISPONIBLE, OCUPADO, DESCANSO, FUERA_DE_SERVICIO`. Para esta primera versión, "activo" se interpreta
como "puede recibir viajes o está en uno ahora mismo" — `ONLINE`, `DISPONIBLE` y `OCUPADO` —
dejando fuera los estados donde el conductor no está operando (`OFFLINE`, `DESCANSO`,
`FUERA_DE_SERVICIO`). Es un criterio definido en esta spec, no viene de una regla de negocio
documentada previamente (no existía el concepto de "activos" antes de este panel).

### Reuso de `UiPersonListItem`

El componente ya existente `components/ui/UiPersonListItem.vue` (iniciales, nombre, badge de
estado, meta) encaja igual con la forma de un conductor ficticio (`nombre` → `name`, `estado` →
`status`/`statusColor`, `vehiculo_placa` → `meta`), así que se reusa en vez de crear un ítem de
lista nuevo desde cero.

## Reglas de negocio

- El fixture `conductoresActivosFixture` trae entre 6 y 8 conductores ficticios, con una mezcla de
  los 6 estados de `conductor_estado` (para poder probar el filtro).
- Solo se listan los que tienen `estado` en `ONLINE`, `DISPONIBLE` u `OCUPADO`.
- Colores de badge por estado: `ONLINE: green`, `DISPONIBLE: blue`, `OCUPADO: orange` (los otros
  tres no se muestran en esta lista, pero quedan documentados por si una futura ampliación los
  necesita: `DESCANSO: gray`, `OFFLINE: gray`, `FUERA_DE_SERVICIO: red`).
- Iniciales del avatar: primera letra del nombre + primera letra del apellido (criterio simple, sin
  normalización especial de acentos).

## Datos ficticios (fixture)

`frontend/src/fixtures/panelDespachador.ts` exporta (compartido con `tenant/009-mapa.md`):

```ts
export interface ConductorActivoFicticio {
  id: number
  nombre: string
  estado: 'ONLINE' | 'OFFLINE' | 'DISPONIBLE' | 'OCUPADO' | 'DESCANSO' | 'FUERA_DE_SERVICIO'
  vehiculo_placa: string
  latitud: number
  longitud: number
}

export const conductoresActivosFixture: ConductorActivoFicticio[]
```

## Frontend (Vue 3)

- **Componente nuevo** `frontend/src/components/panel/ConductoresActivos.vue`:
  `<script setup lang="ts">`, importa `conductoresActivosFixture`, filtra con un `computed` a los
  tres estados "activos", y renderiza un `UiPersonListItem` por conductor (iniciales calculadas del
  nombre, `status` = estado legible, `statusColor` según el mapa de colores de esta spec, `meta` =
  placa del vehículo). Envuelve el contenido en `UiCard` (`title="Conductores activos"`); estado
  vacío con mensaje si el filtrado queda vacío.
- Se monta desde `PanelView.vue` en la columna derecha del grid de 3 columnas.

## Fuera de alcance

- Conexión a un endpoint real de `conductor_estado`.
- Click/detalle sobre un conductor.
- Paginación / scroll infinito.
- Resaltar el conductor seleccionado en el mapa (sin interacción entre columnas en esta versión).

## Criterios de aceptación

1. La columna derecha del panel muestra una `UiCard` con título "Conductores activos".
2. Solo aparecen los conductores ficticios con estado `ONLINE`, `DISPONIBLE` u `OCUPADO`.
3. Cada ítem muestra nombre, badge de estado (color correspondiente) y placa del vehículo.
4. Si el filtrado queda vacío, se muestra el mensaje "No hay conductores activos".
5. El componente no realiza ninguna petición HTTP.
6. ESLint/Prettier corren sin errores.

## Supuestos asumidos (registro completo)

1. "Conductores activos" = estado ficticio `ONLINE`, `DISPONIBLE` u `OCUPADO`; excluye `OFFLINE`,
   `DESCANSO`, `FUERA_DE_SERVICIO`.
2. Fixture compartido con `tenant/009-mapa.md` (`conductoresActivosFixture`, en
   `frontend/src/fixtures/panelDespachador.ts`), con 6-8 conductores ficticios cubriendo los 6
   estados.
3. Campos mostrados por ítem: nombre, badge de estado, placa del vehículo asignado — vía
   `UiPersonListItem` reusado de `components/ui/`.
4. Estado vacío con mensaje si no hay conductores activos.
5. Lista de solo lectura, sin click ni acciones, sin interacción con el mapa.
6. Sin paginación ni scroll infinito.
7. El componente vive en `frontend/src/components/panel/ConductoresActivos.vue`.
