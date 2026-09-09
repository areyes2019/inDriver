// Package web expone los tres endpoints del servicio (SPEC-028, §6.1).
package web

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"strconv"
	"sync"
	"time"

	"github.com/prosello/gps-service/internal/buzon"
	"github.com/prosello/gps-service/internal/cercania"
	"github.com/prosello/gps-service/internal/config"
	"github.com/prosello/gps-service/internal/memoria"
	"github.com/prosello/gps-service/internal/permiso"
	"github.com/prosello/gps-service/internal/posicion"
	"github.com/prosello/gps-service/internal/presencia"
	"github.com/prosello/gps-service/internal/tanda"
)

type Servidor struct {
	cfg        config.Config
	mem        *memoria.Memoria
	verificar  *permiso.Verificador
	presencia  *presencia.Presencia
	almacen    *posicion.Almacen
	buscador   *cercania.Buscador
	emisor     *buzon.Emisor
	acumulador *tanda.Acumulador
	freno      *freno
	bitacora   *slog.Logger
}

func Nuevo(
	cfg config.Config,
	mem *memoria.Memoria,
	pre *presencia.Presencia,
	almacen *posicion.Almacen,
	buscador *cercania.Buscador,
	emisor *buzon.Emisor,
	acumulador *tanda.Acumulador,
	bitacora *slog.Logger,
) *Servidor {
	return &Servidor{
		cfg:        cfg,
		mem:        mem,
		verificar:  permiso.NuevoVerificador(cfg.SecretoPermiso),
		presencia:  pre,
		almacen:    almacen,
		buscador:   buscador,
		emisor:     emisor,
		acumulador: acumulador,
		freno:      nuevoFreno(cfg.LimitePorMinuto),
		bitacora:   bitacora,
	}
}

func (s *Servidor) Rutas() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", s.salud)
	mux.HandleFunc("POST /gps/v1/ping", s.ping)
	mux.HandleFunc("GET /gps/v1/nearby", s.cercanos)

	return mux
}

// Barrer limpia el contador del freno. Lo llama main con su propio reloj para no dejar creciendo
// un mapa con un contador por conductor que ya no vuelve.
func (s *Servidor) Barrer() {
	s.freno.barrer()
}

// salud no pide permiso (SPEC-028, §6.1) y no revela versiones ni configuración: solo si el
// servicio responde y si la memoria contesta.
func (s *Servidor) salud(w http.ResponseWriter, r *http.Request) {
	if err := s.mem.Disponible(r.Context()); err != nil {
		escribirJSON(w, http.StatusServiceUnavailable, map[string]string{"estado": "sin memoria"})

		return
	}

	escribirJSON(w, http.StatusOK, map[string]string{"estado": "ok"})
}

func (s *Servidor) ping(w http.ResponseWriter, r *http.Request) {
	p, ok := s.autorizar(w, r)
	if !ok {
		return
	}

	if !s.freno.permite(p.Tenant, p.Conductor) {
		w.Header().Set("Retry-After", "60")
		escribirJSON(w, http.StatusTooManyRequests, map[string]string{"error": "demasiadas posiciones"})

		return
	}

	r.Body = http.MaxBytesReader(w, r.Body, s.cfg.CuerpoMax)

	var entrada posicion.Entrada
	if err := json.NewDecoder(r.Body).Decode(&entrada); err != nil {
		var excedido *http.MaxBytesError
		if errors.As(err, &excedido) {
			escribirJSON(w, http.StatusRequestEntityTooLarge, map[string]string{"error": "cuerpo demasiado grande"})

			return
		}

		escribirJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "cuerpo inválido"})

		return
	}

	ctx := r.Context()

	// RN-01: sin envío en curso no hay rastreo. Se responde 204 igual que si se hubiera guardado
	// —para la App no es un error— y la posición solo cuenta como señal de vida.
	pedido, err := s.presencia.EnvioActivo(ctx, p.Tenant, p.Conductor)
	if err != nil {
		s.sinMemoria(w, "envio_activo", err)

		return
	}

	if pedido == "" {
		s.latir(ctx, p.Tenant, p.Conductor)
		w.WriteHeader(http.StatusNoContent)

		return
	}

	punto, err := entrada.Validar(time.Now())
	if err != nil {
		escribirJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": err.Error()})

		return
	}

	escrito, err := s.almacen.Guardar(ctx, p.Tenant, p.Conductor, punto)
	if err != nil {
		s.sinMemoria(w, "guardar_posicion", err)

		return
	}

	// RN-11: llegó más vieja que la que ya teníamos. No es un error del teléfono, es mala señal.
	if !escrito {
		w.WriteHeader(http.StatusNoContent)

		return
	}

	s.difundir(ctx, p.Tenant, p.Conductor, punto)
	s.acumulador.Agregar(ctx, p.Tenant, p.Conductor, pedido, punto)
	s.latir(ctx, p.Tenant, p.Conductor)

	w.WriteHeader(http.StatusNoContent)
}

