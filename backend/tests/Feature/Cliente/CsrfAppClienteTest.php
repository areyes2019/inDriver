<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;

/*
| spec tenant/035: la app vive en el mismo dominio que el Panel. Sus rutas se autentican con token
| Bearer y no deben pedir el token CSRF, aunque la petición llegue desde el sitio.
|
| Laravel no aplica el CSRF mientras corren las pruebas, así que una petición de prueba no lo
| demostraría: se pregunta directo al middleware qué rutas exceptúa.
*/

function csrfExceptua(string $uri): bool
{
    $middleware = app(ValidateCsrfToken::class);
    $metodo = new ReflectionMethod($middleware, 'inExceptArray');

    return $metodo->invoke($middleware, Request::create($uri, 'POST'));
}

it('exempts the client app routes from CSRF', function () {
    expect(csrfExceptua('/api/v1/t/cafe-luna/cliente/codigo'))->toBeTrue()
        ->and(csrfExceptua('/api/v1/t/cafe-luna/cliente/verificar'))->toBeTrue()
        ->and(csrfExceptua('/api/v1/t/cafe-luna/cliente/salir'))->toBeTrue();
});

it('keeps CSRF on the Panel routes', function () {
    expect(csrfExceptua('/api/v1/t/cafe-luna/login'))->toBeFalse()
        ->and(csrfExceptua('/api/v1/t/cafe-luna/clientes'))->toBeFalse()
        ->and(csrfExceptua('/api/v1/t/cafe-luna/clientes/unificar'))->toBeFalse();
});
