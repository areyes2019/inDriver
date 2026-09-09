// Package cercania resuelve "¿quién está cerca de aquí?" dentro de un tenant.
//
// Devuelve geografía y nada más: quién es elegible, quién tiene saldo y a quién se le asigna el
// pedido lo decide Laravel (SPEC-028, RN-16).
package cercania

import (
	"context"
	"errors"
	"strconv"
	"time"

	"github.com/redis/go-redis/v9"

	"github.com/prosello/gps-service/internal/memoria"
)

type Vecino struct {
	Conductor  int64   `json:"id_conductor"`
	DistanciaK float64 `json:"distancia_km"`
	FechaMs    int64   `json:"fecha_ms"`
}

type Buscador struct {
	mem *memoria.Memoria
}

func NuevoBuscador(mem *memoria.Memoria) *Buscador {
	return &Buscador{mem: mem}
}

// Buscar recorre el índice del tenant por distancia ascendente.
//
// El índice geoespacial de Redis no tiene caducidad por miembro, así que un conductor que dejó de
// mandar posiciones seguiría figurando ahí para siempre. La verdad de "sigue vivo" es la clave de
// posición, que sí caduca a los 3 minutos (RN-06): al que ya no la tiene se le descarta y se le
// saca del índice de paso, para que el índice se limpie solo con el uso.
func (b *Buscador) Buscar(ctx context.Context, tenant string, lat, lng, radioKm float64, limite int) ([]Vecino, error) {
	candidatos, err := b.mem.Cliente.GeoSearchLocation(ctx, memoria.ClaveGeo(tenant), &redis.GeoSearchLocationQuery{
		GeoSearchQuery: redis.GeoSearchQuery{
			Latitude:   lat,
			Longitude:  lng,
			Radius:     radioKm,
			RadiusUnit: "km",
			Sort:       "ASC",
			// Se pide de más porque parte de los candidatos serán posiciones ya caducadas.
			Count: limite * 3,
		},
		WithDist: true,
	}).Result()
	if err != nil {
		return nil, err
	}

	vecinos := make([]Vecino, 0, limite)
	var caducados []string

	for _, c := range candidatos {
		if len(vecinos) == limite {
			break
		}

		conductor, err := strconv.ParseInt(c.Name, 10, 64)
		if err != nil {
			caducados = append(caducados, c.Name)

			continue
		}

		fecha, err := b.mem.Cliente.HGet(ctx, memoria.ClavePos(tenant, conductor), "fecha_ms").Int64()
		if errors.Is(err, redis.Nil) {
			caducados = append(caducados, c.Name)

			continue
		}
		if err != nil {
			return nil, err
		}

		vecinos = append(vecinos, Vecino{Conductor: conductor, DistanciaK: c.Dist, FechaMs: fecha})
	}

	if len(caducados) > 0 {
		b.limpiar(ctx, tenant, caducados)
	}

	return vecinos, nil
}

// La limpieza es oportunista y no puede tumbar la búsqueda: si falla, el siguiente que pase por
// aquí lo vuelve a intentar.
func (b *Buscador) limpiar(ctx context.Context, tenant string, miembros []string) {
	ctx, cancelar := context.WithTimeout(context.WithoutCancel(ctx), time.Second)
	defer cancelar()

	valores := make([]any, len(miembros))
	for i, m := range miembros {
		valores[i] = m
	}

	b.mem.Cliente.ZRem(ctx, memoria.ClaveGeo(tenant), valores...)
}
