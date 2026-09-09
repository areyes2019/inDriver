Sí. Yo lo convertiría en una **Spec GPS desacoplada**, diseñada específicamente para integrarse a tu plataforma actual sin obligarte a migrar MySQL, Sanctum, Reverb ni toda tu infraestructura.

La idea central sería:

> **Agregar Go + Redis sin tocar lo que ya funciona.**

Te dejo una versión lista para pasarle a Claude:

# SPEC — Microservicio GPS en tiempo real

## 1. Objetivo

Agregar a la plataforma actual un microservicio especializado en **GPS de alta frecuencia y presencia en tiempo real**, sin modificar ni reemplazar la arquitectura de negocio existente.

El microservicio debe encargarse exclusivamente de:

* Recibir posiciones GPS de conductores.
* Validar que el conductor esté autorizado.
* Mantener la última posición de cada conductor en Redis.
* Permitir búsquedas de conductores cercanos.
* Preparar la infraestructura necesaria para transmitir posiciones en tiempo real.
* Mantenerse desacoplado de Laravel para que una caída de Laravel no detenga la recepción del GPS.

### Principio fundamental

**Laravel sigue siendo el dueño de toda la lógica de negocio.**

Go solamente administra información de tiempo real relacionada con ubicación y presencia.

---

# 2. Arquitectura existente que NO debe modificarse

La implementación debe respetar la arquitectura actual:

* Laravel 13.17
* PHP 8.3
* MySQL
* Laravel Sanctum
* stancl/tenancy 3.10
* Vue 3.5
* Vite 8
* Pinia
* Google Maps JavaScript
* Vue 3 + Capacitor para la aplicación del conductor
* Laravel Reverb para eventos en tiempo real existentes
* Infraestructura actual Hostinger + VPS/nginx
* Sin migrar a PostgreSQL.
* Sin introducir PostGIS.
* Sin reemplazar Sanctum por JWT.
* Sin reemplazar Laravel Reverb en esta primera etapa.
* Sin introducir Docker como requisito obligatorio de producción.

**No realizar migraciones de infraestructura que no sean necesarias para implementar el GPS.**

---

# 3. Nueva arquitectura

Se agregarán únicamente dos componentes principales:

```text
                    ┌──────────────────────┐
                    │       Laravel        │
                    │                      │
                    │ Lógica de negocio    │
                    │ Pedidos               │
                    │ Usuarios              │
                    │ Tenants               │
                    │ Pagos                 │
                    │ Sanctum               │
                    │ MySQL                 │
                    └──────────┬───────────┘
                               │
                               │ Eventos / comandos
                               ▼
                         ┌───────────┐
                         │   Redis   │
                         └─────┬─────┘
                               │
                               ▼
                    ┌──────────────────────┐
                    │      Go GPS           │
                    │                      │
                    │ Recepción GPS         │
                    │ GEOADD                │
                    │ GEOSEARCH             │
                    │ Presencia             │
                    │ WebSocket/SSE futuro  │
                    └──────────┬───────────┘
                               │
                               ▼
                         Redis GEO
```

---

# 4. Responsabilidades

## Laravel

Laravel continúa siendo responsable de:

* Autenticación.
* Autorización de usuarios.
* Tenants.
* Conductores.
* Pedidos.
* Asignaciones.
* Estados de pedidos.
* Pagos.
* Reglas de negocio.
* Persistencia permanente.
* Eventos de negocio.
* Comunicación con Reverb.

Laravel **NO debe procesar cada ping GPS individual**.

---

# 5. Responsabilidades de Go

Go será responsable exclusivamente de:

### GPS

Recibir:

```json
{
    "driver_id": "01ABC...",
    "lat": 20.5234,
    "lng": -100.8157,
    "heading": 180,
    "speed": 35
}
```

Procesar el ping sin necesidad de consultar Laravel en cada petición.

### Ubicación actual

Guardar la última ubicación mediante Redis GEO.

Conceptualmente:

```text
GEOADD tenant:{tenant_id}:drivers:geo
       longitude
       latitude
       driver_id
```

Además, guardar información complementaria de la última posición:

```text
tenant:{tenant_id}:driver:{driver_id}:location
```

con datos como:

* lat
* lng
* heading
* speed
* timestamp
* última actualización

