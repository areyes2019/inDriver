# SPEC-033 — Globo con iniciales del conductor en el mapa

**Metadatos**

| Campo | Valor |
|---|---|
| ID | SPEC-033 |
| Módulo | Panel — mapa de conductores |
| Versión | 1.0 |
| Estado | Aprobada el 2026-11-10 |
| Depende de | SPEC-009 (capa de mapas), SPEC-023 (Flotilla y mapa), SPEC-026 (color por conductor, RN-18), SPEC-027 (Panel reactivo), SPEC-028 (deslizamiento del marcador, §19) |

---

## 1. Objetivo

En el mapa del Panel, cada conductor deja de verse como un punto de color y pasa a verse como un
**globo con punta** que lleva sus **iniciales**. El despachador reconoce a cada conductor sin tener
que pasar el mouse por encima, y sigue ligándolo con su línea por el color.

## 2. Alcance

**Incluye:**

- La forma del marcador de conductor en el mapa del Panel (`MapaConductores`).
- Una regla única para calcular iniciales, compartida por el mapa y la lista de Flotilla.
- La elección automática de letra blanca u oscura según el color del globo.
- Pruebas automáticas de la regla de iniciales.

**No incluye:**

- `panda_express`: la app ya no tiene mapa, así que RN-22 de la spec 026 no aplica aquí.
- Cambiar el aspecto del avatar de la lista de Flotilla: sigue con una letra y en morado (spec 023).
- Distinguir en el globo a un conductor Disponible de uno Ocupado (con rebote, halo o de cualquier
  otra forma).
- Decidir qué globo queda encima cuando dos se enciman, y agrupar globos cercanos.
- Girar el globo hacia donde va el conductor.
- Clic sobre el globo (sigue fuera de alcance, como en la spec 009).
- Migrar al marcador nuevo de Google (`AdvancedMarkerElement`).
- Las iniciales del usuario en el menú lateral (spec 032), que siguen con su propio cálculo.

## 3. Decisiones acordadas

| # | Decisión |
|---|---|
| D-01 | Solo cambia el mapa del Panel. |
| D-02 | Las iniciales son dos letras: la del nombre y la del apellido. |
| D-03 | Con una sola palabra, una sola letra. |
| D-04 | Las partículas del apellido ("de", "la", "del", etc.) no cuentan: "María de la Cruz" → **MC**. |
| D-05 | Se respetan acentos y Ñ: "Ángel Núñez" → **ÁN**. |
| D-06 | El globo se rellena con el color del conductor (spec 026, RN-17 y RN-18), con borde blanco. |
| D-07 | La **punta** del globo marca la posición exacta del conductor. |
| D-08 | El globo mide lo mismo en cualquier zoom, unos 32 px de alto. |
| D-09 | El globo se ve igual para Disponible y Ocupado. El Disponible se distingue porque no tiene línea (spec 026, RN-21). Se descartó el rebote. |
| D-10 | Al pasar el mouse se ve el nombre completo. El clic no hace nada. |
| D-11 | Las iniciales repetidas se aceptan: las distinguen el color y el nombre al pasar el mouse. |
| D-12 | Si dos globos se enciman, queda arriba el último que se dibujó. Sin agrupación. |
| D-13 | El globo se desliza como el punto actual (spec 028, §19) y no gira. |
| D-14 | El avatar de la lista de Flotilla no cambia de aspecto: una letra, en morado. |
| D-15 | El globo se dibuja como una imagen generada por código, sobre el marcador que ya se usa (`google.maps.Marker`). No hace falta Map ID. |
| D-16 | Una sola regla de iniciales para todo el sistema. El mapa usa las dos letras y la lista solo la primera. |
| D-17 | La letra es blanca u oscura según qué se lea mejor sobre el color del globo (sección 6). |
| D-18 | El Panel le pasa a la capa de mapas las letras y el color, y la capa dibuja el globo. El Panel no arma imágenes. |
| D-19 | La regla de iniciales tiene pruebas automáticas. Sin nombre, el globo muestra **?**. |

