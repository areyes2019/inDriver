// Package presencia responde tres preguntas que el servicio se hace en cada posición: si el
// permiso sigue vivo, si el conductor tiene un envío en curso y si toca mandar latido.
package presencia

import (
	"context"
	"errors"
	"time"

	"github.com/redis/go-redis/v9"

	"github.com/prosello/gps-service/internal/memoria"
)

type Presencia struct {
	mem        *memoria.Memoria
	envioTTL   time.Duration
	latidoCada time.Duration
}

func Nueva(mem *memoria.Memoria, envioTTL, latidoCada time.Duration) *Presencia {
	return &Presencia{mem: mem, envioTTL: envioTTL, latidoCada: latidoCada}
}

// Cancelado dice si el permiso quedó revocado (RN-10): lo está si se emitió antes del instante
// que apuntó Laravel al cerrar la sesión del conductor.
//
// Si Redis no contesta se responde que NO está cancelado: preferimos aceptar una posición de más
// antes que dejar a toda la flota sin rastreo porque la marca no se pudo leer. El permiso caduca
// solo a los 30 minutos.
func (p *Presencia) Cancelado(ctx context.Context, tenant string, conductor int64, emitido time.Time) bool {
	desde, err := p.mem.Cliente.Get(ctx, memoria.ClaveCancelado(tenant, conductor)).Int64()
	if err != nil {
		return false
	}

	return emitido.Unix() <= desde
}

// Cancelar apunta desde cuándo dejan de valer los permisos del conductor. Es el único que escribe
// esa clave: Laravel solo manda el aviso por el buzón, para que cada clave tenga un solo dueño.
//
// La marca vive lo que dura un permiso: pasado ese plazo, todos los que pudo alcanzar ya caducaron
// por su cuenta.
func (p *Presencia) Cancelar(ctx context.Context, tenant string, conductor int64, desde int64, vida time.Duration) error {
	return p.mem.Cliente.Set(ctx, memoria.ClaveCancelado(tenant, conductor), desde, vida).Err()
}

// EnvioActivo devuelve el id del pedido en curso, o cadena vacía si no hay ninguno. El servicio
// nunca lo deduce: se lo dijo Laravel por el buzón (RN-02).
func (p *Presencia) EnvioActivo(ctx context.Context, tenant string, conductor int64) (string, error) {
	pedido, err := p.mem.Cliente.Get(ctx, memoria.ClaveEnvio(tenant, conductor)).Result()
	if errors.Is(err, redis.Nil) {
		return "", nil
	}

	return pedido, err
}

func (p *Presencia) AbrirEnvio(ctx context.Context, tenant string, conductor int64, pedido string) error {
	return p.mem.Cliente.Set(ctx, memoria.ClaveEnvio(tenant, conductor), pedido, p.envioTTL).Err()
}

func (p *Presencia) CerrarEnvio(ctx context.Context, tenant string, conductor int64) error {
	return p.mem.Cliente.Del(ctx, memoria.ClaveEnvio(tenant, conductor)).Err()
}

// TocaLatido usa la propia clave como acelerador: solo el primero en crearla dentro de la ventana
// se lleva el true, así que sale un latido por conductor por minuto (RN-04) aunque manden cien
// posiciones y aunque corran varias instancias del servicio.
func (p *Presencia) TocaLatido(ctx context.Context, tenant string, conductor int64) bool {
	creada, err := p.mem.Cliente.SetNX(ctx, memoria.ClaveLatido(tenant, conductor), 1, p.latidoCada).Result()

	return err == nil && creada
}
