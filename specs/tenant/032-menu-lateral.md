# SPEC-032 — Menú lateral y mapa de borde a borde

**Metadatos**

| Campo | Valor |
|---|---|
| ID | SPEC-032 |
| Módulo | Layout del tenant |
| Versión | 1.0 |
| Estado | Aprobada el 2026-11-10 |
| Depende de | SPEC-003 (guía de diseño), SPEC-006 (Nueva Entrega), SPEC-008 (Viajes en turno), SPEC-023 (Flotilla y mapa), SPEC-025 (TEST/LIVE), SPEC-031 (bandeja de WhatsApp) |

---

> **Spec abierta, por pasos.** Este es el primer paso de un cambio de aspecto del tenant. Los pasos
> siguientes se escriben aquí mismo, reescribiendo esta spec, no en specs nuevas.

## 1. Objetivo

Cambiar el aspecto del tenant tomando como referencia un menú lateral de solo iconos que se
despliega hacia la derecha. La barra superior desaparece y todo lo que tenía pasa a ese menú. En el
Panel, el mapa ocupa toda la ventana de borde a borde y todos los demás elementos flotan encima.

## 2. Alcance

**Incluye (paso 1):**

- Un menú lateral nuevo (`UiSidebar`) en todas las pantallas del tenant, en lugar de `UiNavbar`.
- La eliminación de la barra superior del tenant.
- El mapa del Panel a pantalla completa, y los paneles flotantes reubicados para no quedar debajo
  del menú.

**No incluye:**

- Cambiar el aspecto de los paneles flotantes (Viajes en turno, Detalle, Nueva Entrega, Flotilla,
  Saldo): en este paso solo se mueven. Su rediseño corresponde a los pasos siguientes.
- El layout de ADMIN_CENTRAL: `AdminLayout` sigue usando `UiNavbar`, que se conserva sin cambios.
- Un tratamiento específico para móvil.
- Recordar si el menú quedó abierto entre recargas.

## 3. Decisiones acordadas

| # | Decisión |
|---|---|
| D-01 | El menú tiene dos estados. **Cerrado**: un riel angosto con solo iconos. **Abierto**: se despliega hacia la derecha y muestra el icono con su nombre al lado. |
| D-02 | Arranca **cerrado** siempre, al entrar y al recargar. |
| D-03 | Al abrirse **flota por encima** del mapa y de los paneles. No empuja ni encoge nada. |
| D-04 | Es una tarjeta flotante con margen y esquinas redondeadas, en `#192a56`, de arriba abajo en el lado izquierdo. Iconos y textos en blanco o claro. El destino activo lleva un fondo más claro. |
| D-05 | Cerrado, al pasar el mouse o el foco del teclado por un icono aparece su nombre en un globo. |
| D-06 | **Sin grupos**: una sola lista de destinos, sin separadores ni títulos de sección. |
| D-07 | Iconos de línea y de un solo color (set `tabler` de Iconify). Los iconos a color actuales chocan con el fondo oscuro. |
| D-08 | La barra superior desaparece en **todas** las pantallas del tenant, no solo en el Panel. |
| D-09 | "Nueva Entrega" pasa a ser un botón **+** destacado arriba del menú, solo en el Panel y solo para el rol operativo. |
| D-10 | La campana de notificaciones se elimina, porque no hacía nada. |
| D-11 | En el Panel, el mapa ocupa toda la ventana, también por detrás del menú. |
| D-12 | Conversaciones se queda en el menú con un icono de chat **provisional**. Su forma final queda pendiente (sección 10). |

## 4. Menú lateral

### Destinos

En este orden, con las mismas reglas de visibilidad por rol que ya tenía el menú superior:

| # | Destino | Icono | Visible para |
|---|---|---|---|
| 1 | Panel | `tabler:map` | rol operativo |
| 2 | Conversaciones | `tabler:messages` (provisional) | AdminCliente y Despachador |
| 3 | Clientes | `tabler:user` | AdminCliente |
| 4 | Usuarios | `tabler:users` | AdminCliente |
| 5 | Despachadores | `tabler:headset` | AdminCliente, solo si el tenant usa despachadores |
| 6 | Conductores | `tabler:steering-wheel` | AdminCliente |

