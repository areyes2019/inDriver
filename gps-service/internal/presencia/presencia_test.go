package presencia

import (
	"context"
	"os"
	"testing"
	"time"

	"github.com/prosello/gps-service/internal/memoria"
)

const tenantDePrueba = "prueba-go"

func presenciaDePrueba(t *testing.T) *Presencia {
	t.Helper()

	mem := memoria.Conectar("127.0.0.1:6379", os.Getenv("REDIS_PASSWORD"), 0)

	if err := mem.Disponible(context.Background()); err != nil {
		mem.Cerrar()
		t.Skipf("sin Redis: %v", err)
	}

	t.Cleanup(func() {
		mem.Cliente.Del(context.Background(), memoria.ClaveEnvio(tenantDePrueba, 1))
		mem.Cerrar()
	})

	return Nueva(mem, time.Minute, time.Minute)
}

func TestCierraElEnvioDelPedidoCorrecto(t *testing.T) {
	p := presenciaDePrueba(t)
	ctx := context.Background()

	if err := p.AbrirEnvio(ctx, tenantDePrueba, 1, "500"); err != nil {
		t.Fatal(err)
	}

	cerrado, err := p.CerrarEnvio(ctx, tenantDePrueba, 1, "500")
	if err != nil {
		t.Fatal(err)
	}

	activo, _ := p.EnvioActivo(ctx, tenantDePrueba, 1)
	if !cerrado || activo != "" {
		t.Fatalf("debía cerrarse: cerrado=%v activo=%q", cerrado, activo)
	}
}

func TestUnAvisoViejoNoCierraElEnvioSiguiente(t *testing.T) {
	p := presenciaDePrueba(t)
	ctx := context.Background()

	if err := p.AbrirEnvio(ctx, tenantDePrueba, 1, "501"); err != nil {
		t.Fatal(err)
	}

	cerrado, err := p.CerrarEnvio(ctx, tenantDePrueba, 1, "500")
	if err != nil {
		t.Fatal(err)
	}

	activo, _ := p.EnvioActivo(ctx, tenantDePrueba, 1)
	if cerrado || activo != "501" {
		t.Fatalf("no debía cerrarse: cerrado=%v activo=%q", cerrado, activo)
	}
}

func TestCerrarSinMarcaNoEsUnError(t *testing.T) {
	p := presenciaDePrueba(t)

	cerrado, err := p.CerrarEnvio(context.Background(), tenantDePrueba, 1, "500")
	if err != nil {
		t.Fatal(err)
	}

	if !cerrado {
		t.Fatal("sin marca no hay nada que proteger: se da por cerrado")
	}
}
