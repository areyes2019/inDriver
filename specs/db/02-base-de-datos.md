## SPEC Base de Datos inDriver parte 2 - Base del Tenant

Este SPEC define la tablas, columnas y relaciones de la base de datos del sistema delivery

## Base de datos: delivery_tenant_01

Esta es la base que vamos a utilizar inicialmente.

Todo lo que ocurre dentro de la empresa estará aquí.

## 01 Tabla usuarios - Todos los usuarios que trabajan dentro del Tenant.

id_usuario
nombre
apellido_paterno
apellido_materno
telefono
email
password
rol
estado
ultimo_acceso
created_at
updated_at

* Roles 
- AdminCliente
- Despachador
- Conductor

# 02 Tabla despachadores - Perfil operativo del despachador.

id_despachador
id_usuario
estado
created_at
updated_at

* Relación
usuarios
   │
   └── despachador

## 03 Tabla conductores - Perfil operativo del conductor.

id_conductor
id_usuario
numero_licencia
fecha_vencimiento_licencia
estado
disponibilidad
created_at
updated_at

* Estado
- ACTIVO
- INACTIVO
- BLOQUEADO

* Disponibilidad
- DISPONIBLE
- OCUPADO
- DESCANSO
- FUERA_DE_SERVICIO

## 04 Tabla vehiculos - Vehículo propio de cada conductor (relación 1 a 1, no de flotilla).

id_vehiculo
id_conductor
placa
marca
created_at
updated_at

El tenant no es dueño de una flotilla que asigna vehículos: cada conductor llega con su propio
vehículo. `id_conductor` es única — un vehículo nunca pertenece a más de un conductor, y un conductor
nunca tiene más de un vehículo a la vez. Si el conductor cambia de vehículo, se sobreescriben estos
mismos campos; no se conserva el vehículo anterior (ver `tenant/004-vehiculo-del-conductor.md`).

## 05 Tabla conductor_vehiculo — ELIMINADA

Existió como historial de asignaciones conductor↔vehículo bajo un modelo donde el tenant era dueño de
una flotilla y asignaba (y reasignaba) vehículos entre conductores. Ese modelo no aplica: cada
conductor llega con su propio vehículo, en relación 1 a 1 y sin historial de cambios. La relación vive
ahora directamente en `vehiculos.id_conductor` (inciso 04). Ver
`tenant/004-vehiculo-del-conductor.md`.


## 06 Tabla clientes - Clientes finales que solicitan servicios.

id_cliente
nombre
telefono
email
referencia
estado
created_at
updated_at

## 07 Tabla direcciones_clientes - Un cliente puede tener múltiples direcciones.

id_direccion
id_cliente
alias
calle
numero
colonia
cp
ciudad
estado
referencia
latitud
longitud
instrucciones_entrega
created_at
updated_at

* Ejemplo:

Cliente: Juan Pérez

Casa
Trabajo
Negocio

# 08 Tabla pedidos - Tabla central del sistema operativo.

├── id_pedido
├── numero_pedido
│
├── id_cliente
│
├── nombre_solicitante
├── telefono_solicitante
│
├── direccion_recogida
├── latitud_recogida
├── longitud_recogida
│
├── direccion_entrega
├── latitud_entrega
├── longitud_entrega
│
├── fecha_servicio
│
├── hora_desde
├── hora_hasta
├── lo_antes_posible
│
├── modalidad_pago (Receptor paga envio; Remitente paga envio; Receptor paga envio y productos)
├── importe_envio
├── importe_cobro
│
├── id_despachador
├── id_conductor
├── id_vehiculo
│
├── estado
│
├── fecha_publicacion
├── fecha_asignacion
├── fecha_entrega
├── fecha_cancelacion
│
├── created_at
└── updated_at

* Estados de Pedido
- PENDIENTE
- PUBLICADO
- TOMADO
- ARRIBADO
- EN_CAMINO
- ARRIBADO_A_ENTREGA
- ENTREGADO
- RECHAZADO
- CANCELADO