- Un Despachador ve Panel (si es operativo) y Conversaciones, igual que hoy.
- Panel se marca como activo solo en `/{slug}/panel` exacto. Sin esto, quedaría marcado también en
  `/{slug}/panel/clientes` y en cualquier otra ruta que empiece igual.
- Conversaciones conserva su contador de conversaciones esperando (spec 031, RN-55 y RN-60). Cerrado,
  es un número rojo sobre la esquina del icono. Abierto, va al final de la fila. Con 0 no se ve.

### Partes del riel, de arriba abajo

1. **Logo**: un cuadro con `tabler:bolt` en el color de acento. Abierto, muestra además el texto
   "inDriver" al lado.
2. **Botón de abrir/cerrar**, debajo del logo: `tabler:layout-sidebar-left-expand` cerrado y
   `tabler:layout-sidebar-left-collapse` abierto, con `aria-expanded` y `aria-label`.
3. **Nueva Entrega** (solo en el Panel y para el rol operativo): un botón **+** (`tabler:plus`) con
   fondo de acento, para que se distinga de los destinos. Abierto, dice "Nueva Entrega".
4. **Destinos** (tabla de arriba).
5. **Pie**, pegado al borde inferior:
   - **Configuración** (`tabler:settings`), con el mismo destino que el engranaje actual:
     `tenant-configuracion` para AdminCliente y `tenant-cambiar-password` para los demás roles.
   - **Interruptor TEST/LIVE** (spec 025). Cerrado, se ve compacto: una píldora con el texto "TEST"
     o "LIVE" del color del ambiente. Abierto, se ve completo, como hoy. Sigue siendo editable solo
     para AdminCliente.
   - **Usuario**: un círculo con sus iniciales (nombre y apellido paterno), porque el sistema no
     guarda fotos. Abierto, muestra además su nombre y correo.

### Menú del usuario

Al hacer clic en el usuario se abre un menú flotante junto al riel con:

- Nombre y correo.
- **Cambiar contraseña** (`tabler:key`), que lleva a `tenant-cambiar-password`.
- **Cerrar sesión** (`tabler:logout`), con el mismo comportamiento que el botón actual.

Se cierra con `Escape`, con un clic fuera o al elegir una opción.

### Interacción

- Abrir o cerrar con el botón de la parte superior. La transición dura 300 ms.
- Con el menú abierto, lo cierran: `Escape`, un clic fuera del menú o elegir un destino.
- Cerrado, cada icono tiene `aria-label` con su nombre y muestra el globo con el nombre al recibir
  el mouse o el foco.

### Nueva Entrega (spec 006)

El botón + conserva el comportamiento que tenía en la barra superior:

- Recibe el foco al entrar al Panel con el rol operativo.
- `ArrowRight` o `ArrowDown` con el foco en el botón abre el panel de Nueva Entrega.
- Al cerrar el panel (Cancelar o `Escape`), el foco vuelve a este botón.
- `TenantLayout` sigue exponiendo `focusNuevaEntrega()` y emitiendo `toggle-nueva-entrega`.

## 5. Medidas y capas

### Medidas

Las medidas del riel viven en variables CSS en la raíz de `TenantLayout`, para que los pasos
siguientes cambien un número y no cada componente:

| Variable | Valor | Qué es |
|---|---|---|
| `--riel-ancho` | `4rem` | Ancho del riel cerrado |
| `--riel-ancho-abierto` | `15rem` | Ancho del menú abierto |
| `--riel-margen` | `0.75rem` | Separación del riel con los bordes de la ventana |
| `--riel-ocupado` | `calc(var(--riel-ancho) + 2 * var(--riel-margen))` | Franja izquierda que ocupa el riel cerrado (`5.5rem`) |
| `--alto-banda` | `1.25rem` en TEST, `0` en LIVE | Alto de la banda amarilla de "Modo prueba" |

- El riel va en `top: calc(var(--alto-banda) + var(--riel-margen))`, `left: var(--riel-margen)` y
  `bottom: var(--riel-margen)`.
- La banda de "Modo prueba" (spec 025) sigue arriba, a todo lo ancho, por encima de todo. Lo que
  antes se medía desde el alto de la barra (`4.25rem`) ahora se mide desde `--alto-banda`.

