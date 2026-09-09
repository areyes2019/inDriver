// Package posicion valida y guarda la posición actual del conductor.
package posicion

import (
	"context"
	"errors"
	"math"
	"time"

	"github.com/redis/go-redis/v9"

	"github.com/prosello/gps-service/internal/memoria"
)

var (
	ErrCoordenadas = errors.New("coordenadas fuera de rango")
	ErrFecha       = errors.New("fecha de posición inválida")
)

// Punto es una lectura ya validada. `Fecha` viaja en milisegundos desde la época porque es lo que
// compara el script de orden y lo que Laravel vuelve a convertir a fecha al persistir.
type Punto struct {
	Latitud   float64  `json:"latitud"`
	Longitud  float64  `json:"longitud"`
	Precision *float64 `json:"precision,omitempty"`
	Velocidad *float64 `json:"velocidad,omitempty"`
	Rumbo     *int     `json:"rumbo,omitempty"`
	Bateria   *int     `json:"bateria,omitempty"`
	FechaMs   int64    `json:"fecha_ms"`
}

// Entrada es lo que llega por HTTP. No incluye tenant ni conductor: salen del permiso (RN-15).
type Entrada struct {
	Latitud       float64  `json:"latitud"`
	Longitud      float64  `json:"longitud"`
	Precision     *float64 `json:"precision"`
	Velocidad     *float64 `json:"velocidad"`
	Rumbo         *int     `json:"rumbo"`
	Bateria       *int     `json:"bateria"`
	FechaPosicion string   `json:"fecha_posicion"`
}

// Validar deja el punto listo para guardar. Una lectura sin fecha se fecha al recibirla: es peor
// descartarla que aceptarla con unos milisegundos de desfase.
func (e Entrada) Validar(ahora time.Time) (Punto, error) {
	if math.IsNaN(e.Latitud) || math.IsNaN(e.Longitud) ||
		e.Latitud < -90 || e.Latitud > 90 || e.Longitud < -180 || e.Longitud > 180 {
		return Punto{}, ErrCoordenadas
	}

	fecha := ahora
	if e.FechaPosicion != "" {
		f, err := time.Parse(time.RFC3339, e.FechaPosicion)
		if err != nil {
			return Punto{}, ErrFecha
		}
		fecha = f
	}

	// Una fecha en el futuro bloquearía todas las posiciones siguientes por el control de orden
	// (RN-11): un teléfono con el reloj adelantado se congelaría en el mapa hasta que caduque.
	if fecha.After(ahora.Add(time.Minute)) {
		fecha = ahora
	}

	return Punto{
		Latitud:   e.Latitud,
		Longitud:  e.Longitud,
		Precision: e.Precision,
		Velocidad: e.Velocidad,
		Rumbo:     e.Rumbo,
		Bateria:   e.Bateria,
		FechaMs:   fecha.UnixMilli(),
	}, nil
}

// Guardar la posición y descartar la desordenada son la misma operación indivisible (RN-11): entre
// leer la fecha anterior y escribir la nueva pueden colarse dos pings del mismo teléfono por rutas
// distintas, y el que llegue segundo ganaría aunque sea más viejo.
var guardar = redis.NewScript(`
local anterior = redis.call('HGET', KEYS[1], 'fecha_ms')
if anterior and tonumber(anterior) >= tonumber(ARGV[7]) then
  return 0
end

redis.call('HSET', KEYS[1],
  'latitud', ARGV[1], 'longitud', ARGV[2], 'rumbo', ARGV[3],
  'velocidad', ARGV[4], 'precision', ARGV[5], 'bateria', ARGV[6], 'fecha_ms', ARGV[7])
redis.call('EXPIRE', KEYS[1], ARGV[8])
redis.call('GEOADD', KEYS[2], ARGV[2], ARGV[1], ARGV[9])

return 1
`)

type Almacen struct {
	mem *memoria.Memoria
	ttl time.Duration
}

func NuevoAlmacen(mem *memoria.Memoria, ttl time.Duration) *Almacen {
	return &Almacen{mem: mem, ttl: ttl}
}

// Guardar devuelve false cuando el punto es más viejo que el ya guardado: no es un error, es una
// posición que llegó tarde por mala señal y hay que ignorar en silencio.
func (a *Almacen) Guardar(ctx context.Context, tenant string, conductor int64, p Punto) (bool, error) {
	claves := []string{memoria.ClavePos(tenant, conductor), memoria.ClaveGeo(tenant)}

	argumentos := []any{
		p.Latitud,
		p.Longitud,
		opcionalEntero(p.Rumbo),
		opcionalDecimal(p.Velocidad),
		opcionalDecimal(p.Precision),
		opcionalEntero(p.Bateria),
		p.FechaMs,
		int(a.ttl.Seconds()),
		memoria.MiembroGeo(conductor),
	}

	escrito, err := guardar.Run(ctx, a.mem.Cliente, claves, argumentos...).Int()
	if err != nil {
		return false, err
	}

	return escrito == 1, nil
}

// Olvidar saca al conductor del mapa cuando termina su envío (SPEC-028, §6.3).
func (a *Almacen) Olvidar(ctx context.Context, tenant string, conductor int64) error {
	tuberia := a.mem.Cliente.TxPipeline()
	tuberia.Del(ctx, memoria.ClavePos(tenant, conductor))
	tuberia.ZRem(ctx, memoria.ClaveGeo(tenant), memoria.MiembroGeo(conductor))
	_, err := tuberia.Exec(ctx)

	return err
}

// Redis no acepta nil como argumento: los opcionales ausentes se guardan como cadena vacía y
// Laravel los vuelve a leer como nulos.
func opcionalDecimal(v *float64) any {
	if v == nil {
		return ""
	}

	return *v
}

func opcionalEntero(v *int) any {
	if v == nil {
		return ""
	}

	return *v
}