* Caso de  uso 
Si cliente frecuente, id_clietne = 25
Si no es cliente frecuente, id_cliente = null

* Casos de pago
    Caso 1
    RECEPTOR_PAGA_ENVIO

        importe_envio = $80
        importe_cobro = $0
    Caso 2
    REMITENTE_PAGA_ENVIO

        importe_envio = $80
        importe_cobro = $0
    Caso 3
    RECEPTOR_PAGA_ENVIO_PRODUCTOS

        importe_envio = $80
        importe_cobro = $450 

# 09 Tabla pedido_asignaciones - Registra cada intento de asignación.

id_pedido
id_despachador
id_conductor
id_vehiculo
fecha_asignacion
fecha_respuesta
estado
motivo
created_at
updated_at

* Estados
PENDIENTE
ACEPTADA
RECHAZADA
EXPIRADA
CANCELADA
FINALIZADA

Esto permite:

Pedido 1001
      │
      ├── Conductor 01 → RECHAZA
      │
      ├── Conductor 02 → RECHAZA
      │
      └── Conductor 03 → ACEPTA
Resgistro
id_asignacion = 1
id_pedido = 10025
id_conductor = 1
estado = PENDIENTE
o
estado = RECHAZADA
motivo = "Lejos del punto de recogida"

## 10 Tabla pedido_estados - Historial completo del pedido.

id_estado
id_pedido
id_usuario
estado_anterior
estado_nuevo
motivo
origen
latitud
longitud
created_at

* Ejemplo:

PENDIENTE
    ↓
PUBLICADO
    ↓
TOMADO
    ↓
ARRIBADO
    ↓
EN_CAMINO
    ↓
ARRIBADO_A_ENTREGA
    ↓
ENTREGADO

* Origen 

DESPACHADOR
CONDUCTOR
CLIENTE
SISTEMA
ADMIN_CLIENTE 

## 11 Tabla pedido_cambios - Modificaciones relavantes al pedido

pedido_cambios
id_cambio
id_pedido
id_usuario
tipo
campo
valor_anterior
valor_nuevo
motivo
created_at

* Tipos
DIRECCION_RECOGIDA
DIRECCION_ENTREGA
HORARIO
FECHA_SERVICIO
MODALIDAD_PAGO
IMPORTE
CANCELACION
OTRO

* Ejemplo de registro 

id_pedido = 10025
id_usuario = 25
tipo = DIRECCION_ENTREGA
campo = direccion_entrega
valor_anterior = "Av. Tecnológico 500"
valor_nuevo = "Av. Tecnológico 700"
motivo = "Solicitud del cliente"

tipo = HORARIO
valor_anterior = "14:00 - 15:00"
valor_nuevo = "16:00 - 17:00"

estado_nuevo = CANCELADO
motivo = "Cliente solicitante canceló"
origen = CLIENTE

* Caso de uso 

Imagina el pedido:

#10025
09:00

Despachador registra:

PENDIENTE
09:01

Publica:

PUBLICADO
09:03

Conductor acepta:

TOMADO
09:20

Conductor llega:

ARRIBADO
09:22

Recoge:

EN_CAMINO
09:30

Cliente llama y cambia dirección.

pedido_cambios:

DIRECCION_ENTREGA

El pedido continúa:

EN_CAMINO
09:45

Cliente vuelve a llamar y cambia horario.

pedido_cambios:

HORARIO

El estado sigue:

EN_CAMINO
10:00

Entrega:

ENTREGADO
# Arquitectura final 
                         PEDIDO #10025
                              │
             ┌────────────────┼─────────────────┐
             │                │                 │
             ▼                ▼                 ▼
          ESTADO         ASIGNACIONES        CAMBIOS
          ACTUAL           HISTÓRICO         HISTÓRICO
             │                │                 │
             │                │                 ├── Dirección cambió
             │                │                 ├── Horario cambió
             │                │                 └── ...
             │                │
             │                ├── Conductor 01 rechazó
             │                ├── Conductor 02 expiró
             │                └── Conductor 03 aceptó
             │
             └── PENDIENTE
                  PUBLICADO
                  TOMADO
                  ARRIBADO
                  EN_CAMINO
                  ARRIBADO_A_ENTREGA
                  ENTREGADO