---

# 6. Multi-tenancy

El microservicio debe respetar estrictamente el aislamiento de tenants.

Nunca debe existir una estructura global donde un tenant pueda consultar accidentalmente las posiciones de otro.

Las claves Redis deben incluir obligatoriamente el `tenant_id`.

Ejemplo:

```text
tenant:{tenant_id}:drivers:geo
tenant:{tenant_id}:driver:{driver_id}:location
tenant:{tenant_id}:drivers:online
```

El `driver_id` por sí solo nunca debe utilizarse para determinar el tenant.

---

# 7. Autenticación

NO modificar Sanctum.

NO convertir el sistema completo a JWT.

El sistema actual continuará utilizando Sanctum para Laravel.

Para Go se debe implementar un mecanismo de autenticación específico para el servicio GPS.

La opción recomendada es utilizar un **token GPS de corta duración**, emitido por Laravel para el conductor.

Flujo:

```text
Conductor
   │
   │ solicita acceso GPS
   ▼
Laravel
   │
   │ genera token GPS temporal
   ▼
Conductor
   │
   │ token GPS
   ▼
Go
   │
   │ valida token
   ▼
GPS autorizado
```

El token debe contener como mínimo:

```text
tenant_id
driver_id
exp
```

Opcionalmente:

```text
role
```

El token debe tener una duración corta.

Go debe validar:

* Firma.
* Expiración.
* tenant_id.
* driver_id.
* Claims obligatorios.
* Algoritmo esperado.

No aceptar tokens sin firma válida.

No aceptar tokens expirados.

No aceptar tokens pertenecientes a otro tenant.

---

# 8. Endpoint GPS

Crear un endpoint dedicado:

```http
POST /api/v1/gps/ping
```

Header:

```http
Authorization: Bearer {gps_token}
```

Body:

```json
{
    "lat": 20.5234,
    "lng": -100.8157,
    "heading": 180,
    "speed": 35,
    "timestamp": 1750000000
}
```

Go debe:

1. Validar token.
2. Obtener `tenant_id` y `driver_id`.
3. Validar coordenadas.
4. Validar timestamp.
5. Guardar ubicación en Redis.
6. Actualizar presencia.
7. Responder rápidamente.

Respuesta:

```json
{
    "success": true,
    "timestamp": 1750000000
}
```

El endpoint debe evitar consultas innecesarias a Laravel.

---

# 9. Frecuencia GPS

El sistema debe soportar múltiples conductores enviando posiciones simultáneamente.

No asumir una frecuencia fija.

Debe funcionar correctamente tanto con:

```text
1 ping cada 5 segundos
```

como:

```text
1 ping cada 1 segundo
```

La aplicación móvil debe poder controlar la frecuencia según:

* movimiento.
* batería.
* precisión.
* estado online/offline.
* configuración futura.

---

# 10. Presencia del conductor

Redis también debe utilizarse para determinar si un conductor está actualmente activo.

Crear una estructura de presencia:

```text
tenant:{tenant_id}:drivers:online
```

La presencia debe actualizarse cada vez que se recibe un ping válido.

Utilizar TTL para evitar que un conductor quede permanentemente "online" si la aplicación se cierra inesperadamente.

Ejemplo conceptual:

```text
driver_id → TTL
```

Si el conductor deja de enviar pings durante el período definido:

```text
ONLINE → OFFLINE
```

No depender exclusivamente de que la aplicación móvil envíe un evento de logout.

---

# 11. Búsqueda de conductores cercanos

Go debe proporcionar una función utilizando:

```text
GEOSEARCH
```

Ejemplo:

```http
GET /api/v1/gps/nearby?lat=20.5234&lng=-100.8157&radius=3
```

La búsqueda debe estar limitada al tenant correspondiente.

Respuesta conceptual:

```json
{
    "drivers": [
        {
            "driver_id": "01ABC",
            "distance_km": 0.8
        },
        {
            "driver_id": "01DEF",
            "distance_km": 1.4
        }
    ]
}
```

Go solamente proporciona información geográfica.

**Go NO decide a quién asignar un pedido.**

La decisión final de asignación continúa perteneciendo a Laravel.

---

# 12. Flujo de asignación

Cuando Laravel crea un pedido:

```text
Laravel
   │
   │ pedido creado
   ▼
Laravel determina candidatos
   │
   │ consulta
   ▼
Go GPS
   │
   │ GEOSEARCH
   ▼
conductores cercanos
   │
   ▼
Laravel
   │
   │ aplica reglas de negocio
   ▼
conductor seleccionado
```

Go nunca debe decidir:

* quién puede recibir el pedido.
* quién tiene prioridad.
* quién tiene saldo.
* quién está autorizado.
* quién pertenece al despachador.
* quién puede aceptar.
* quién debe recibir la comisión.

Todo eso pertenece a Laravel.

---

# 13. Tiempo real

La plataforma actualmente utiliza Laravel Reverb.

**No reemplazar Reverb en esta primera implementación.**

El objetivo inicial es integrar el GPS con la arquitectura existente de forma progresiva.

Primera etapa:

```text
Conductor
   ↓
Go
   ↓
Redis
```

Posteriormente se puede agregar:

```text
Go
 ↓
WebSocket
 ↓
Panel Vue
```

si las pruebas de carga demuestran que es necesario.

La implementación debe dejar preparada la arquitectura para incorporar WebSocket en Go sin tener que reescribir el servicio GPS.

---

# 14. Comunicación Laravel ↔ Redis

Redis será utilizado como capa de comunicación cuando sea necesario.

No convertir automáticamente toda la comunicación de Laravel en Redis.

Utilizar Redis únicamente para eventos que realmente necesiten comunicación desacoplada.

Ejemplos futuros:

```text
DRIVER_ONLINE
DRIVER_OFFLINE
DRIVER_LOCATION_UPDATED
ORDER_LOCATION_REQUESTED
```

La lógica de negocio debe permanecer en Laravel.

---

# 15. Redis

Redis debe utilizarse para información temporal y de alta frecuencia.

Principalmente:

```text
GEOADD
GEOSEARCH
TTL
GET
SET
```

No utilizar MySQL para guardar cada ping GPS.

MySQL continúa siendo la base de datos permanente de la plataforma.

---

# 16. Historial GPS

La primera versión NO debe guardar cada ping GPS en MySQL.

El objetivo inicial es:

```text
GPS → Go → Redis → ubicación actual
```

Si posteriormente se necesita historial:

```text
GPS
 ↓
Go
 ↓
Redis
 ↓
proceso asíncrono
 ↓
Laravel
 ↓
MySQL
```

El historial debe diseñarse posteriormente según el volumen real.

No crear una tabla gigantesca de millones de registros sin una estrategia de retención.

---

# 17. Resiliencia

El servicio Go debe ser independiente de Laravel.

Si Laravel deja de responder:

```text
Go + Redis
```

deben poder continuar recibiendo posiciones mientras exista un token GPS válido.

Si Redis se desconecta:

* Go debe detectar el error.
* Reintentar conexión.
* No bloquear permanentemente las goroutines.
* Registrar el error.
* Recuperarse automáticamente cuando Redis vuelva.

No utilizar ciclos infinitos sin control.

Utilizar:

* context.Context
* timeouts
* connection pooling
* goroutines controladas
* manejo explícito de errores.

---

# 18. Seguridad

Go debe validar:

* Token.
* Tenant.
* Driver.
* Coordenadas.
* Timestamp.
* Payload.
* Tamaño máximo del request.
* Rate limiting básico.

No confiar en:

```json
{
    "driver_id": "otro_driver"
}
```

si el `driver_id` ya viene determinado por el token.

El `driver_id` efectivo debe provenir de la identidad autenticada.

---

# 19. Estructura del microservicio Go

Crear una estructura modular:

```text
gps-service/
├── cmd/
│   └── server/
│       └── main.go
│
├── internal/
│   ├── auth/
│   ├── gps/
│   ├── redis/
│   ├── presence/
│   ├── geo/
│   ├── http/
│   └── config/
│
├── go.mod
└── Dockerfile
```

Separar responsabilidades.

No colocar toda la lógica en `main.go`.

---

# 20. Endpoints iniciales

Implementar inicialmente:

```text
GET  /health
POST /api/v1/gps/ping
GET  /api/v1/gps/nearby
```

`/health` no debe requerir autenticación.

Debe comprobar:

* servicio Go funcionando.
* conectividad con Redis.

