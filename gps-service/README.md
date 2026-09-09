# Servicio GPS

Ingesta de posiciones de conductores y búsqueda de cercanía. Implementa
[`specs/tenant/028-microservicio-gps.md`](../specs/tenant/028-microservicio-gps.md).

Lo que **no** hace, y no debe empezar a hacer: abrir MySQL, decidir a quién se le asigna un pedido,
hablar con Reverb, o saber qué es un saldo. Todo eso es de Laravel (RN-16, RN-20).

## Estructura

```text
cmd/servidor/   arranque y cableado
cmd/carga/      prueba de carga de 200 conductores (criterio de aceptación 1)
internal/
  config/       variables de entorno
  permiso/      verificación del permiso GPS (HS256, sin dependencias externas)
  memoria/      conexión a Redis y construcción de claves — todas llevan el tenant
  posicion/     validación y guardado de la posición actual
  presencia/    envío en curso, latido y permisos cancelados
  cercania/     GEOSEARCH acotado al tenant
  buzon/        los dos streams contra Laravel
  tanda/        acumulación del recorrido y vaciado por bloques
  web/          rutas, freno y respuestas
```

## Endpoints

| Método | Ruta | Quién |
|---|---|---|
| GET | `/health` | público |
| POST | `/gps/v1/ping` | conductor, con permiso GPS |
| GET | `/gps/v1/nearby` | Laravel, con token de servicio |

## Desarrollo

```bash
go mod tidy          # la primera vez: descarga go-redis y escribe go.sum
go test ./...
go vet ./...
go run ./cmd/servidor
```

Con Redis en local y estas variables:

```bash
export GPS_TOKEN_SECRET=secreto-de-pruebas
export GPS_SERVICE_TOKEN=token-de-servicio
export REDIS_HOST=127.0.0.1
export GPS_LISTEN=127.0.0.1:8081
```

Los mismos valores de `GPS_TOKEN_SECRET` y `GPS_SERVICE_TOKEN` tienen que estar en el `.env` de
`backend/`, o los dos lados no se reconocen.

Para probar un ping a mano hace falta que el conductor tenga envío en curso, que en producción abre
Laravel por el buzón (RN-01, RN-02):

```bash
redis-cli SET "tenant:cafe-luna:conductor:1:envio" 1 EX 3600
```

## Prueba de carga

```bash
go run ./cmd/carga -conductores 200 -cada 5s -durante 10m -secreto "$GPS_TOKEN_SECRET"
```

El criterio de aceptación es p99 por debajo de 50 ms y sin crecimiento de memoria. Es la medición
que decide si más adelante se le agrega un canal WebSocket propio o se deja el Panel en Reverb.

## Despliegue

Producción corre el binario bajo systemd, **sin contenedores**. El `Dockerfile` es solo para
desarrollo y para levantar la prueba de carga en una máquina limpia.

```bash
# 1. Compilar para el VPS (Linux amd64), desde gps-service/
GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o gps-service ./cmd/servidor

# 2. Subir el binario
scp gps-service root@prosello-vps:/usr/local/bin/gps-service
ssh root@prosello-vps 'chmod 755 /usr/local/bin/gps-service'

# 3. Entorno (una sola vez; contiene el secreto, por eso 600)
ssh root@prosello-vps 'cat > /etc/gps-service.env <<EOF
GPS_LISTEN=127.0.0.1:8081
GPS_TOKEN_SECRET=...
GPS_SERVICE_TOKEN=...
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
EOF
chmod 600 /etc/gps-service.env'

# 4. Unidades de systemd (el servicio y el worker del buzón)
scp ../deploy/vps/gps-service.service ../deploy/vps/gps-buzon.service root@prosello-vps:/etc/systemd/system/
ssh root@prosello-vps 'systemctl daemon-reload && systemctl enable --now gps-service gps-buzon'

# 5. Comprobar
curl -fsS https://delivery.prosello.com.mx/gps-health
journalctl -u gps-service -f
```

Redis, nginx y los paquetes los deja listos `deploy/vps/provision.sh`.

Para actualizar basta repetir los pasos 1, 2 y `systemctl restart gps-service`: el apagado es
ordenado y suelta la última tanda de recorrido antes de salir.

## Apagarlo

Vaciar `GPS_SERVICE_URL` en el `.env` de `backend/` y reiniciar PHP-FPM. Laravel deja de emitir
permisos y de publicar avisos, la App vuelve a mandar la posición al endpoint de siempre (RN-19), y
la plataforma queda como antes de esta spec. Es la marcha atrás de cualquiera de los cinco pasos de
la migración (§13).
