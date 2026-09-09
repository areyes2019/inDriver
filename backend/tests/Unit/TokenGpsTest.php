<?php

use App\Support\TokenGps;

/**
 * spec tenant/028, §5.3 — el permiso GPS.
 *
 * Es el único punto por el que el microservicio decide si acepta o rechaza a alguien, así que las
 * formas de falsificarlo se prueban una por una. El verificador equivalente en Go tiene estas
 * mismas pruebas (`internal/permiso/permiso_test.go`).
 */
const SECRETO = 'secreto-de-pruebas';

function claimsValidos(array $overrides = []): array
{
    return array_merge([
        'jti' => 'abc123',
        'tenant' => 'cafe-luna',
        'sub' => 41,
        'iat' => time(),
        'exp' => time() + 1800,
    ], $overrides);
}

it('firma y vuelve a leer un permiso válido', function () {
    $token = TokenGps::firmar(claimsValidos(), SECRETO);

    $claims = TokenGps::verificar($token, SECRETO);

    expect($claims)->not->toBeNull()
        ->and($claims['tenant'])->toBe('cafe-luna')
        ->and($claims['sub'])->toBe(41);
});

it('produce las tres partes de un JWT en base64url', function () {
    $token = TokenGps::firmar(claimsValidos(), SECRETO);

    expect(explode('.', $token))->toHaveCount(3)
        // base64url no usa "+", "/" ni relleno: el permiso viaja en una URL.
        ->and($token)->not->toContain('+')
        ->and($token)->not->toContain('/')
        ->and($token)->not->toContain('=');
});

it('rechaza un permiso firmado con otro secreto', function () {
    $token = TokenGps::firmar(claimsValidos(), 'otro-secreto');

    expect(TokenGps::verificar($token, SECRETO))->toBeNull();
});

it('rechaza un permiso al que le cambiaron el conductor', function () {
    $token = TokenGps::firmar(claimsValidos(), SECRETO);

    [$cabecera, , $firma] = explode('.', $token);
    $cuerpoFalso = rtrim(strtr(base64_encode(json_encode(claimsValidos(['sub' => 99]))), '+/', '-_'), '=');

    expect(TokenGps::verificar("{$cabecera}.{$cuerpoFalso}.{$firma}", SECRETO))->toBeNull();
});

it('rechaza el ataque de algoritmo none', function () {
    $cabecera = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $cuerpo = rtrim(strtr(base64_encode(json_encode(claimsValidos())), '+/', '-_'), '=');

    expect(TokenGps::verificar("{$cabecera}.{$cuerpo}.", SECRETO))->toBeNull();
});

it('rechaza un permiso caducado', function () {
    $token = TokenGps::firmar(claimsValidos(['exp' => time() - 1]), SECRETO);

    expect(TokenGps::verificar($token, SECRETO))->toBeNull();
});

it('rechaza cualquier cosa que no tenga tres partes', function () {
    expect(TokenGps::verificar('no-es-un-token', SECRETO))->toBeNull()
        ->and(TokenGps::verificar('a.b', SECRETO))->toBeNull()
        ->and(TokenGps::verificar('', SECRETO))->toBeNull();
});

it('genera identificadores distintos para cada permiso', function () {
    expect(TokenGps::identificador())->not->toBe(TokenGps::identificador());
});