---

# 21. Observabilidad

Registrar como mínimo:

* errores.
* conexiones Redis.
* pings rechazados.
* pings procesados.
* latencia.
* conductores online.
* errores de autenticación.

No registrar tokens completos.

No registrar información sensible innecesaria.

Agregar métricas posteriormente si el volumen lo requiere.

---

# 22. Docker

Docker NO debe convertirse en requisito para toda la plataforma actual.

Crear Dockerfile para Go para permitir:

```text
docker compose
```

en desarrollo y pruebas.

La infraestructura actual puede continuar funcionando sin Docker mientras se valida el nuevo servicio.

Si posteriormente se decide migrar producción a Docker, hacerlo como una fase independiente.

---

# 23. PostgreSQL/PostGIS

NO introducir PostgreSQL ni PostGIS en esta fase.

Redis GEO cubre inicialmente las necesidades de:

* última ubicación.
* distancia.
* búsqueda de conductores cercanos.

Si posteriormente se requieren:

* geofencing avanzado.
* análisis geoespacial complejo.
* rutas.
* polígonos.
* consultas históricas geográficas complejas.

se evaluará PostGIS como una evolución independiente.

---

# 24. Flujo completo

### Conductor inicia sesión

```text
App Capacitor
     ↓
Laravel / Sanctum
     ↓
usuario autenticado
     ↓
solicita token GPS
     ↓
Laravel entrega token GPS temporal
```

### Conductor envía ubicación

```text
App
 ↓
Go
 ↓
validación token
 ↓
validación tenant/driver
 ↓
Redis GEOADD
 ↓
Redis ubicación actual
 ↓
respuesta inmediata
```

### Panel solicita conductores cercanos

```text
Panel Vue
 ↓
Laravel / Go
 ↓
GEOSEARCH
 ↓
conductores cercanos
```

### Laravel asigna pedido

```text
Pedido
 ↓
Laravel
 ↓
consulta candidatos geográficos
 ↓
Go / Redis
 ↓
candidatos
 ↓
Laravel aplica reglas
 ↓
conductor seleccionado
```

---

# 25. Regla arquitectónica principal

La implementación debe respetar esta frontera:

### Laravel = VERDAD DEL NEGOCIO

```text
Usuarios
Tenants
Conductores
Despachadores
Pedidos
Asignaciones
Pagos
Estados
Permisos
Reglas
```

### Go = TIEMPO REAL GPS

```text
Posición
Distancia
Presencia
Geolocalización
Ingesta GPS
```

### Redis = VELOCIDAD Y COMUNICACIÓN

```text
Ubicación actual
Geodatos temporales
Presencia
Eventos desacoplados
```

Esta separación debe mantenerse durante toda la implementación.

---

# 26. Resultado esperado

Al finalizar esta implementación:

1. Laravel seguirá funcionando con MySQL y Sanctum.
2. stancl/tenancy seguirá funcionando sin modificaciones estructurales.
3. Vue y Capacitor seguirán utilizando la arquitectura actual.
4. Reverb continuará funcionando.
5. Go recibirá los pings GPS de alta frecuencia.
6. Redis almacenará las posiciones activas.
7. Go podrá buscar conductores cercanos.
8. Laravel podrá utilizar esa información para sus decisiones de negocio.
9. Una caída de Laravel no deberá detener la ingesta GPS.
10. El sistema quedará preparado para agregar WebSockets GPS dedicados posteriormente.
11. No se realizará migración a PostgreSQL/PostGIS en esta fase.
12. No se reemplazará Sanctum por JWT.
13. No se reescribirá la plataforma existente.

# 27. Regla de implementación

**No modificar funcionalidades existentes que no sean necesarias para integrar el GPS.**

Antes de crear código:

1. Analizar la estructura actual del proyecto.
2. Identificar cómo se autentican actualmente los conductores.
3. Identificar cómo se identifica el tenant.
4. Identificar cómo funciona actualmente Reverb.
5. Identificar el flujo actual de ubicación GPS.
6. Reutilizar estructuras existentes cuando sea posible.
7. No crear tablas, servicios o migraciones duplicadas.
8. No reemplazar componentes existentes sin una razón técnica demostrable.

La implementación debe realizarse de forma incremental y compatible con el sistema actual.

