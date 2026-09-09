// Prueba de carga del servicio GPS (SPEC-028, criterio de aceptación 1 y adición técnica 12).
//
// Simula N conductores mandando su posición a la vez y reporta cuántas entraron, cuántas se
// rechazaron y qué latencia se midió. Es la prueba que decide si más adelante vale la pena mover
// también el canal en vivo del Panel a este servicio (§13 de la spec original).
//
// Antes de correrla hay que abrirle envío a los conductores simulados, o el servicio contestará
// 204 sin guardar nada (RN-01) y la medición no valdría:
//
//	redis-cli SET "tenant:cafe-luna:conductor:1:envio" 1 EX 3600
//
//	go run ./cmd/carga -url http://127.0.0.1:8081 -tenant cafe-luna \
//	    -conductores 200 -cada 5s -durante 10m -secreto "$GPS_TOKEN_SECRET"
package main

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"flag"
	"fmt"
	"math/rand"
	"net/http"
	"os"
	"sort"
	"sync"
	"sync/atomic"
	"time"
)

type resultado struct {
	ok        atomic.Int64
	rechazos  atomic.Int64
	errores   atomic.Int64
	mu        sync.Mutex
	latencias []time.Duration
}

func (r *resultado) anotar(d time.Duration) {
	r.mu.Lock()
	r.latencias = append(r.latencias, d)
	r.mu.Unlock()
}

func main() {
	url := flag.String("url", "http://127.0.0.1:8081", "base del servicio")
	tenant := flag.String("tenant", "cafe-luna", "slug del tenant")
	secreto := flag.String("secreto", os.Getenv("GPS_TOKEN_SECRET"), "secreto con el que se firma el permiso")
	conductores := flag.Int("conductores", 200, "conductores simultáneos")
	cada := flag.Duration("cada", 5*time.Second, "cada cuánto manda posición cada conductor")
	durante := flag.Duration("durante", 10*time.Minute, "cuánto dura la prueba")
	flag.Parse()

	if *secreto == "" {
		fmt.Fprintln(os.Stderr, "falta -secreto (o GPS_TOKEN_SECRET)")
		os.Exit(1)
	}

	ctx, cancelar := context.WithTimeout(context.Background(), *durante)
	defer cancelar()

	// Un cliente compartido con el pool bien dimensionado: si cada conductor abriera su propia
	// conexión, lo que mediríamos sería el coste de abrir conexiones, no el del servicio.
	cliente := &http.Client{
		Timeout: 5 * time.Second,
		Transport: &http.Transport{
			MaxIdleConns:        *conductores * 2,
			MaxIdleConnsPerHost: *conductores * 2,
			IdleConnTimeout:     90 * time.Second,
		},
	}

	res := &resultado{}
	var flota sync.WaitGroup

	arranque := time.Now()

	for i := 1; i <= *conductores; i++ {
		flota.Add(1)

		go func(conductor int) {
			defer flota.Done()
			rodar(ctx, cliente, *url, *tenant, *secreto, conductor, *cada, res)
		}(i)
	}

	flota.Wait()
	informar(res, time.Since(arranque), *conductores)
}

func rodar(ctx context.Context, cliente *http.Client, url, tenant, secreto string, conductor int, cada time.Duration, res *resultado) {
	// Arranque escalonado: si los 200 mandaran en el mismo milisegundo, se mediría un pico
	// artificial que ningún teléfono real produce.
	espera := time.Duration(rand.Int63n(int64(cada)))
	select {
	case <-ctx.Done():
		return
	case <-time.After(espera):
	}

	permiso := firmar(tenant, conductor, secreto)
	emitido := time.Now()

	reloj := time.NewTicker(cada)
	defer reloj.Stop()

	lat, lng := 20.5234, -100.8157

	for {
		select {
		case <-ctx.Done():
			return
		case <-reloj.C:
			// El permiso dura 30 minutos: una prueba larga tiene que renovarlo, igual que la App.
			if time.Since(emitido) > 20*time.Minute {
				permiso = firmar(tenant, conductor, secreto)
				emitido = time.Now()
			}

			// Deriva de unos pocos metros por ping, para que las posiciones cambien de verdad y el
			// índice geoespacial trabaje.
			lat += (rand.Float64() - 0.5) / 2000
			lng += (rand.Float64() - 0.5) / 2000

			mandar(ctx, cliente, url, permiso, lat, lng, res)
		}
	}
}