### Capas (`z-index`)

| Capa | `z-index` |
|---|---|
| Mapa | sin `z-index` (fondo) |
| Viajes en turno, Flotilla, Saldo | `30` (sin cambio) |
| Detalle y Nueva Entrega | `35` (sin cambio) |
| Riel, cerrado y abierto | `40` (el lugar que tenía la barra) |
| Menú del usuario | `45` |
| Banda de "Modo prueba" | `50` (sin cambio) |

## 6. Panel: mapa y paneles flotantes

- El mapa ocupa `h-screen w-screen`, de borde a borde, también por detrás del riel.
- Los paneles se reubican sin cambiar su aspecto:

| Panel | Antes | Ahora |
|---|---|---|
| Viajes en turno (`ServiciosEnTurno`) | `left-0 top-[4.25rem]`, alto `100vh − 4.25rem` | `left: var(--riel-ocupado)`, `top: var(--alto-banda)`, alto `100vh − --alto-banda` |
| Detalle (`DetalleEnvioPanel`) | igual que Viajes en turno | igual que Viajes en turno |
| Nueva Entrega (`NuevaEntregaPanel`) | `left-0 top-0 h-screen`, encabezado con `pt-[4.25rem]` | `left: var(--riel-ocupado)`, `top: var(--alto-banda)`, alto `100vh − --alto-banda`; el encabezado pierde el `pt-[4.25rem]` |
| Flotilla (`ConductoresActivos`) | `right-0 top-[4.25rem]`, alto `100vh − 4.25rem` | `right-0`, `top: var(--alto-banda)`, alto `100vh − --alto-banda` |
| Saldo (`SaldoViajesTenant`) | `top-[4.75rem]` | `top: calc(var(--alto-banda) + 0.5rem)` |

- Los anchos (20 % y 45 %) no cambian.
- Detalle y Nueva Entrega se ocultan deslizándose a la izquierda. Como ahora arrancan después del
  riel, su posición oculta es `translateX(calc(-100% - var(--riel-ocupado)))`, para que no asome un
  borde entre los márgenes del riel.

## 7. Las demás pantallas del tenant

- No tienen mapa: el contenido conserva su carril centrado y su fondo actual.
- `<main>` deja el relleno superior que reservaba la barra y gana un relleno izquierdo de
  `var(--riel-ocupado)`, para no quedar debajo del riel cerrado.
- El menú abierto flota encima del contenido, igual que en el Panel.

## 8. Reglas

- **RN-01.** Ninguna pantalla del tenant muestra barra superior.
- **RN-02.** El menú arranca cerrado en cada carga.
- **RN-03.** Abrir el menú nunca mueve ni redimensiona el mapa ni los paneles.
- **RN-04.** Cada destino del menú respeta las mismas reglas de rol que tenía en la barra superior.
- **RN-05.** El botón + de Nueva Entrega solo existe en el Panel y para el rol operativo.
- **RN-06.** El cambio de ambiente (TEST/LIVE) sigue siendo exclusivo de AdminCliente.
- **RN-07.** Los colores del menú salen de tokens de `@theme`, no de valores sueltos en el componente.

## 9. Componentes

### Nuevos

- **`components/ui/UiSidebar.vue`**: el riel y el menú abierto. Recibe:
  - `items`: `Array<{ label: string; to: string; icon: string; badge?: number; exacto?: boolean }>`.
    `exacto` activa la comparación exacta de ruta (la usa Panel).
  - Slot `acciones`: el botón + de Nueva Entrega.
  - Slot `pie`: Configuración, TEST/LIVE y el usuario.
  - Expone `abierto` como estado interno. No usa un store: nadie fuera del menú necesita saberlo.

### A modificar

- **`assets/main.css`**: token `--color-sidebar: #192a56` en `@theme`, más los tonos derivados que
  necesite el menú (activo, separador, texto atenuado), también como tokens.
- **`scripts/generate-icon-data.mjs`** y **`assets/icon-data.json`**: se agrega la colección
  `tabler` con solo los iconos de esta spec: `map`, `messages`, `user`, `users`, `headset`,
  `steering-wheel`, `settings`, `plus`, `key`, `logout`, `bolt`, `layout-sidebar-left-expand` y
  `layout-sidebar-left-collapse`. Los iconos se siguen empaquetando localmente, sin pedirlos a la API
  de Iconify.
