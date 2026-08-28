## SPEC Base de Datos inDriver parte 1 - Base Central

Este SPEC define la tablas, columnas y relaciones de la base de datos del sistema delivery

Actores principales: 
ADMIN CENTRAL (Dueño del sistema)
│
├── Tenant / Cliente (Empresa o negocio)
│
│   └── ADMIN CLIENTE (Duesño del negocio)
│       │
│       ├── DESPACHADORES (Administradores de la flotilla)
│       │   └── Gestionan / asignan pedidos
│       │
│       └── CONDUCTORES (Ejectuan la orden de envio)
│           ├── Conductor 01
│           ├── Conductor 02
│           ├── Conductor 03
│           └── ...

# Arquitecura general
                       ┌───────────────────┐
                       │   ADMIN CENTRAL   │
                       └─────────┬─────────┘
                                 │
                        ┌────────▼─────────┐
                        │ delivery_central │
                        │                  │
                        │ tenants          │
                        │ admins           │
                        │ paquetes_viajes  │
                        └────────┬─────────┘
                                 │
                                 ▼
                           TENANT 01
                                 │
                            ┌────▼────┐
                            │  DB_T01 │
                            └────┬────┘
                                 │
                 ┌───────────────┼───────────────┐
                 │               │               │
                 ▼               ▼               ▼
            USUARIOS        DESPACHADORES    CONDUCTORES
                                                 │
                                      ┌──────────┼──────────┐
                                      │          │          │
                                      ▼          ▼          ▼
                                  VEHÍCULOS     GPS       ESTADO
                                      │
                                      │
                                      ▼
                                   PEDIDOS
                                      │
                    ┌─────────────────┼─────────────────┐
                    │                 │                 │
                    ▼                 ▼                 ▼
              ASIGNACIONES        ESTADOS             PAGOS
                    │
                    ▼
                 ENTREGA
                    │
              ┌─────┴─────┐
              ▼           ▼
         EVIDENCIAS    AUDITORÍA
    


## TABLA TENANTS - Empresas que utilizan el sistema

id_tenant
nombre_comercial
razon_social
rfc
telefono
email
calle
numero_int
numero_ext
colonia
cp
ciudad
estado_direccion
pais
estado
modo_estado
fecha_inicio
fecha_vencimiento
database_nombre
database_host
database_puerto
database_usuario
database_password
created_at
updated_at

* Estados (`estado`)
- Activo 
- Suspendido
- Inactivo

* Modo de control del estado (`modo_estado`)
- AUTOMATICO
- MANUAL

**Derogado** (spec `009-paquetes-viajes.md`): ya no existe el modelo de planes/suscripciones, así que el `estado` del tenant ya no se sincroniza con ninguna vigencia automática. Por ahora `modo_estado` sigue existiendo pero solo se mueve a MANUAL cuando un ADMIN_CENTRAL cambia el `estado` a mano (ver `008-listado-tenants.md`); qué otra cosa (si acaso) pondría el `estado` en AUTOMATICO, o cómo se refleja quedarse sin viajes disponibles en los paquetes comprados, queda pendiente para una historia futura.

## TABLA ADMIN_CENTRALES - Usuarios que administran todo el sistema

id_admin
nombre
apellido_paterno
apellido_materno
email
password
estado
ultimo_acceso
created_at
updated_at

## Tabla paquetes_viajes - Catálogo de paquetes de viajes que un tenant puede comprar

id_paquete
codigo_paquete
nombre
descripcion
cantidad_viajes
precio
estado
created_at
updated_at
deleted_at

* Estados
- Activo
- Inactivo

`codigo_paquete` es un identificador libre y único (no es llave foránea real): la tabla
`compras_paquetes`, que vive en la base de cada tenant, lo referencia por texto para registrar qué
paquete compró, ya que no es posible una llave foránea entre bases de datos distintas.

"Eliminar" un paquete es un borrado lógico (`deleted_at`), no físico: como no hay forma de
verificar desde `delivery_central` si algún tenant ya lo compró (esa información vive en la base
de cada tenant), se prefiere ocultarlo de los listados y de futuras compras en vez de borrar la
fila, para no dejar compras históricas apuntando a un `codigo_paquete` sin ningún registro que lo
explique.

## Tabla configuraciones_globales - Configuraciones generales del sistema.
id_configuracion
clave
valor
created_at
updated_at

* Ejemplo:

nombre_sistema
correo_soporte
telefono_soporte
url_api

## Tabla logs_centrales - Registro de actividades realizadas desde el sistema central.

id_log
id_tenant
id_admin
tipo
accion
descripcion
created_at

# Reglas de negocio — Base Central

- Cada tenant activo tiene su propia base de datos física separada (DB_T01, DB_T02...), aprovisionada al darse de alta el tenant — no es un esquema compartido con columna `tenant_id`.
- El `estado` de TENANTS pasa a MANUAL cuando un ADMIN_CENTRAL lo fija a mano (ver `modo_estado` arriba); no hay ningún proceso automático que lo mueva por ahora (ver nota "Derogado" arriba).
- `paquetes_viajes` es el catálogo (definido por ADMIN_CENTRAL) de paquetes que un tenant puede comprar; no reemplaza ningún límite ni vigencia — cómo un tenant consume esos viajes, o qué pasa cuando se le acaban, queda pendiente para una historia futura.
- `logs_centrales` registra solo acciones administrativas de alto nivel hechas por ADMIN_CENTRAL (altas/bajas de tenants, altas/ediciones de paquetes de viajes, suspensiones, cambios de `modo_estado`) — no la actividad operativa de despachadores/conductores dentro de cada tenant, que queda fuera del alcance de esta parte 1.
- Todos los ADMIN_CENTRAL tienen el mismo nivel de acceso a todos los tenants; no hay cartera asignada por admin ni niveles de permiso entre ellos.
- `configuraciones_globales` aplica igual para todos los tenants; un tenant no puede sobreescribir un valor global con uno propio.
- ADMIN_CLIENTE (dueño del negocio/tenant) se define y almacena dentro de la base de datos propia del tenant (DB_T0X), no en `delivery_central` — por eso no aparece como tabla en este documento de la Base Central.

# Relaciones — Base Central
admins_centrales
        │
        └── administra → tenants

admins_centrales
        │
        └── administra → paquetes_viajes

tenants
        │
        └── tiene → logs_centrales