## 4. Regla de iniciales

### De dónde sale el nombre

`ConductorActivoResource` manda `nombre` ya armado como `"{nombre} {apellido_paterno}"`. El
apellido materno no viaja. La regla trabaja sobre ese texto, sin pedir campos nuevos al servidor.

### Cálculo

1. Quitar espacios al inicio y al final y juntar los espacios repetidos.
2. Partir en palabras.
3. Quitar las partículas: `de`, `del`, `la`, `las`, `los`, `y`, sin importar mayúsculas.
4. Si no queda ninguna palabra, el resultado es `?`.
5. Si queda una, el resultado es su primera letra.
6. Si quedan dos o más, el resultado es la primera letra de la **primera** palabra más la primera
   letra de la **última**.
7. El resultado va en mayúsculas, con los acentos que tenga.

Tomar la última palabra en vez de la segunda hace que un nombre compuesto no se confunda con el
apellido: "Juan Carlos Pérez" → **JP**, no JC.

### Ejemplos (los que cubren las pruebas)

| `nombre` | Iniciales |
|---|---|
| Juan Pérez | JP |
| Juan Carlos Pérez | JP |
| María de la Cruz | MC |
| José del Río | JR |
| Juan | J |
| `"  ana   torres  "` | AT |
| Ángel Núñez | ÁN |
| `""` o solo espacios | ? |

### Uso

- **Mapa:** el resultado completo.
- **Lista de Flotilla:** solo el primer carácter del resultado. Hoy toma la primera letra del
  nombre, que es lo mismo, así que la lista no cambia a la vista. La diferencia es que ya no tiene
  su propia fórmula.

## 5. Globo

- Forma de gota: un círculo arriba y una punta abajo. Mide unos 32 px de alto y unos 26 px de
  ancho, siempre igual en cualquier zoom.
- Relleno: el color del conductor. Borde blanco de 2 px, igual que el punto actual.
- Iniciales centradas en el círculo, en negritas, con la familia tipográfica del sitio y en el
  color que diga la sección 6.
- **Anclaje en la punta:** la posición del conductor es la punta, no el centro del círculo. Al
  mover el marcador se mueve la punta.
- `title` con el nombre completo, como hoy.
- Sin `zIndex` propio: rige el orden de dibujado (D-12).
- El deslizamiento entre posiciones (spec 028, §19) y la pausa con `prefers-reduced-motion` o con la
  pestaña oculta siguen igual: solo cambia la figura, no cómo se mueve.
- Sin color (algún uso futuro del marcador que no lo mande), se conserva el pin de Google por
  defecto, como hoy.

## 6. Color de la letra

- Se elige entre blanco (`#FFFFFF`) y casi negro (`#111827`) con el contraste de WCAG.
- **Va en blanco mientras su contraste con el relleno sea de al menos 3.5:1.** Si baja de ahí, va en
  casi negro.
- Con la paleta de la spec 026:

| Color | Contraste del blanco | Letra |
|---|---|---|
| `#2563EB` | 5.17 | blanca |
| `#DC2626` | 4.83 | blanca |
| `#059669` | 3.77 | blanca |
| `#D97706` | 3.19 | **oscura** |
| `#7C3AED` | 5.70 | blanca |
| `#DB2777` | 4.60 | blanca |
| `#0891B2` | 3.68 | blanca |
| `#65A30D` | 3.09 | **oscura** |
| `#EA580C` | 3.56 | blanca |
| `#4F46E5` | 6.29 | blanca |

- La regla se calcula con el color, no con una lista fija: si la paleta cambia, se sigue ajustando
  sola.

## 7. Reglas

- **RN-01.** Cada conductor con posición se ve en el mapa del Panel como un globo con punta, del
  color que le da la spec 026 (RN-17), con sus iniciales.
- **RN-02.** La punta del globo es la posición del conductor.
- **RN-03.** Las iniciales salen siempre de la regla de la sección 4. Ninguna pantalla calcula
  iniciales de conductor por su cuenta.
