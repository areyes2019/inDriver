// Package buzon es la comunicación con Laravel: dos streams de Redis, uno en cada sentido.
//
// Son buzón y no megáfono a propósito (SPEC-028, RN-17): con Pub/Sub, reiniciar el servicio o el
// worker perdería los avisos emitidos mientras nadie escuchaba, y "se perdió el ENVIO_INICIADO"
// significa un conductor que rueda sin que se le guarde el recorrido.
package buzon

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"strconv"
	"strings"
	"time"

	"github.com/redis/go-redis/v9"

	"github.com/prosello/gps-service/internal/memoria"
)

// Avisos que el servicio manda a Laravel.
const (
	TipoPosicion = "POSICION"

	// TipoPosicionSinEnvio es la posición de un conductor en línea sin envío en curso (RN-06,
	// spec tenant/025 RN-08). No dispara `UbicacionActualizada` ni se acumula como recorrido —eso
	// sigue siendo privilegio de `TipoPosicion`—, pero Laravel necesita guardarla en
	// `conductor_estados` para tener un origen real de dónde arrancar la próxima simulación TEST.
	TipoPosicionSinEnvio = "POSICION_SIN_ENVIO"

	TipoRecorrido = "RECORRIDO"
	TipoLatido    = "LATIDO"
)

// Avisos que Laravel manda al servicio.
const (
	TipoEnvioIniciado    = "ENVIO_INICIADO"
	TipoEnvioTerminado   = "ENVIO_TERMINADO"
	TipoPermisoCancelado = "PERMISO_CANCELADO"
)

// Retencion es lo que se conserva de cada stream. Un buzón sin poda crece hasta llenar la memoria.
const Retencion = 24 * time.Hour

type Emisor struct {
	mem    *memoria.Memoria
	stream string
}

func NuevoEmisor(mem *memoria.Memoria, stream string) *Emisor {
	return &Emisor{mem: mem, stream: stream}
}

// Publicar deja el aviso en el buzón. El cuerpo va como JSON en un solo campo: así Laravel lo
// decodifica de una pieza y agregar un campo nuevo no obliga a tocar el consumidor.
func (e *Emisor) Publicar(ctx context.Context, tipo string, datos any) error {
	cuerpo, err := json.Marshal(datos)
	if err != nil {
		return err
	}

	return e.mem.Cliente.XAdd(ctx, &redis.XAddArgs{
		Stream: e.stream,
		MinID:  strconv.FormatInt(time.Now().Add(-Retencion).UnixMilli(), 10),
		Approx: true,
		Values: map[string]any{"tipo": tipo, "datos": string(cuerpo)},
	}).Err()
}

// Aviso es un mensaje ya sacado del buzón.
type Aviso struct {
	ID    string
	Tipo  string
	Datos json.RawMessage
}

type Consumidor struct {
	mem        *memoria.Memoria
	stream     string
	grupo      string
	consumidor string
	bitacora   *slog.Logger
}

func NuevoConsumidor(mem *memoria.Memoria, stream, grupo, consumidor string, bitacora *slog.Logger) *Consumidor {
	return &Consumidor{mem: mem, stream: stream, grupo: grupo, consumidor: consumidor, bitacora: bitacora}
}

// Escuchar bloquea hasta que se cancele el contexto. Un aviso solo se confirma (XACK) después de
// procesarse sin error: si el servicio muere a medias, otro consumidor lo vuelve a recibir.
func (c *Consumidor) Escuchar(ctx context.Context, manejar func(context.Context, Aviso) error) {
	c.asegurarGrupo(ctx)

	for {
		if ctx.Err() != nil {
			return
		}

		flujos, err := c.mem.Cliente.XReadGroup(ctx, &redis.XReadGroupArgs{
			Group:    c.grupo,
			Consumer: c.consumidor,
			Streams:  []string{c.stream, ">"},
			Count:    50,
			Block:    5 * time.Second,
		}).Result()

		// Sin mensajes en la ventana de espera: no es un error, se vuelve a esperar.
		if errors.Is(err, redis.Nil) {
			continue
		}

		if err != nil {
			if ctx.Err() != nil {
				return
			}

			// Redis se cayó o se reinició (y con él, el grupo). Se recrea y se reintenta con una
			// pausa, en vez de girar en vacío quemando CPU.
			c.bitacora.Warn("no se pudo leer el buzón", "error", err)
			c.esperar(ctx, time.Second)
			c.asegurarGrupo(ctx)

			continue
		}

		for _, flujo := range flujos {
			for _, mensaje := range flujo.Messages {
				c.procesar(ctx, mensaje, manejar)
			}
		}
	}
}

func (c *Consumidor) procesar(ctx context.Context, mensaje redis.XMessage, manejar func(context.Context, Aviso) error) {
	tipo, _ := mensaje.Values["tipo"].(string)
	datos, _ := mensaje.Values["datos"].(string)

	aviso := Aviso{ID: mensaje.ID, Tipo: tipo, Datos: json.RawMessage(datos)}

	if err := manejar(ctx, aviso); err != nil {
		// No se confirma: el aviso queda pendiente y se reintenta. Se registra sin el cuerpo, que
		// puede traer coordenadas.
		c.bitacora.Error("aviso no procesado", "tipo", tipo, "id", mensaje.ID, "error", err)

		return
	}

	if err := c.mem.Cliente.XAck(ctx, c.stream, c.grupo, mensaje.ID).Err(); err != nil {
		c.bitacora.Warn("no se pudo confirmar el aviso", "id", mensaje.ID, "error", err)
	}
}

// El grupo se crea con "$": al arrancar por primera vez se ignora lo viejo del buzón. Un
// ENVIO_INICIADO de hace tres días no describe nada del presente.
func (c *Consumidor) asegurarGrupo(ctx context.Context) {
	err := c.mem.Cliente.XGroupCreateMkStream(ctx, c.stream, c.grupo, "$").Err()
	if err != nil && !strings.Contains(err.Error(), "BUSYGROUP") {
		c.bitacora.Warn("no se pudo preparar el grupo del buzón", "grupo", c.grupo, "error", err)
	}
}

func (c *Consumidor) esperar(ctx context.Context, d time.Duration) {
	temporizador := time.NewTimer(d)
	defer temporizador.Stop()

	select {
	case <-ctx.Done():
	case <-temporizador.C:
	}
}