func (s *Servidor) cercanos(w http.ResponseWriter, r *http.Request) {
	// Comparación en tiempo constante: el token de servicio es fijo y de larga vida.
	dado := permiso.DelEncabezado(r.Header.Get("Authorization"))
	if subtle.ConstantTimeCompare([]byte(dado), []byte(s.cfg.TokenServicio)) != 1 {
		escribirJSON(w, http.StatusUnauthorized, map[string]string{"error": "no autorizado"})

		return
	}

	tenant := r.Header.Get("X-Tenant")
	if tenant == "" {
		escribirJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "falta X-Tenant"})

		return
	}

	lat, errLat := strconv.ParseFloat(r.URL.Query().Get("lat"), 64)
	lng, errLng := strconv.ParseFloat(r.URL.Query().Get("lng"), 64)
	if errLat != nil || errLng != nil {
		escribirJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "lat y lng son obligatorios"})

		return
	}

	vecinos, err := s.buscador.Buscar(
		r.Context(), tenant, lat, lng,
		decimalConsulta(r, "radio_km", 3, 0.1, 100),
		int(decimalConsulta(r, "limite", 10, 1, 100)),
	)
	if err != nil {
		s.sinMemoria(w, "cercanos", err)

		return
	}

	escribirJSON(w, http.StatusOK, map[string]any{"conductores": vecinos})
}

// autorizar aplica los dos primeros pasos de §7.3: firma y cancelación. Devuelve false cuando ya
// escribió la respuesta.
func (s *Servidor) autorizar(w http.ResponseWriter, r *http.Request) (permiso.Permiso, bool) {
	token := permiso.DelEncabezado(r.Header.Get("Authorization"))
	if token == "" {
		escribirJSON(w, http.StatusUnauthorized, map[string]string{"error": "falta el permiso"})

		return permiso.Permiso{}, false
	}

	p, err := s.verificar.Verificar(token)
	if err != nil {
		// Se registra el motivo, nunca el token (SPEC-028, §9).
		s.bitacora.Info("permiso rechazado", "motivo", err.Error())
		escribirJSON(w, http.StatusUnauthorized, map[string]string{"error": "permiso inválido"})

		return permiso.Permiso{}, false
	}

	if s.presencia.Cancelado(r.Context(), p.Tenant, p.Conductor, p.Emitido) {
		escribirJSON(w, http.StatusUnauthorized, map[string]string{"error": "permiso cancelado"})

		return permiso.Permiso{}, false
	}

	return p, true
}

// difundir y latir no pueden retrasar la respuesta al teléfono, pero tampoco se lanzan en una
// goroutine suelta: publicar en el buzón es una operación de milisegundos y, si falla, lo que se
// pierde es el refresco del Panel, no la posición, que ya quedó guardada.
func (s *Servidor) difundir(ctx context.Context, tenant string, conductor int64, p posicion.Punto) {
	err := s.emisor.Publicar(ctx, buzon.TipoPosicion, map[string]any{
		"tenant":       tenant,
		"id_conductor": conductor,
		"latitud":      p.Latitud,
		"longitud":     p.Longitud,
		"fecha_ms":     p.FechaMs,
	})
	if err != nil {
		s.bitacora.Warn("no se pudo difundir la posición", "tenant", tenant, "conductor", conductor, "error", err)
	}
}

func (s *Servidor) latir(ctx context.Context, tenant string, conductor int64) {
	if !s.presencia.TocaLatido(ctx, tenant, conductor) {
		return
	}

	err := s.emisor.Publicar(ctx, buzon.TipoLatido, map[string]any{
		"tenant":       tenant,
		"id_conductor": conductor,
	})
	if err != nil {
		s.bitacora.Warn("no se pudo publicar el latido", "tenant", tenant, "conductor", conductor, "error", err)
	}
}

func (s *Servidor) sinMemoria(w http.ResponseWriter, operacion string, err error) {
	s.bitacora.Error("memoria no disponible", "operacion", operacion, "error", err)
	escribirJSON(w, http.StatusServiceUnavailable, map[string]string{"error": "sin memoria"})
}

func decimalConsulta(r *http.Request, clave string, porDefecto, minimo, maximo float64) float64 {
	v, err := strconv.ParseFloat(r.URL.Query().Get(clave), 64)
	if err != nil || v < minimo || v > maximo {
		return porDefecto
	}

	return v
}

func escribirJSON(w http.ResponseWriter, estado int, cuerpo any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(estado)
	_ = json.NewEncoder(w).Encode(cuerpo)
}

// freno es el límite de RN-12: una ventana de un minuto por conductor.
//
// Se lleva en memoria y no en Redis a propósito: es una válvula de seguridad contra un teléfono
// enloquecido, y no vale la cuarta parte de un viaje de red en el camino crítico de cada posición.
type freno struct {
	limite int
	mu     sync.Mutex
	conteo map[string]*ventana
}

type ventana struct {
	desde time.Time
	n     int
}

func nuevoFreno(limite int) *freno {
	return &freno{limite: limite, conteo: make(map[string]*ventana)}
}

func (f *freno) permite(tenant string, conductor int64) bool {
	clave := tenant + "|" + strconv.FormatInt(conductor, 10)
	ahora := time.Now()

	f.mu.Lock()
	defer f.mu.Unlock()

	v, hay := f.conteo[clave]
	if !hay || ahora.Sub(v.desde) >= time.Minute {
		f.conteo[clave] = &ventana{desde: ahora, n: 1}

		return true
	}

	if v.n >= f.limite {
		return false
	}

	v.n++

	return true
}

func (f *freno) barrer() {
	ahora := time.Now()

	f.mu.Lock()
	defer f.mu.Unlock()

	for clave, v := range f.conteo {
		if ahora.Sub(v.desde) >= 5*time.Minute {
			delete(f.conteo, clave)
		}
	}
}
