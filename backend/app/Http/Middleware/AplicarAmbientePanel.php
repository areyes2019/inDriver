<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant\ConfiguracionTenant;
use App\Support\ContextoAmbiente;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enciende el filtro por ambiente para las peticiones del Panel (spec tenant/025, RN-05).
 *
 * Va colgado solo del grupo `auth:usuario` de `routes/api.php`. Todo lo que no pase por aquí —la
 * app del conductor bajo `auth:conductor-token`, el comando de simulación, los jobs— deja el
 * contexto vacío y por tanto ve todos los envíos, sin excepciones enumeradas en ningún lado.
 *
 * Corre después de `tenant.slug` (ver la lista de prioridad en `bootstrap/app.php`), así que
 * `configuraciones_tenant` ya apunta a la base del tenant correcto.
 */
class AplicarAmbientePanel
{
    public function handle(Request $request, Closure $next): Response
    {
        app(ContextoAmbiente::class)->fijar(
            ConfiguracionTenant::obtener(ConfiguracionTenant::AMBIENTE, ContextoAmbiente::LIVE) ?? ContextoAmbiente::LIVE,
        );

        return $next($request);
    }
}