- **`package.json`**: `@iconify-json/tabler` como dependencia de desarrollo, igual que las dos
  colecciones actuales.
- **`layouts/TenantLayout.vue`**:
  - Reemplaza `UiNavbar` por `UiSidebar`.
  - Agrega `icon` (y `exacto` en Panel) a cada destino de `items`.
  - Define las variables CSS de la sección 5.
  - `<main>` cambia su relleno (sección 7). Con `anchoCompleto` no lleva relleno.
- **`components/ui/UiAmbienteSwitch.vue`**: una prop `compacto` para la píldora del riel cerrado.
- **`views/tenant/panel/PanelView.vue`**: el contenedor del mapa pasa a `h-screen w-screen`.
- **`components/panel/ServiciosEnTurno.vue`**, **`DetalleEnvioPanel.vue`**,
  **`NuevaEntregaPanel.vue`**, **`ConductoresActivos.vue`** y **`SaldoViajesTenant.vue`**: posiciones
  según la sección 6.

### Sin cambios

- **`components/ui/UiNavbar.vue`**: lo sigue usando `AdminLayout`.

## 10. Pendientes

- **Conversaciones**: icono y lugar definitivos en el menú. Por ahora es `tabler:messages` en la
  segunda posición.
- **Pasos siguientes** del cambio de aspecto, empezando por el rediseño de los paneles flotantes.

## 11. Criterios de aceptación

1. Ninguna pantalla del tenant muestra barra superior. ADMIN_CENTRAL sigue con la suya.
2. Al entrar o recargar, el menú aparece cerrado: un riel de solo iconos en `#192a56`, flotando a la
   izquierda con margen y esquinas redondeadas.
3. El botón de abrir/cerrar despliega el menú hacia la derecha con los nombres al lado de cada
   icono, sin mover el mapa ni los paneles. `Escape`, un clic fuera o elegir un destino lo cierran.
4. Cerrado, cada icono muestra su nombre en un globo al recibir el mouse o el foco del teclado.
5. Los destinos aparecen sin grupos, en el orden de la sección 4 y con sus iconos: mapa, chat,
   persona, grupo de personas, audífonos y volante.
6. Cada rol ve exactamente los destinos que veía antes: AdminCliente con y sin despachadores,
   Despachador operativo y no operativo.
7. Panel solo se marca activo en `/{slug}/panel`, no en las demás pantallas.
8. El contador de Conversaciones se ve sobre su icono cuando hay conversaciones esperando y
   desaparece con 0.
9. En el Panel, para el rol operativo, el botón + recibe el foco al entrar; `ArrowRight` o
   `ArrowDown` abre Nueva Entrega; al cerrarla, el foco vuelve al +. En otras pantallas o roles, el
   + no existe.
10. El engranaje lleva a Configuración (AdminCliente) o a Cambiar contraseña (los demás roles).
11. El usuario se ve como un círculo con sus iniciales. Al hacer clic abre un menú con nombre,
    correo, Cambiar contraseña y Cerrar sesión, y Cerrar sesión lleva al login del tenant.
12. El interruptor TEST/LIVE se ve en el pie del menú (compacto cerrado, completo abierto), y solo
    AdminCliente puede cambiarlo.
13. En TEST, la banda amarilla sigue arriba a todo lo ancho, y el riel y los paneles empiezan debajo
    de ella.
14. En el Panel, el mapa llega a los cuatro bordes de la ventana, también por detrás del riel.
15. Viajes en turno, Detalle y Nueva Entrega empiezan a la derecha del riel y ocupan todo el alto; al
    ocultarse, no asoma ningún borde entre los márgenes del riel. La Flotilla ocupa todo el alto a la
    derecha y Saldo queda centrado arriba.
16. En las demás pantallas, el contenido no queda debajo del riel cerrado.
17. La campana de notificaciones ya no existe.
18. Los iconos del menú se cargan sin pedir nada a la API de Iconify.
19. ESLint, Prettier y la revisión de tipos corren sin errores.
