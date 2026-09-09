// Package tanda acumula el recorrido y lo suelta por bloques (SPEC-028, RN-09).
//
// El servicio no escribe en MySQL (RN-20). Lo que hace es juntar los puntos de cada conductor y
// mandarlos al buzón cada 10 segundos o cada 50 puntos, para que Laravel los inserte de una sola
// vez en lugar de cincuenta veces.
package tanda

import (
	"context"
	"log/slog"
	"strconv"
	"sync"
	"time"

	"github.com/prosello/gps-service/internal/buzon"
	"github.com/prosello/gps-service/internal/posicion"
)

type bloque struct {
	Tenant    string           `json:"tenant"`
	Conductor int64            `json:"id_conductor"`
	Pedido    string           `json:"id_pedido"`
	Puntos    []posicion.Punto `json:"puntos"`
}

type Acumulador struct {
	emisor     *buzon.Emisor
	max        int
	intervalo  time.Duration
	bitacora   *slog.Logger
	mu         sync.Mutex
	pendientes map[string]*bloque
}

func Nuevo(emisor *buzon.Emisor, max int, intervalo time.Duration, bitacora *slog.Logger) *Acumulador {
	return &Acumulador{
		emisor:     emisor,
		max:        max,
		intervalo:  intervalo,
		bitacora:   bitacora,
		pendientes: make(map[string]*bloque),
	}
}

// Agregar suma un punto a la tanda del conductor y la suelta si ya llegó al tope.
//
// Si el conductor cambió de pedido sin que llegara el ENVIO_TERMINADO (una reasignación, por
// ejemplo), se cierra la tanda anterior antes de abrir la nueva: mezclar puntos de dos envíos en
// un mismo bloque dejaría la polilínea de SPEC-026 cruzada entre pedidos.
func (a *Acumulador) Agregar(ctx context.Context, tenant string, conductor int64, pedido string, p posicion.Punto) {
	var listo *bloque

	a.mu.Lock()
	clave := clave(tenant, conductor)
	actual, hay := a.pendientes[clave]

	if hay && actual.Pedido != pedido {
		listo = actual
		hay = false
	}

	if !hay {
		actual = &bloque{Tenant: tenant, Conductor: conductor, Pedido: pedido}
		a.pendientes[clave] = actual
	}

	actual.Puntos = append(actual.Puntos, p)

	var lleno *bloque
	if len(actual.Puntos) >= a.max {
		lleno = actual
		delete(a.pendientes, clave)
	}
	a.mu.Unlock()

	// Fuera del candado: publicar es una llamada de red, y sostener el candado mientras tanto
	// frenaría a todos los demás conductores.
	a.publicar(ctx, listo)
	a.publicar(ctx, lleno)
}

// Cerrar suelta lo que quede del conductor. Lo llama el ENVIO_TERMINADO para que los últimos
// metros del recorrido no se queden esperando al reloj.
func (a *Acumulador) Cerrar(ctx context.Context, tenant string, conductor int64) {
	a.mu.Lock()
	clave := clave(tenant, conductor)
	pendiente := a.pendientes[clave]
	delete(a.pendientes, clave)
	a.mu.Unlock()

	a.publicar(ctx, pendiente)
}

// Correr vacía cada intervalo lo que se haya juntado y, al terminar, suelta todo lo pendiente:
// un apagado ordenado no debe costar los últimos diez segundos de trazo de nadie.
func (a *Acumulador) Correr(ctx context.Context) {
	reloj := time.NewTicker(a.intervalo)
	defer reloj.Stop()

	for {
		select {
		case <-ctx.Done():
			// El contexto ya está cancelado; se usa uno nuevo con plazo corto para alcanzar a
			// publicar el último vaciado.
			cierre, cancelar := context.WithTimeout(context.WithoutCancel(ctx), 5*time.Second)
			a.vaciar(cierre)
			cancelar()

			return
		case <-reloj.C:
			a.vaciar(ctx)
		}
	}
}

func (a *Acumulador) vaciar(ctx context.Context) {
	a.mu.Lock()
	bloques := make([]*bloque, 0, len(a.pendientes))
	for clave, b := range a.pendientes {
		bloques = append(bloques, b)
		delete(a.pendientes, clave)
	}
	a.mu.Unlock()

	for _, b := range bloques {
		a.publicar(ctx, b)
	}
}

func (a *Acumulador) publicar(ctx context.Context, b *bloque) {
	if b == nil || len(b.Puntos) == 0 {
		return
	}

	if err := a.emisor.Publicar(ctx, buzon.TipoRecorrido, b); err != nil {
		// Se pierde esta tanda, no el envío: RN-09 acepta explícitamente ese costo antes que
		// bloquear la ingesta esperando a que Redis vuelva.
		a.bitacora.Error("no se pudo publicar el recorrido",
			"tenant", b.Tenant, "conductor", b.Conductor, "puntos", len(b.Puntos), "error", err)
	}
}

func clave(tenant string, conductor int64) string {
	return tenant + "|" + strconv.FormatInt(conductor, 10)
}
