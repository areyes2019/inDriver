// Package memoria concentra la conexión con Redis y la construcción de claves.
//
// Ninguna clave se arma fuera de aquí: es la forma de garantizar que todas llevan el tenant por
// delante (SPEC-028, RN-14). Si una clave nueva no cabe en este archivo, es que está mal pensada.
package memoria

import (
	"context"
	"strconv"
	"time"

	"github.com/redis/go-redis/v9"
)

type Memoria struct {
	Cliente *redis.Client
}

func Conectar(addr, password string, db int) *Memoria {
	return &Memoria{Cliente: redis.NewClient(&redis.Options{
		Addr:     addr,
		Password: password,
		DB:       db,

		// go-redis reconecta solo; estos límites evitan que una caída de Redis deje goroutines
		// colgadas esperando para siempre (SPEC-028, §7.7).
		DialTimeout:     2 * time.Second,
		ReadTimeout:     2 * time.Second,
		WriteTimeout:    2 * time.Second,
		PoolSize:        50,
		MinIdleConns:    5,
		MaxRetries:      3,
		MinRetryBackoff: 20 * time.Millisecond,
		MaxRetryBackoff: 500 * time.Millisecond,
	})}
}

func (m *Memoria) Disponible(ctx context.Context) error {
	ctx, cancelar := context.WithTimeout(ctx, time.Second)
	defer cancelar()

	return m.Cliente.Ping(ctx).Err()
}

func (m *Memoria) Cerrar() error {
	return m.Cliente.Close()
}

// ClaveGeo es el índice geoespacial del tenant: un conductor solo puede aparecer dentro del suyo.
func ClaveGeo(tenant string) string {
	return "tenant:" + tenant + ":conductores:geo"
}

// ClavePos guarda la última posición completa. Caduca a los 3 min (RN-06).
func ClavePos(tenant string, conductor int64) string {
	return conductorPrefijo(tenant, conductor) + ":pos"
}

// ClaveEnvio marca que el conductor tiene un envío en curso, según se lo avisó Laravel (RN-02).
func ClaveEnvio(tenant string, conductor int64) string {
	return conductorPrefijo(tenant, conductor) + ":envio"
}

// ClaveLatido es el acelerador del latido: mientras exista, no se manda otro (RN-04).
func ClaveLatido(tenant string, conductor int64) string {
	return conductorPrefijo(tenant, conductor) + ":latido"
}

// ClaveCancelado guarda el instante desde el cual los permisos del conductor dejan de valer. Se
// revoca por conductor y no por permiso porque un conductor puede tener varios vivos a la vez
// (SPEC-028, RN-10).
func ClaveCancelado(tenant string, conductor int64) string {
	return conductorPrefijo(tenant, conductor) + ":cancelado-desde"
}

// MiembroGeo es cómo se identifica al conductor dentro del índice geoespacial.
func MiembroGeo(conductor int64) string {
	return strconv.FormatInt(conductor, 10)
}

func conductorPrefijo(tenant string, conductor int64) string {
	return "tenant:" + tenant + ":conductor:" + strconv.FormatInt(conductor, 10)
}
