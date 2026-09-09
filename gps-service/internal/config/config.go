// Package config carga la configuración desde el entorno. Todo tiene un valor por defecto
// razonable salvo los dos secretos, que sin valor impiden arrancar: un servicio GPS sin secreto
// aceptaría cualquier permiso.
package config

import (
	"errors"
	"os"
	"strconv"
	"time"
)

type Config struct {
	Direccion string // dirección de escucha, p. ej. "127.0.0.1:8081"

	RedisAddr     string
	RedisPassword string
	RedisDB       int

	// Secreto con el que Laravel firma los permisos GPS (SPEC-028, §5.3).
	SecretoPermiso []byte
	// Token fijo con el que Laravel llama a /gps/v1/nearby (SPEC-028, §6.1).
	TokenServicio string

	PresenciaTTL   time.Duration // RN-06: 3 min
	EnvioTTL       time.Duration // vida de la marca de envío en curso
	CanceladoTTL   time.Duration // RN-07: lo que dura el permiso
	LatidoCada     time.Duration // RN-04: 60 s por conductor
	TandaIntervalo time.Duration // RN-09: 10 s
	TandaMax       int           // RN-09: 50 puntos

	LimitePorMinuto int   // RN-12
	CuerpoMax       int64 // RN-12, en bytes

	StreamHaciaLaravel  string
	StreamHaciaServicio string
	GrupoServicio       string
	Consumidor          string
}

func Cargar() (Config, error) {
	secreto := os.Getenv("GPS_TOKEN_SECRET")
	if secreto == "" {
		return Config{}, errors.New("falta GPS_TOKEN_SECRET")
	}

	token := os.Getenv("GPS_SERVICE_TOKEN")
	if token == "" {
		return Config{}, errors.New("falta GPS_SERVICE_TOKEN")
	}

	anfitrion, err := os.Hostname()
	if err != nil || anfitrion == "" {
		anfitrion = "gps-1"
	}

	return Config{
		Direccion:     texto("GPS_LISTEN", "127.0.0.1:8081"),
		RedisAddr:     texto("REDIS_HOST", "127.0.0.1") + ":" + texto("REDIS_PORT", "6379"),
		RedisPassword: os.Getenv("REDIS_PASSWORD"),
		RedisDB:       entero("REDIS_DB", 0),

		SecretoPermiso: []byte(secreto),
		TokenServicio:  token,

		PresenciaTTL:   duracion("GPS_PRESENCIA_TTL", 3*time.Minute),
		EnvioTTL:       duracion("GPS_ENVIO_TTL", 12*time.Hour),
		CanceladoTTL:   duracion("GPS_CANCELADO_TTL", 30*time.Minute),
		LatidoCada:     duracion("GPS_LATIDO_CADA", time.Minute),
		TandaIntervalo: duracion("GPS_TANDA_INTERVALO", 10*time.Second),
		TandaMax:       entero("GPS_TANDA_MAX", 50),

		LimitePorMinuto: entero("GPS_LIMITE_POR_MINUTO", 30),
		CuerpoMax:       int64(entero("GPS_CUERPO_MAX", 2048)),

		StreamHaciaLaravel:  texto("GPS_STREAM_HACIA_LARAVEL", "gps:hacia-laravel"),
		StreamHaciaServicio: texto("GPS_STREAM_HACIA_SERVICIO", "gps:hacia-servicio"),
		GrupoServicio:       texto("GPS_GRUPO_SERVICIO", "servicio"),
		Consumidor:          texto("GPS_CONSUMIDOR", anfitrion),
	}, nil
}

func texto(clave, porDefecto string) string {
	if v := os.Getenv(clave); v != "" {
		return v
	}

	return porDefecto
}

func entero(clave string, porDefecto int) int {
	v, err := strconv.Atoi(os.Getenv(clave))
	if err != nil {
		return porDefecto
	}

	return v
}

func duracion(clave string, porDefecto time.Duration) time.Duration {
	d, err := time.ParseDuration(os.Getenv(clave))
	if err != nil {
		return porDefecto
	}

	return d
}
