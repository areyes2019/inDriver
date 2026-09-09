package permiso

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"testing"
	"time"
)

var secreto = []byte("secreto-de-pruebas")

func firmar(t *testing.T, cab map[string]any, claims map[string]any, conSecreto []byte) string {
	t.Helper()

	codificar := func(v any) string {
		crudo, err := json.Marshal(v)
		if err != nil {
			t.Fatalf("no se pudo serializar: %v", err)
		}

		return base64.RawURLEncoding.EncodeToString(crudo)
	}

	cuerpo := codificar(cab) + "." + codificar(claims)

	mac := hmac.New(sha256.New, conSecreto)
	mac.Write([]byte(cuerpo))

	return cuerpo + "." + base64.RawURLEncoding.EncodeToString(mac.Sum(nil))
}

func validos() (map[string]any, map[string]any) {
	return map[string]any{"alg": "HS256", "typ": "JWT"},
		map[string]any{
			"jti":    "abc123",
			"tenant": "panda_express",
			"sub":    41,
			"iat":    time.Now().Unix(),
			"exp":    time.Now().Add(30 * time.Minute).Unix(),
		}
}

func TestVerificaUnPermisoValido(t *testing.T) {
	cab, claims := validos()

	p, err := NuevoVerificador(secreto).Verificar(firmar(t, cab, claims, secreto))
	if err != nil {
		t.Fatalf("se esperaba un permiso válido, salió: %v", err)
	}

	if p.Tenant != "panda_express" || p.Conductor != 41 || p.JTI != "abc123" {
		t.Fatalf("claims mal leídos: %+v", p)
	}
}

func TestRechazaFirmaDeOtroSecreto(t *testing.T) {
	cab, claims := validos()

	_, err := NuevoVerificador(secreto).Verificar(firmar(t, cab, claims, []byte("otro-secreto")))
	if !errors.Is(err, ErrFirma) {
		t.Fatalf("se esperaba ErrFirma, salió: %v", err)
	}
}

// El ataque clásico contra JWT: firma vacía y alg "none".
func TestRechazaAlgoritmoNone(t *testing.T) {
	cab, claims := validos()
	cab["alg"] = "none"

	_, err := NuevoVerificador(secreto).Verificar(firmar(t, cab, claims, secreto))
	if !errors.Is(err, ErrAlgoritmo) {
		t.Fatalf("se esperaba ErrAlgoritmo, salió: %v", err)
	}
}

func TestRechazaPermisoCaducado(t *testing.T) {
	cab, claims := validos()
	claims["exp"] = time.Now().Add(-time.Second).Unix()

	_, err := NuevoVerificador(secreto).Verificar(firmar(t, cab, claims, secreto))
	if !errors.Is(err, ErrCaducado) {
		t.Fatalf("se esperaba ErrCaducado, salió: %v", err)
	}
}

// Sin `iat` no se puede saber si el permiso quedó dentro de una cancelación (RN-10).
func TestRechazaPermisoSinFechaDeEmision(t *testing.T) {
	cab, claims := validos()
	delete(claims, "iat")

	_, err := NuevoVerificador(secreto).Verificar(firmar(t, cab, claims, secreto))
	if !errors.Is(err, ErrClaims) {
		t.Fatalf("se esperaba ErrClaims, salió: %v", err)
	}
}

func TestRechazaPermisoSinTenant(t *testing.T) {
	cab, claims := validos()
	delete(claims, "tenant")

	_, err := NuevoVerificador(secreto).Verificar(firmar(t, cab, claims, secreto))
	if !errors.Is(err, ErrClaims) {
		t.Fatalf("se esperaba ErrClaims, salió: %v", err)
	}
}

// PHP puede serializar el id como cadena según cómo se arme el array; ambas formas valen.
func TestAceptaElConductorComoCadena(t *testing.T) {
	cab, claims := validos()
	claims["sub"] = "41"

	p, err := NuevoVerificador(secreto).Verificar(firmar(t, cab, claims, secreto))
	if err != nil || p.Conductor != 41 {
		t.Fatalf("no se leyó el conductor: %+v, %v", p, err)
	}
}

func TestDelEncabezado(t *testing.T) {
	casos := map[string]string{
		"Bearer abc": "abc",
		"bearer abc": "abc",
		"abc":        "",
		"Bearer":     "",
		"":           "",
	}

	for entrada, esperado := range casos {
		if salida := DelEncabezado(entrada); salida != esperado {
			t.Errorf("DelEncabezado(%q) = %q, se esperaba %q", entrada, salida, esperado)
		}
	}
}