- **RN-04.** El globo no cambia con el estado del conductor (Disponible u Ocupado).
- **RN-05.** El color de la letra sigue la sección 6.
- **RN-06.** El Panel nunca construye la imagen del globo: le pasa iniciales y color a `MapService`.

Esta spec **reemplaza la forma** descrita en RN-18 de la spec 026: el marcador sigue usando el color
del conductor, pero ya no es un punto, sino el globo de la sección 5.

## 8. Componentes

### Nuevos

- **`src/lib/iniciales.ts`**: `iniciales(nombre: string): string`, la regla de la sección 4.
- **`src/lib/__tests__/iniciales.test.ts`**: los casos de la tabla de la sección 4, con
  `node:test`, como `tarifa.test.ts`.

### A modificar

- **`src/services/maps/types.ts`**: `MarkerOptions` gana `iniciales?: string`. Con `color` e
  `iniciales`, el marcador es un globo; con solo `color`, sigue siendo el punto actual; sin color,
  el pin de Google.
- **`src/services/maps/GoogleProvider.ts`**:
  - `puntoDeColor` deja de ser el único icono con color. Se agrega un generador del globo:
    arma un SVG con la gota, el borde y las iniciales, y lo entrega como `google.maps.Icon` con
    `url` en `data:image/svg+xml`, `scaledSize` del tamaño de la sección 5 y `anchor` en la punta.
    Las iniciales se escapan antes de meterlas al SVG.
  - La elección del color de letra (sección 6) vive aquí, junto al generador.
  - `addMarker` usa el globo cuando recibe `color` e `iniciales`.
- **`src/services/maps/MapService.ts`** y **`BaseProvider.ts`**: solo si hace falta que pasen la
  opción nueva; la firma de `addMarker` no cambia.
- **`src/components/panel/MapaConductores.vue`**: pasa `iniciales: iniciales(conductor.nombre)` en
  `addMarker`.
- **`src/components/panel/ConductoresActivos.vue`**: su `inicial(nombre)` usa `iniciales(nombre)` y
  toma el primer carácter.
- **`specs/tenant/026-cola-y-polilinea-seguimiento.md`**: nota en RN-18 que remite a esta spec.

### Sin cambios

- El backend: `nombre` y `color` ya vienen en `ConductorActivoResource`.
- `panda_express`.

## 9. Pendientes

- Migrar al marcador nuevo de Google (`AdvancedMarkerElement`) cuando el marcador actual tenga
  fecha de retiro. Exige crear un Map ID en la cuenta de Google.
- Unificar con esta regla las iniciales del usuario en el menú lateral (spec 032), que hoy se
  calculan aparte en `TenantLayout`.

## 10. Criterios de aceptación

1. En el Panel, cada conductor con posición se ve como un globo con punta del color de su línea,
   con sus iniciales dentro. Ya no hay puntos de color.
2. "Juan Carlos Pérez" se ve como **JP**, "María de la Cruz" como **MC** y "Juan" como **J**.
3. Al acercar o alejar el zoom, la punta sigue exactamente sobre la posición del conductor y el
   globo no cambia de tamaño.
4. Los globos ámbar (`#D97706`) y lima (`#65A30D`) llevan letra oscura; los demás, blanca.
5. Al pasar el mouse por un globo se ve el nombre completo del conductor.
6. Cuando el conductor se mueve, el globo se desliza hasta la nueva posición como antes; con
   "reducir movimiento" activado, brinca sin animación.
7. Un conductor Disponible y uno Ocupado tienen el mismo globo; solo el Ocupado tiene línea.
8. Con dos conductores del mismo color de línea, cada globo conserva su color y sus iniciales.
9. La lista de Flotilla se ve igual que antes: avatar morado con una letra.
10. Un conductor que se conecta con el Panel abierto aparece con su globo sin recargar (spec 027).
11. `npm run test:unit` pasa, incluidos los casos de iniciales.
12. ESLint, Prettier y la revisión de tipos corren sin errores.
