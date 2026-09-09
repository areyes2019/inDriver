# Solo para desarrollo y pruebas de carga (SPEC-028, §22 y §14): producción corre el binario bajo
# systemd, sin contenedores.
FROM golang:1.23-alpine AS construccion

WORKDIR /src

# Las dependencias van en su propia capa: cambiar el código no obliga a volver a descargarlas.
COPY go.mod go.sum ./
RUN go mod download

COPY . .

# Sin CGO y con la tabla de símbolos fuera, el binario queda estático y cabe en una imagen vacía.
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /gps ./cmd/servidor

FROM gcr.io/distroless/static-debian12:nonroot

COPY --from=construccion /gps /gps

USER nonroot:nonroot
EXPOSE 8081

ENTRYPOINT ["/gps"]