## 12 Tabla pagos - Registra el cobro real de cada pedido.

id_pago
id_pedido
metodo_pago
monto
referencia_transaccion
fecha_pago
created_at
updated_at

* Métodos de pago
- EFECTIVO
- TARJETA
- TRANSFERENCIA

## 13 Tabla conductor_posiciones - Histórico de posiciones GPS.

id_posicion
id_conductor
latitud
longitud
precision
velocidad
rumbo
bateria
fecha_posicion
created_at

* Importante
Esta tabla será probablemente la que más crecerá.
No conviene consultar todo el histórico cada vez que el mapa necesite mostrar conductores.

## 14 Tabla conductor_estado - Estado actual del conductor.
id
id_conductor
estado
ultima_conexion
ultima_desconexion
ultima_latitud
ultima_longitud
ultima_actualizacion
created_at
updated_at

* Estados
ONLINE
OFFLINE
DISPONIBLE
OCUPADO
DESCANSO
FUERA_DE_SERVICIO

Esta tabla permite obtener rápidamente:

¿Dónde está el conductor?
¿Está conectado?
¿Está disponible?
¿Cuándo se actualizó?


## 15 Tabla compras_paquetes - facturacion comercial del sistema

id_compra
codigo_paquete
cantidad_paquetes
cantidad_viajes
precio_unitario
importe_total
forma_pago
estado
fecha_compra
created_at
updated_at

## 16 Tabla configuraciones_tenant - Configuraciones particulares de cada empresa.

id_configuracion
clave
valor
created_at
updated_at
Ejemplo:
radio_asignacion = 5
tiempo_expiracion_pedido = 60
permite_efectivo = true
permite_tarjeta = true

## 17 Tabla notificaciones - Notificaciones internas de la aplicación.
id_notificacion
id_usuario
id_pedido
tipo
titulo
mensaje
leida
fecha_lectura
created_at
updated_at

* Tipos
NUEVO_PEDIDO
PEDIDO_ASIGNADO
PEDIDO_CANCELADO
PEDIDO_ENTREGADO
NUEVA_ASIGNACION

## 18 Tabla zonas_servicio
Zonas donde opera la flotilla.
id_zona
nombre
descripcion
estado
created_at
updated_at

## 19 Tabla auditoria - Registro general de cambios sensibles dentro del tenant.

id_auditoria
id_usuario
tabla_afectada
accion
descripcion
created_at


RELACIONES COMPLETAS
                    USUARIOS
                       │
             ┌─────────┴─────────┐
             │                   │
             ▼                   ▼
       DESPACHADORES         CONDUCTORES
                                 │
                         ┌───────┼───────┐
                         │       │       │
                         ▼       ▼       ▼
                    VEHÍCULOS   GPS   ESTADO
                         │
                         ▼
                  CONDUCTOR_VEHICULO


                    CLIENTES
                       │
                       ▼
                DIRECCIONES_CLIENTES
                       │
                       ▼
                    PEDIDOS
                       │
         ┌─────────────┼─────────────┐
         │             │             │
         ▼             ▼             ▼
    ASIGNACIONES     ESTADOS        PAGOS

Relaciones específicas
usuarios.id_usuario
        ↓
despachadores.id_usuario
usuarios.id_usuario
        ↓
conductores.id_usuario
conductores.id_conductor
        ↓
vehiculos.id_conductor
clientes.id_cliente
        ↓
direcciones_clientes.id_cliente
clientes.id_cliente
        ↓
pedidos.id_cliente
despachadores.id_despachador
        ↓
pedidos.id_despachador
conductores.id_conductor
        ↓
pedidos.id_conductor
vehiculos.id_vehiculo
        ↓
pedidos.id_vehiculo
pedidos.id_pedido
        ↓
