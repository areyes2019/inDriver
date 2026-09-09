// Package permiso verifica el permiso GPS que emite Laravel (SPEC-028, §5.3).
//
// Es un JWT HS256 minúsculo y se verifica con la librería estándar a propósito: son treinta líneas
// de HMAC, y así el servicio no arrastra una dependencia de terceros en el único punto por el que
// pasa toda la seguridad.
package permiso

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"strconv"
	"strings"
	"time"
)

var (
	ErrFormato   = errors.New("permiso mal formado")
	ErrAlgoritmo = errors.New("algoritmo no soportado")
	ErrFirma     = errors.New("firma inválida")
	ErrCaducado  = errors.New("permiso caducado")
	ErrClaims    = errors.New("permiso incompleto")
)

// Permiso son los datos ya verificados. El tenant y el conductor salen SIEMPRE de aquí, nunca del
// cuerpo de la petición (RN-14, RN-15).
type Permiso struct {
	JTI       string
	Tenant    string
	Conductor int64
	// Emitido es lo que compara la cancelación: se revoca "todo lo emitido antes de tal instante",
	// no un permiso concreto (SPEC-028, RN-10).
	Emitido time.Time
	Expira  time.Time
}

type cabecera struct {
	Alg string `json:"alg"`
	Typ string `json:"typ"`
}

// Los claims viajan como los escribe Laravel. `sub` se acepta como número o como cadena porque
// json_encode de PHP no garantiza el tipo cuando el id viaja en un array asociativo.
type claims struct {
	JTI    string      `json:"jti"`
	Tenant string      `json:"tenant"`
	Sub    json.Number `json:"sub"`
	Iat    int64       `json:"iat"`
	Exp    int64       `json:"exp"`
}

type Verificador struct {
	secreto []byte
	ahora   func() time.Time
}

func NuevoVerificador(secreto []byte) *Verificador {
	return &Verificador{secreto: secreto, ahora: time.Now}
}

// Verificar comprueba firma, algoritmo, caducidad y presencia de los claims obligatorios. No
// consulta a nadie: es lo que permite seguir aceptando posiciones con Laravel caído (SPEC-028, §7.7).
func (v *Verificador) Verificar(token string) (Permiso, error) {
	partes := strings.Split(token, ".")
	if len(partes) != 3 {
		return Permiso{}, ErrFormato
	}

	crudoCabecera, err := decodificar(partes[0])
	if err != nil {
		return Permiso{}, ErrFormato
	}

	var cab cabecera
	if err := json.Unmarshal(crudoCabecera, &cab); err != nil {
		return Permiso{}, ErrFormato
	}

	// Sin esta comprobación, un token con alg "none" pasaría con firma vacía.
	if cab.Alg != "HS256" {
		return Permiso{}, ErrAlgoritmo
	}

	firma, err := decodificar(partes[2])
	if err != nil {
		return Permiso{}, ErrFormato
	}

	mac := hmac.New(sha256.New, v.secreto)
	mac.Write([]byte(partes[0] + "." + partes[1]))

	// Comparación en tiempo constante: un `bytes.Equal` filtraría la firma byte a byte.
	if !hmac.Equal(firma, mac.Sum(nil)) {
		return Permiso{}, ErrFirma
	}

	crudoClaims, err := decodificar(partes[1])
	if err != nil {
		return Permiso{}, ErrFormato
	}

	var c claims
	if err := json.Unmarshal(crudoClaims, &c); err != nil {
		return Permiso{}, ErrFormato
	}

	if c.JTI == "" || c.Tenant == "" || c.Sub == "" || c.Exp == 0 || c.Iat == 0 {
		return Permiso{}, ErrClaims
	}

	conductor, err := strconv.ParseInt(strings.Trim(c.Sub.String(), `"`), 10, 64)
	if err != nil || conductor <= 0 {
		return Permiso{}, ErrClaims
	}

	expira := time.Unix(c.Exp, 0)
	if !v.ahora().Before(expira) {
		return Permiso{}, ErrCaducado
	}

	return Permiso{
		JTI:       c.JTI,
		Tenant:    c.Tenant,
		Conductor: conductor,
		Emitido:   time.Unix(c.Iat, 0),
		Expira:    expira,
	}, nil
}

// JWT usa base64url sin relleno, pero se acepta también con relleno por si el emisor cambia.
func decodificar(s string) ([]byte, error) {
	if b, err := base64.RawURLEncoding.DecodeString(s); err == nil {
		return b, nil
	}

	return base64.URLEncoding.DecodeString(s)
}

// DelEncabezado extrae el token de "Authorization: Bearer …".
func DelEncabezado(valor string) string {
	const prefijo = "Bearer "
	if len(valor) <= len(prefijo) || !strings.EqualFold(valor[:len(prefijo)], prefijo) {
		return ""
	}

	return strings.TrimSpace(valor[len(prefijo):])
}
