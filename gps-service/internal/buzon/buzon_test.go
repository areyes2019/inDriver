package buzon

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"os"
	"testing"

	"github.com/redis/go-redis/v9"

	"github.com/prosello/gps-service/internal/memoria"
)

const (
	streamDePrueba = "gps:prueba-go:buzon"
	grupoDePrueba  = "prueba"
)

// consumidorDePrueba habla con un Redis de verdad, porque lo que se prueba son los comandos del
// protocolo. Se salta si no hay uno a mano. La contraseña, si la hay, sale de REDIS_PASSWORD.
func consumidorDePrueba(t *testing.T) (*Consumidor, *memoria.Memoria) {
	t.Helper()

	mem := memoria.Conectar("127.0.0.1:6379", os.Getenv("REDIS_PASSWORD"), 0)
	ctx := context.Background()

	if err := mem.Disponible(ctx); err != nil {
		mem.Cerrar()
		t.Skipf("sin Redis: %v", err)
	}

	mem.Cliente.Del(ctx, streamDePrueba)
	t.Cleanup(func() {
		mem.Cliente.Del(context.Background(), streamDePrueba)
		mem.Cerrar()
	})

	bitacora := slog.New(slog.NewTextHandler(io.Discard, nil))
	c := NuevoConsumidor(mem, streamDePrueba, grupoDePrueba, "consumidor", bitacora)
	c.asegurarGrupo(ctx)

	return c, mem
}

func publicarYRecibir(t *testing.T, c *Consumidor, mem *memoria.Memoria) string {
	t.Helper()
	ctx := context.Background()

	id, err := mem.Cliente.XAdd(ctx, &redis.XAddArgs{
		Stream: streamDePrueba,
		Values: map[string]any{"tipo": TipoEnvioIniciado, "datos": `{"tenant":"t"}`},
	}).Result()
	if err != nil {
		t.Fatalf("no se pudo publicar: %v", err)
	}

	// Primera entrega, que queda sin confirmar: es lo que pasa cuando el manejador falla.
	_, err = mem.Cliente.XReadGroup(ctx, &redis.XReadGroupArgs{
		Group:    grupoDePrueba,
		Consumer: "consumidor",
		Streams:  []string{streamDePrueba, ">"},
		Block:    -1,
	}).Result()
	if err != nil {
		t.Fatalf("no se pudo leer: %v", err)
	}

	return id
}

func pendientes(t *testing.T, mem *memoria.Memoria) int64 {
	t.Helper()

	resumen, err := mem.Cliente.XPending(context.Background(), streamDePrueba, grupoDePrueba).Result()
	if err != nil {
		t.Fatalf("no se pudo consultar XPENDING: %v", err)
	}

	return resumen.Count
}

func TestReintentaUnAvisoQueQuedoSinConfirmar(t *testing.T) {
	c, mem := consumidorDePrueba(t)
	id := publicarYRecibir(t, c, mem)

	var recibido string
	c.RevisarPendientes(context.Background(), func(_ context.Context, a Aviso) error {
		recibido = a.ID

		return nil
	})

	if recibido != id {
		t.Fatalf("se esperaba reintentar %s, se recibió %q", id, recibido)
	}

	if n := pendientes(t, mem); n != 0 {
		t.Fatalf("el aviso debía quedar confirmado, quedan %d pendientes", n)
	}
}

func TestDejaPendienteUnAvisoQueVuelveAFallar(t *testing.T) {
	c, mem := consumidorDePrueba(t)
	publicarYRecibir(t, c, mem)

	c.RevisarPendientes(context.Background(), func(context.Context, Aviso) error {
		return errors.New("falla")
	})

	if n := pendientes(t, mem); n != 1 {
		t.Fatalf("el aviso debía seguir pendiente, hay %d", n)
	}
}

func TestDescartaUnAvisoQueAgotoSusReintentos(t *testing.T) {
	c, mem := consumidorDePrueba(t)
	publicarYRecibir(t, c, mem)

	llamadas := 0
	falla := func(context.Context, Aviso) error {
		llamadas++

		return errors.New("falla")
	}

	for i := 0; i < MaxEntregas+2; i++ {
		c.RevisarPendientes(context.Background(), falla)
	}

	if n := pendientes(t, mem); n != 0 {
		t.Fatalf("el aviso debía descartarse, quedan %d pendientes", n)
	}

	// Una entrega fue la inicial, así que quedan MaxEntregas-1 reintentos.
	if llamadas != MaxEntregas-1 {
		t.Fatalf("se esperaban %d reintentos, hubo %d", MaxEntregas-1, llamadas)
	}
}

func TestSoportaUnPendienteQueLaPodaYaBorro(t *testing.T) {
	c, mem := consumidorDePrueba(t)
	id := publicarYRecibir(t, c, mem)

	if err := mem.Cliente.XDel(context.Background(), streamDePrueba, id).Err(); err != nil {
		t.Fatalf("no se pudo borrar: %v", err)
	}

	var tipo = "sin llamar"
	c.RevisarPendientes(context.Background(), func(_ context.Context, a Aviso) error {
		tipo = a.Tipo

		return nil
	})

	if tipo != "" {
		t.Fatalf("se esperaba un aviso vacío, se recibió %q", tipo)
	}

	if n := pendientes(t, mem); n != 0 {
		t.Fatalf("debía quedar confirmado, quedan %d pendientes", n)
	}
}

func TestNoLeHaceNadaAUnBuzonSinPendientes(t *testing.T) {
	c, _ := consumidorDePrueba(t)

	c.RevisarPendientes(context.Background(), func(context.Context, Aviso) error {
		t.Fatal("no había nada que reintentar")

		return nil
	})
}
