// Servicio GPS de la plataforma (SPEC-028).
//
// Recibe posiciones de los conductores, las guarda en memoria y le pasa a Laravel lo que deba
// difundirse o persistirse. No abre MySQL ni decide nada de negocio.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"sync"
	"syscall"
	"time"

	"github.com/prosello/gps-service/internal/buzon"
	"github.com/prosello/gps-service/internal/cercania"
	"github.com/prosello/gps-service/internal/config"
	"github.com/prosello/gps-service/internal/memoria"
	"github.com/prosello/gps-service/internal/posicion"
	"github.com/prosello/gps-service/internal/presencia"
	"github.com/prosello/gps-service/internal/tanda"
	"github.com/prosello/gps-service/internal/web"
)

func main() {
	bitacora := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))

	cfg, err := config.Cargar()
	if err != nil {
		bitacora.Error("configuración inválida", "error", err)
		os.Exit(1)
	}

	// El contexto muere con SIGINT/SIGTERM: es lo que hace que systemd pueda reiniciar el servicio
	// sin cortar a media escritura.
	ctx, detener := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer detener()

	mem := memoria.Conectar(cfg.RedisAddr, cfg.RedisPassword, cfg.RedisDB)
	defer mem.Cerrar()

	// Arrancar sin memoria no es fatal: se registra y se sigue. Redis puede estar levantándose
	// todavía, y go-redis reconecta solo (SPEC-028, §17 de la spec original / §7.7).
	if err := mem.Disponible(ctx); err != nil {
		bitacora.Warn("la memoria no responde al arrancar", "error", err)
	}

	pre := presencia.Nueva(mem, cfg.EnvioTTL, cfg.LatidoCada)
	almacen := posicion.NuevoAlmacen(mem, cfg.PresenciaTTL)
	buscador := cercania.NuevoBuscador(mem)
	emisor := buzon.NuevoEmisor(mem, cfg.StreamHaciaLaravel)
	acumulador := tanda.Nuevo(emisor, cfg.TandaMax, cfg.TandaIntervalo, bitacora)
	servidor := web.Nuevo(cfg, mem, pre, almacen, buscador, emisor, acumulador, bitacora)

	var tareas sync.WaitGroup

	tareas.Add(1)
	go func() {
		defer tareas.Done()
		acumulador.Correr(ctx)
	}()

	tareas.Add(1)
	go func() {
		defer tareas.Done()

		consumidor := buzon.NuevoConsumidor(mem, cfg.StreamHaciaServicio, cfg.GrupoServicio, cfg.Consumidor, bitacora)
		consumidor.Escuchar(ctx, manejador(pre, almacen, acumulador, cfg, bitacora))
	}()

	tareas.Add(1)
	go func() {
		defer tareas.Done()
		barrer(ctx, servidor)
	}()

	servidorHTTP := &http.Server{
		Addr:    cfg.Direccion,
		Handler: servidor.Rutas(),

		// Plazos explícitos: sin ellos, un teléfono con la conexión colgada retiene una goroutine
		// y un descriptor de archivo indefinidamente.
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       10 * time.Second,
		WriteTimeout:      10 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	go func() {
		bitacora.Info("servicio GPS escuchando", "direccion", cfg.Direccion)

		if err := servidorHTTP.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			bitacora.Error("el servidor HTTP se detuvo", "error", err)
			detener()
		}
	}()

	<-ctx.Done()
	bitacora.Info("apagando")

	// Primero se deja de aceptar peticiones y después se espera a las tareas de fondo, para que el
	// último vaciado de tandas incluya todo lo que entró hasta el final.
	cierre, cancelar := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancelar()

	if err := servidorHTTP.Shutdown(cierre); err != nil {
		bitacora.Warn("apagado del servidor HTTP incompleto", "error", err)
	}

	tareas.Wait()
	bitacora.Info("apagado limpio")
}

type avisoEnvio struct {
	Tenant    string `json:"tenant"`
	Conductor int64  `json:"id_conductor"`
	Pedido    any    `json:"id_pedido"`
}

type avisoPermiso struct {
	Tenant    string `json:"tenant"`
	Conductor int64  `json:"id_conductor"`
	Desde     int64  `json:"desde"`
}

// manejador aplica los avisos que manda Laravel (SPEC-028, §6.3). Devolver error deja el aviso sin
// confirmar para que se reintente; devolver nil lo da por procesado, incluso si venía mal formado
// —reintentar un mensaje corrupto para siempre solo atasca el buzón—.
func manejador(
	pre *presencia.Presencia,
	almacen *posicion.Almacen,
	acumulador *tanda.Acumulador,
	cfg config.Config,
	bitacora *slog.Logger,
) func(context.Context, buzon.Aviso) error {
	return func(ctx context.Context, aviso buzon.Aviso) error {
		switch aviso.Tipo {
		case buzon.TipoEnvioIniciado:
			var datos avisoEnvio
			if err := json.Unmarshal(aviso.Datos, &datos); err != nil || datos.Tenant == "" || datos.Conductor == 0 {
				bitacora.Warn("ENVIO_INICIADO mal formado", "id", aviso.ID)

				return nil
			}

			return pre.AbrirEnvio(ctx, datos.Tenant, datos.Conductor, texto(datos.Pedido))

		case buzon.TipoEnvioTerminado:
			var datos avisoEnvio
			if err := json.Unmarshal(aviso.Datos, &datos); err != nil || datos.Tenant == "" || datos.Conductor == 0 {
				bitacora.Warn("ENVIO_TERMINADO mal formado", "id", aviso.ID)

				return nil
			}

			// El orden importa: primero se suelta lo que quedaba del recorrido y solo después se
			// borra la marca del envío, para que ningún punto se quede sin pedido al que pertenecer.
			acumulador.Cerrar(ctx, datos.Tenant, datos.Conductor)

			if err := pre.CerrarEnvio(ctx, datos.Tenant, datos.Conductor); err != nil {
				return err
			}

			return almacen.Olvidar(ctx, datos.Tenant, datos.Conductor)

		case buzon.TipoPermisoCancelado:
			var datos avisoPermiso
			if err := json.Unmarshal(aviso.Datos, &datos); err != nil || datos.Tenant == "" || datos.Conductor == 0 || datos.Desde == 0 {
				bitacora.Warn("PERMISO_CANCELADO mal formado", "id", aviso.ID)

				return nil
			}

			return pre.Cancelar(ctx, datos.Tenant, datos.Conductor, datos.Desde, cfg.CanceladoTTL)

		default:
			bitacora.Warn("aviso desconocido", "tipo", aviso.Tipo, "id", aviso.ID)

			return nil
		}
	}
}

// El id del pedido llega como número desde PHP y se guarda como texto: aquí solo identifica al
// envío, no se hace aritmética con él.
func texto(v any) string {
	switch t := v.(type) {
	case string:
		return t
	case float64:
		return strconv.FormatInt(int64(t), 10)
	default:
		return ""
	}
}

func barrer(ctx context.Context, servidor *web.Servidor) {
	reloj := time.NewTicker(5 * time.Minute)
	defer reloj.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-reloj.C:
			servidor.Barrer()
		}
	}
}
