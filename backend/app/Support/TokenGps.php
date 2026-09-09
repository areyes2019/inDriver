<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * El permiso GPS: un JWT HS256 diminuto que Laravel firma y el microservicio en Go verifica sin
 * consultar a nadie (spec tenant/028, §5.3).
 *
 * No se usa Sanctum para esto. Un token de Sanctum es una fila en `personal_access_tokens`: para
 * validarlo hay que ir a la base del tenant, y entonces una caída de Laravel o de MySQL cortaría
 * la ingesta GPS — justo lo que la spec existe para evitar (RN-18, §7.7). Un permiso firmado se
 * verifica con el secreto y nada más.
 *
 * Tampoco reemplaza a Sanctum: para pedir este permiso hay que estar autenticado con Sanctum, y
 * este permiso no sirve para nada más que mandar la propia posición (RN-19 de las asunciones).
 */
final class TokenGps
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public static function firmar(array $claims, string $secreto): string
    {
        $cabecera = self::base64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $cuerpo = self::base64(json_encode($claims, JSON_THROW_ON_ERROR));

        $firma = hash_hmac('sha256', "{$cabecera}.{$cuerpo}", $secreto, binary: true);

        return "{$cabecera}.{$cuerpo}.".self::base64($firma);
    }

    /**
     * Devuelve los claims si el permiso es válido, o null si no lo es. Existe para las pruebas y
     * para diagnosticar: en producción quien verifica es el servicio en Go.
     *
     * @return array<string, mixed>|null
     */
    public static function verificar(string $token, string $secreto): ?array
    {
        $partes = explode('.', $token);

        if (count($partes) !== 3) {
            return null;
        }

        [$cabecera, $cuerpo, $firma] = $partes;

        $decodificada = json_decode((string) self::desdeBase64($cabecera), true);

        // Un token con `alg: none` y firma vacía pasaría sin esta comprobación.
        if (! is_array($decodificada) || ($decodificada['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $esperada = hash_hmac('sha256', "{$cabecera}.{$cuerpo}", $secreto, binary: true);

        // `hash_equals` compara en tiempo constante: `===` filtraría la firma byte a byte.
        if (! hash_equals($esperada, (string) self::desdeBase64($firma))) {
            return null;
        }

        $claims = json_decode((string) self::desdeBase64($cuerpo), true);

        if (! is_array($claims) || ! isset($claims['exp']) || $claims['exp'] <= time()) {
            return null;
        }

        return $claims;
    }

    /**
     * Identificador único del permiso, lo que permite cancelarlo antes de que caduque (RN-10).
     */
    public static function identificador(): string
    {
        return (string) Str::ulid();
    }

    /**
     * base64url: sin relleno y sin los caracteres que se rompen dentro de una URL, que es como
     * viaja el token cuando la App lo manda por query param.
     */
    private static function base64(string $crudo): string
    {
        return rtrim(strtr(base64_encode($crudo), '+/', '-_'), '=');
    }

    private static function desdeBase64(string $codificado): string|false
    {
        return base64_decode(strtr($codificado, '-_', '+/'), strict: false);
    }
}
