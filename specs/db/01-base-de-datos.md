## SPEC Base de Datos - Sistema de facturación

Este SPEC define la tablas, columnas y relaciones de la base de datos del sistema delivery

Actores principales: 
ADMIN CENTRAL
│
├── Tenant / Cliente
│
│   └── ADMIN CLIENTE
│       │
│       ├── DESPACHADORES
│       │   └── Gestionan / asignan pedidos
│       │
│       └── CONDUCTORES
│           ├── Conductor 01
│           ├── Conductor 02
│           ├── Conductor 03
│           └── ...

# Tabla Usuarios
id_usuario
nombre
rol
email
password
estado 
created_at
updated_at

# Tabla Datos Fiscales Usuario
id_datos_usuario
razon_social
regimen_fiscal
cer_ruta
key_ruta
certificados_validados
rfc
calle
numero_int
numero_ext
colonia
cp
ciudad
estado_domicilio
telefono
created_at
updated_at
id_usuario
# Tabla clientes
id_cliente
nombre_legal
regimen_fiscal
rfc
calle
numero_int
numero_ext
colonia
cp
ciudad
estado_domicilio
telefono 
email
created_at
updated_at
id_usuario

# Tabla Factura
id_factura
id_cliente (a quien se factura)
id_datos_usuario
uuid
folio
fecha_timbrado
uso 
forma_pago
tipo_comprobante
metodo_pago
sub_total
iva
total
estado
pdf_ruta
xml_ruta
id_cotizacion
created_at
updated_at

# Tabla Detalle Factura 
id 
cantidad
descripcion
precio_unitario
total
id_factura
created_at
updated_at

# Tabla Cotizaciones
id_cotizacion
id_cliente
sub_total
iva
total
estado
id_usuario
created_at
updated_at

# Tabla Detalle Cotización
id_detalle_cotizacion 
cantidad
descripcion
precio_unitario
total
id_cotizacion
created_at
updated_at

# Tabla Paquetes
id_paquete
nombre_paquete
costo_paquete
cantidad_folios
created_at
updated_at

# Tabla Créditos
id_credito
id_usuario
fecha_compra
id_paquete
forma_pago

# Tabla Movimientos

id_movimiento
id_usuario
id_factura
concepto_movimiento
consumo
acreditacion
saldo
created_at

# Relaciones de Tablas
* tabla_usuarios.id_usuario -> tabla_datos_fiscales_usuario.id_usuario
    - Un usuario puede tener varios registros de datos fiscales
* tabla_clientes.id_usuario -> tabla_usuarios.id_usuario
    - Un usuario puede tener varios clientes
* tabla_cotizaciones.id_cliente -> tabla_clientes.id_cliente
    - Un cliente puede generar muchas cotizaciones
* tabla_detalle_cotizaciones.id_cotizacion -> tabla_cotizaciones.id_cotizacion
    - Un cotizacion puede tener varios detalles
* tabla_facturas.id_cotizacion -> tabla_cotizaciones.id_cotizacion
    - La cotizacion puede tener una referencia a la factura
* tabla_detalle_facturas.id_factura -> tabla_facturas.id_factura
    - La factura puede tener varios detalles
* tabla_facturas.id_cliente -> tabla_clientes.id_cliente
    - Un cliente puede tener varias facturas
* tabla_movimientos.id_usuario -> tabla_usuarios.id_usuario
    - Un usuario puede tener varios movimientos
* tabla_creditos.id_usuario -> tabla_usuarios.id_usuario
    - Un usuario puede tener varios registros de crédito
* tabla_creditos.id_paquete -> tabla_paquetes.id_paquete
    - Referencia al paquete comprado (solo lectura, para historial de precios)
* tabla_facturas.id_datos_usuario -> tabla_datos_fiscales_usuario.id_datos_usuario
    - Una empresa puede generar varias facturas 
