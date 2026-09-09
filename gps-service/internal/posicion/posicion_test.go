package posicion

import (
	"errors"
	"testing"
	"time"
)

func TestValidaUnaLecturaNormal(t *testing.T) {
	ahora := time.Date(2026, 9, 8, 10, 14, 22, 0, time.UTC)

	p, err := Entrada{
		Latitud:       20.5248,
		Longitud:      -100.8132,
		FechaPosicion: "2026-09-08T10:14:20Z",
	}.Validar(ahora)
	if err != nil {
		t.Fatalf("se esperaba una lectura válida, salió: %v", err)
	}

	if p.FechaMs != time.Date(2026, 9, 8, 10, 14, 20, 0, time.UTC).UnixMilli() {
		t.Fatalf("fecha mal convertida: %d", p.FechaMs)
	}
}

func TestRechazaCoordenadasFueraDeRango(t *testing.T) {
	casos := []Entrada{
		{Latitud: 91, Longitud: 0},
		{Latitud: -91, Longitud: 0},
		{Latitud: 0, Longitud: 181},
		{Latitud: 0, Longitud: -181},
	}

	for _, caso := range casos {
		if _, err := caso.Validar(time.Now()); !errors.Is(err, ErrCoordenadas) {
			t.Errorf("%+v: se esperaba ErrCoordenadas, salió: %v", caso, err)
		}
	}
}

func TestSinFechaSeFechaAlRecibir(t *testing.T) {
	ahora := time.Date(2026, 9, 8, 10, 14, 22, 0, time.UTC)

	p, err := Entrada{Latitud: 20.5, Longitud: -100.8}.Validar(ahora)
	if err != nil {
		t.Fatalf("se esperaba una lectura válida, salió: %v", err)
	}

	if p.FechaMs != ahora.UnixMilli() {
		t.Fatalf("fecha mal asignada: %d", p.FechaMs)
	}
}

// Un teléfono con el reloj adelantado bloquearía todas sus posiciones siguientes por el control de
// orden de RN-11: la fecha se corrige al momento de recepción en vez de descartarla.
func TestCorrigeLaFechaEnElFuturo(t *testing.T) {
	ahora := time.Date(2026, 9, 8, 10, 14, 22, 0, time.UTC)

	p, err := Entrada{
		Latitud:       20.5,
		Longitud:      -100.8,
		FechaPosicion: ahora.Add(2 * time.Hour).Format(time.RFC3339),
	}.Validar(ahora)
	if err != nil {
		t.Fatalf("se esperaba una lectura válida, salió: %v", err)
	}

	if p.FechaMs != ahora.UnixMilli() {
		t.Fatalf("no se corrigió la fecha futura: %d", p.FechaMs)
	}
}

func TestRechazaFechaIlegible(t *testing.T) {
	_, err := Entrada{Latitud: 20.5, Longitud: -100.8, FechaPosicion: "ayer"}.Validar(time.Now())
	if !errors.Is(err, ErrFecha) {
		t.Fatalf("se esperaba ErrFecha, salió: %v", err)
	}
}
