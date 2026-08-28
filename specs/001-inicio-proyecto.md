# Spec: Inicio de proyecto — Laravel API + Vue 3 SPA

## Historia de usuario

Como desarrollador, quiero iniciar un proyecto con Laravel (última versión) como backend y
Vue 3 como frontend, para una app de delivery tipo Uber con GPS tracking (cliente que pide el
servicio y repartidor que lo presta), dejando el backend configurado como API REST para que en
el futuro pueda construirse una PWA + Capacitor (app móvil), sin incluir todavía el módulo de
autenticación (login/registro) ni ninguna lógica de tracking/mapas.

## Objetivo / Alcance

Dejar el scaffolding base de un proyecto desacoplado (Laravel API + Vue 3 SPA) funcionando en
local, con las convenciones y librerías de soporte instaladas, pero **sin** lógica de negocio de
delivery/tracking ni pantallas de login/registro. Sanctum queda instalado y configurado a nivel
de infraestructura para no requerir refactor cuando se agregue auth más adelante.

## Arquitectura

- Backend y frontend desacoplados: Laravel expone únicamente una API REST (sin Blade ni Inertia).
- Vue 3 es una SPA independiente que consume la API vía HTTP/JSON.
- Un solo proyecto frontend (no dos apps separadas): cliente y repartidor comparten el mismo
  `frontend/`, diferenciados por rutas/rol. Si más adelante las necesidades de cada rol lo
  justifican, se puede partir en dos proyectos separados sin afectar el backend.
- Repositorio único con dos carpetas raíz:
  - `backend/` → proyecto Laravel
  - `frontend/` → proyecto Vue 3
- Repositorio remoto: `git@github.com:areyes2019/inDriver.git`. Commits directos a `main`, sin
  ramas de feature ni pull requests (desarrollador único).

## Backend (Laravel)

- Versión: siempre la **última estable disponible** en el momento de instalar/actualizar el
  proyecto, sin fijar un número de versión mayor específico (evita quedar atado a una versión
  vieja o generar conflictos si se reinstala más adelante). Instalada vía `laravel new`.
- Base de datos: MySQL (entorno Laragon).
- Testing: Pest.
- Estilo de código: Laravel Pint.
- Rutas API versionadas bajo `/api/v1/...` (`routes/api.php`).
- Respuestas mediante Laravel API Resources (JSON estándar), sin JSON:API ni GraphQL.
- **Sanctum**:
  - Paquete `laravel/sanctum` instalado y publicado (config + migración de `personal_access_tokens`).
  - `stateful` domains configurados (`SANCTUM_STATEFUL_DOMAINS` en `.env`, incluyendo el host del
    dev server de Vite).
  - Middleware `EnsureFrontendRequestsAreStateful` habilitado en el grupo `api`.
  - **No** se crean rutas, controladores ni vistas de login/registro/logout en esta historia.
- CORS (`config/cors.php`) configurado en línea con los dominios stateful de Sanctum (no abierto
  con `*`).
- `.env.example` incluye una variable reservada y vacía para la futura llave del proveedor de
  mapas (`GOOGLE_MAPS_API_KEY=`), sin instalar ningún SDK de mapas todavía — solo evita tocar la
  configuración base cuando llegue esa historia.

## Frontend (Vue 3)

- Vue 3 con Composition API y `<script setup>`.
- TypeScript.
- Build tool: Vite.
- Gestor de paquetes: npm.
- Vue Router instalado (estructura de rutas base, sin guards de auth todavía).
- Pinia instalado como store por defecto (sin store de auth todavía).
- ESLint + Prettier configurados.
- Cliente HTTP (ej. `axios` o `fetch` wrapper) apuntando a `/api/v1` del backend, preparado para
  enviar credenciales (cookies) por el esquema stateful de Sanctum cuando se active auth.

## Preparación futura (fuera de alcance de esta historia)

- Configuración de manifest y service worker para PWA.
- Integración de Capacitor para empaquetar como app móvil.
- Implementación de login/registro/logout y protección de rutas.
- Integración de SDK de mapas/GPS (Google Maps, Mapbox, etc.) y tracking en vivo.
- WebSockets / real-time (Laravel Reverb, Pusher, etc.) para actualizar ubicación en vivo.

Estos puntos no se implementan ahora, pero la arquitectura (API REST desacoplada + Sanctum
preinstalado + variable de entorno reservada para mapas) queda lista para que se agreguen sin
refactor mayor.

## Fuera de alcance

- Cualquier módulo de autenticación funcional (login, registro, recuperación de contraseña).
- Lógica de negocio de delivery/tracking (pedidos, asignación de repartidor, GPS en vivo, mapas).
- Despliegue / infraestructura de producción.
- Configuración de Docker/Sail (se usa Laragon en local).

## Criterios de aceptación

1. `backend/` levanta con `php artisan serve` (o Laragon) y responde en `/api/v1/...`.
2. `frontend/` levanta con `npm run dev` (Vite) y puede hacer una petición de prueba exitosa
   contra el backend sin errores de CORS.
3. `laravel/sanctum` está instalado, migrado y con `stateful` domains apuntando al dev server de
   Vue, pero no existe ninguna ruta de auth expuesta.
4. Pint y ESLint/Prettier corren sin errores sobre el código scaffolding generado.
5. No existen tablas, controladores ni vistas relacionadas a usuarios/login más allá de lo que
   Sanctum requiere para su propio funcionamiento interno.
6. No existe ningún SDK de mapas ni lógica de tracking instalada; solo la variable reservada en
   `.env.example`.
7. El repositorio tiene como remoto `git@github.com:areyes2019/inDriver.git` y el historial parte
   de commits directos a `main`.

## Supuestos asumidos (registro completo)

1. Arquitectura desacoplada: Laravel API + Vue 3 SPA (sin Inertia/Blade).
2. Monorepo con carpetas `backend/` y `frontend/`.
3. Laravel: siempre la última versión estable disponible al momento de instalar/actualizar, sin
   fijar número de versión mayor.
4. Vue 3 con Composition API + `<script setup>`.
5. TypeScript en el frontend.
6. Vite como build tool.
7. npm como gestor de paquetes.
8. MySQL como base de datos.
9. Rutas API versionadas (`/api/v1`).
10. Respuestas con Laravel API Resources.
11. Sanctum instalado y configurado (stateful domains) desde el inicio, sin implementar
    login/auth funcional todavía.
12. Pest como framework de testing backend.
13. Laravel Pint + ESLint/Prettier como linters/formatters.
14. Vue Router instalado sin guards de auth.
15. Pinia instalado como store por defecto.
16. PWA (manifest/service worker) no se configura aún; solo se deja la arquitectura lista.
17. Capacitor no se instala aún; la separación API REST evita refactor mayor futuro.
18. Entorno de desarrollo: Laragon, sin Docker/Sail.
19. Alcance limitado a scaffolding inicial, sin features de negocio de delivery/tracking.
20. Un solo proyecto frontend (no separado por rol cliente/repartidor) en esta etapa.
21. No se instala ningún SDK de mapas/GPS ni WebSockets/real-time en esta historia; solo se deja
    una variable de entorno reservada para la futura llave de mapas.
22. Repositorio remoto en GitHub (`areyes2019/inDriver`), con commits directos a `main`.
