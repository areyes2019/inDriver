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
                        │ planes            │
                        │ suscripciones    │
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

El `estado` del tenant normalmente lo decide el sistema solo, revisando si su suscripción está vigente o vencida (`modo_estado` = AUTOMATICO). Cuando un ADMIN_CENTRAL cambia el `estado` a mano, `modo_estado` pasa a MANUAL: desde ese momento el sistema deja de tocar el `estado` de ese tenant, aunque su suscripción cambie, hasta que un ADMIN_CENTRAL regrese `modo_estado` a AUTOMATICO explícitamente.

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

## TABLA PLANES - Permite definir planes comerciales

id_plan
nombre
descripcion
precio
limite_despachadores
limite_conductores
limite_pedidos
estado
created_at
updated_at

## Tabla suscripciones - Relaciona un Tenant con su plan.

id_suscripcion
id_tenant
id_plan
fecha_inicio
fecha_vencimiento
estado
created_at
updated_at

* Estados
- ACTIVA
- VENCIDA
- SUSPENDIDA
- CANCELADA

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
- El `estado` de TENANTS es AUTOMATICO por defecto y se sincroniza con el estado de la suscripción; puede pasar a MANUAL cuando un ADMIN_CENTRAL lo fija a mano (ver `modo_estado` arriba).
- Mientras la suscripción de un tenant está VENCIDA o SUSPENDIDA (y `modo_estado` = AUTOMATICO), el sistema bloquea el acceso al tenant (admin_cliente, despachadores, conductores) — no hay periodo de gracia.
- Los límites del plan (`limite_despachadores`, `limite_conductores`, `limite_pedidos`) son topes duros: el sistema impide crear un nuevo registro si el tenant ya alcanzó su límite.
- `limite_pedidos` se cuenta por mes (ciclo de facturación de la suscripción), no como total histórico acumulado ni como pedidos concurrentes abiertos.
- Un tenant solo puede tener una suscripción con estado ACTIVA a la vez; cambiar de plan implica cancelar/reemplazar la suscripción vigente, no coexistir varias activas.
- `logs_centrales` registra solo acciones administrativas de alto nivel hechas por ADMIN_CENTRAL (altas/bajas de tenants, cambios de plan, suspensiones, cambios de `modo_estado`) — no la actividad operativa de despachadores/conductores dentro de cada tenant, que queda fuera del alcance de esta parte 1.
- Todos los ADMIN_CENTRAL tienen el mismo nivel de acceso a todos los tenants; no hay cartera asignada por admin ni niveles de permiso entre ellos.
- `configuraciones_globales` aplica igual para todos los tenants; un tenant no puede sobreescribir un valor global con uno propio.
- ADMIN_CLIENTE (dueño del negocio/tenant) se define y almacena dentro de la base de datos propia del tenant (DB_T0X), no en `delivery_central` — por eso no aparece como tabla en este documento de la Base Central.

# Relaciones — Base Central
admins_centrales
        │
        └── administra → tenants

tenants
        │
        └── tiene → suscripciones

planes
        │
        └── tiene → suscripciones

tenants
        │
        └── tiene → logs_centrales