pedido_asignaciones.id_pedido
pedidos.id_pedido
        ↓
pedido_estados.id_pedido
conductores.id_conductor
        ↓
conductor_posiciones.id_conductor
conductores.id_conductor
        ↓
conductor_estado.id_conductor
pedidos.id_pedido
        ↓
pagos.id_pedido
ARQUITECTURA FINAL
                           ┌─────────────────────┐
                           │    ADMIN CENTRAL    │
                           └──────────┬──────────┘
                                      │
                                      ▼
                           ┌─────────────────────┐
                           │  delivery_central   │
                           ├─────────────────────┤
                           │                     │
                           │ tenants             │
                           │ admins_centrales    │
                           │ planes              │
                           │ suscripciones       │
                           │ configuraciones     │
                           │ logs_centrales      │
                           │                     │
                           └──────────┬──────────┘
                                      │
                                      ▼
                                TENANT 01
                                      │
                                      ▼
                         ┌────────────────────────┐
                         │  delivery_tenant_01    │
                         ├────────────────────────┤
                         │                        │
                         │ usuarios               │
                         │ despachadores          │
                         │ conductores            │
                         │ vehiculos              │
                         │                        │
                         │ clientes               │
                         │ direcciones_clientes   │
                         │                        │
                         │ pedidos                │
                         │ pedido_asignaciones    │
                         │ pedido_estados         │
                         │                        │
                         │ conductor_posiciones   │
                         │ conductor_estado       │
                         │                        │
                         │ pagos                  │
                         │                        │
                         │ notificaciones         │
                         │ configuraciones        │
                         │ zonas_servicio         │
                         │ auditoria              │
                         └────────────────────────┘
Cuando llegue el segundo cliente

No modificamos la estructura:

delivery_central
       │
       ├── Tenant 01 → delivery_tenant_01
       │
       ├── Tenant 02 → delivery_tenant_02
       │
       ├── Tenant 03 → delivery_tenant_03
       │
       ├── Tenant 04 → delivery_tenant_04
       │
       └── Tenant 05 → delivery_tenant_05

Cada BD Tenant tendrá exactamente el mismo esquema.

# Reglas de negocio — Base del Tenant

- Cuando un conductor no responde a una asignación dentro del `tiempo_expiracion_pedido` configurado, el sistema la marca automáticamente como EXPIRADA (`pedido_asignaciones.estado`) y ofrece el pedido al siguiente conductor candidato — el despachador no tiene que reasignar a mano cada rechazo o expiración.
- `radio_asignacion` es el radio inicial de búsqueda de conductores disponibles; si nadie acepta dentro de ese radio, el sistema lo amplía automáticamente en vez de dejar el pedido sin candidatos.
- `zonas_servicio` es informativa por ahora: no restringe qué despachador puede operar en ella ni qué conductor recibe pedidos de ella — no hay relación funcional entre `zonas_servicio` y `pedidos` en esta etapa.
- `pagos` registra el cobro real de cada pedido (método, monto, fecha, referencia de transacción) — no existe liquidación ni comisión al conductor, porque el sistema no gestiona pagos a conductores.
- Los conductores son partners independientes: cubren sus propios gastos operativos (combustible, mantenimiento, peajes). El sistema no lleva registro de gastos de conductor.
- No se exige evidencia fotográfica ni firma para marcar un pedido como ENTREGADO.
- Un conductor tiene como máximo un vehículo y un vehículo pertenece como máximo a un conductor (`vehiculos.id_conductor`, relación 1 a 1) — no existe historial de vehículos anteriores.
- Un despachador ve y puede operar sobre todos los pedidos y conductores del tenant por igual — no hay segmentación de despachadores por zona ni por sub-flotilla.
- `compras_paquetes` no lleva `id_tenant`: como cada tenant ya vive en su propia base de datos, esa columna sería redundante. `codigo_paquete` es un identificador libre (no hay tabla `paquetes` formal todavía — los paquetes se definen en configuración/código).