func mandar(ctx context.Context, cliente *http.Client, url, permiso string, lat, lng float64, res *resultado) {
	cuerpo, _ := json.Marshal(map[string]any{
		"latitud":        lat,
		"longitud":       lng,
		"velocidad":      34.5,
		"rumbo":          118,
		"precision":      12,
		"fecha_posicion": time.Now().UTC().Format(time.RFC3339),
	})

	peticion, err := http.NewRequestWithContext(ctx, http.MethodPost, url+"/gps/v1/ping", bytes.NewReader(cuerpo))
	if err != nil {
		res.errores.Add(1)

		return
	}

	peticion.Header.Set("Authorization", "Bearer "+permiso)
	peticion.Header.Set("Content-Type", "application/json")

	inicio := time.Now()
	respuesta, err := cliente.Do(peticion)
	tardanza := time.Since(inicio)

	if err != nil {
		res.errores.Add(1)

		return
	}
	respuesta.Body.Close()

	res.anotar(tardanza)

	switch {
	case respuesta.StatusCode == http.StatusNoContent:
		res.ok.Add(1)
	default:
		res.rechazos.Add(1)
	}
}

func informar(res *resultado, duracion time.Duration, conductores int) {
	res.mu.Lock()
	latencias := res.latencias
	res.mu.Unlock()

	sort.Slice(latencias, func(i, j int) bool { return latencias[i] < latencias[j] })

	fmt.Printf("\nconductores:  %d\n", conductores)
	fmt.Printf("duración:     %s\n", duracion.Round(time.Second))
	fmt.Printf("aceptadas:    %d\n", res.ok.Load())
	fmt.Printf("rechazadas:   %d\n", res.rechazos.Load())
	fmt.Printf("errores red:  %d\n", res.errores.Load())

	if len(latencias) == 0 {
		return
	}

	fmt.Printf("p50:          %s\n", latencias[len(latencias)*50/100].Round(time.Microsecond))
	fmt.Printf("p95:          %s\n", latencias[len(latencias)*95/100].Round(time.Microsecond))
	// El criterio de aceptación 1 se lee aquí: el p99 tiene que quedar por debajo de 50 ms.
	fmt.Printf("p99:          %s\n", latencias[len(latencias)*99/100].Round(time.Microsecond))
	fmt.Printf("máx:          %s\n", latencias[len(latencias)-1].Round(time.Microsecond))
}

// Mismo formato que emite Laravel (`App\Support\TokenGps`): HS256, base64url sin relleno.
func firmar(tenant string, conductor int, secreto string) string {
	ahora := time.Now()

	codificar := func(v any) string {
		crudo, _ := json.Marshal(v)

		return base64.RawURLEncoding.EncodeToString(crudo)
	}

	cabecera := codificar(map[string]string{"alg": "HS256", "typ": "JWT"})
	cuerpo := codificar(map[string]any{
		"jti":    fmt.Sprintf("carga-%d-%d", conductor, ahora.UnixNano()),
		"tenant": tenant,
		"sub":    conductor,
		"iat":    ahora.Unix(),
		"exp":    ahora.Add(30 * time.Minute).Unix(),
	})

	mac := hmac.New(sha256.New, []byte(secreto))
	mac.Write([]byte(cabecera + "." + cuerpo))

	return cabecera + "." + cuerpo + "." + base64.RawURLEncoding.EncodeToString(mac.Sum(nil))
}
