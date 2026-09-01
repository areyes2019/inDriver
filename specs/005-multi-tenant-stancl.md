# Spec: Arquitectura multi-tenant con stancl/tenancy

## Historia de usuario

Como desarrollador, quiero implementar en Laravel el esquema de dos bases de datos definido en
`db/01-base-de-datos.md` (central) y `db/02-base-de-datos.md` (tenant), para tener el
aprovisionamiento de tenants (cada uno con su propia base de datos) funcionando en local.

## Decisión técnica

Se usa el paquete `stancl/tenancy` (v3, `illuminate/support ^13.0` compatible) en modo
**multi-database**: cada tenant tiene su propia base de datos física, aprovisionada
automáticamente al crear el registro en `tenants`.

## Ajustes necesarios sobre el paquete por defecto

- **Clave primaria del tenant**: el paquete usa por defecto una columna `id` (UUID) como llave
  técnica. Para no romper la spec 01 (que define `id_tenant` como PK autoincremental,
  referenciada por `suscripciones`, `logs_centrales` y `compras_paquetes`), se sobreescribe
  `App\Models\Tenant` para que su llave primaria sea `id_tenant` (autoincremental, tipo `int`),
  desactivando el generador de UUID (`tenancy.id_generator = null`).
- **Nombre de la base de datos por tenant**: `prefix = 'delivery_tenant_'`, `suffix = ''`. Con
  IDs autoincrementales esto da `delivery_tenant_1`, `delivery_tenant_2`, etc. — **no** lleva
  cero a la izquierda (`delivery_tenant_01` en la spec 02 era solo el ejemplo ilustrativo del
  primer tenant, no un requisito de formato).
- **Base central**: la conexión por defecto (`DB_CONNECTION=mysql` en `.env`) apunta a la base
  `delivery_central`. `tenancy.database.central_connection` usa esa misma conexión.
- **Columna `data` (json)**: se conserva en `tenants` junto a las columnas propias de la spec 01.
  Es un mecanismo interno del paquete (`HasDataColumn`) para guardar atributos sueltos sin
  necesidad de migración; no se usa activamente todavía.
- **Tabla `domains`**: se elimina. Viene por defecto con el paquete para identificar tenants por
  dominio/subdominio, pero **la identificación de tenant (por dominio, header, o vía el usuario
  autenticado) queda fuera de alcance de esta historia** — igual que la spec 001 dejó fuera el
  login. Se retoma cuando exista una spec de autenticación/API que decida cómo una petición HTTP
  sabe a qué tenant pertenece.

## Estructura de migraciones

- `database/migrations/` (conexión central, `delivery_central`): `tenants`, `admins_centrales`,
  `planes`, `suscripciones`, `configuraciones_globales`, `logs_centrales` — tablas de
  `db/01-base-de-datos.md`.
- `database/migrations/tenant/` (conexión dinámica por tenant): las 19 tablas de
  `db/02-base-de-datos.md` (`usuarios`, `despachadores`, `conductores`, `vehiculos`,
  `conductor_vehiculo`, `clientes`, `direcciones_clientes`, `pedidos`, `pedido_asignaciones`,
  `pedido_estados`, `pedido_cambios`, `pagos`, `conductor_posiciones`, `conductor_estado`,
  `compras_paquetes`, `notificaciones`, `configuraciones_tenant`, `zonas_servicio`, `auditoria`).

## Fuera de alcance

- Identificación del tenant en una petición HTTP (dominio, subdominio, header, o resuelto desde
  el usuario autenticado) — depende de la spec de autenticación, todavía no existe.
- Modelos Eloquent, controladores, rutas o lógica de negocio sobre estas tablas — esta historia
  solo deja las bases de datos y su esquema funcionando.
- Colas (`shouldBeQueued`) para los jobs de creación/migración de base de datos de tenant — se
  ejecutan de forma síncrona por ahora, igual que trae el paquete por defecto.

## Criterios de aceptación

1. Existe la base `delivery_central` con las 6 tablas de la spec 01, incluyendo `modo_estado` en
   `tenants`.
2. Crear un registro en `tenants` aprovisiona automáticamente su propia base de datos
   (`delivery_tenant_<id_tenant>`) y le aplica las 19 migraciones de la spec 02.
3. `compras_paquetes` no tiene columna `id_tenant`; usa `codigo_paquete` como identificador libre.
4. `php artisan migrate` (sin `fresh`) corre sin errores sobre `delivery_central`.
5. No se toca ni se borra la base `indriver` (la que usaba el scaffolding de la spec 001) — se
   reemplaza por `delivery_central` en `.env`, dejando la anterior intacta en el servidor MySQL.

## Supuestos asumidos (registro completo)

1. Multi-database real (una base física por tenant), no multi-schema ni base compartida.
2. `id_tenant` autoincremental en vez de UUID, para respetar la spec 01 tal cual fue aprobada.
3. Nombre de base por tenant sin cero a la izquierda (`delivery_tenant_1`, no
   `delivery_tenant_01`).
4. Sin tabla `domains`: la identificación de tenant se decide en una spec futura.
5. Jobs de aprovisionamiento (crear base + migrar) síncronos, no encolados.
