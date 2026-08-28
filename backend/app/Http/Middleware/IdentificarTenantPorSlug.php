<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IdentificarTenantPorSlug
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $slug = $route->parameter('slug');

        $tenant = Tenant::where('slug', $slug)->first();

        if (! $tenant) {
            throw new NotFoundHttpException;
        }

        $route->forgetParameter('slug');

        // Sin tenancy()->end() al terminar: a diferencia de un queue worker (que reutiliza el
        // mismo proceso PHP para muchos jobs, como CrearAdminClienteInicial), cada petición HTTP
        // aquí arranca el framework desde cero, así que no hay estado de tenencia que arrastrar
        // a la siguiente petición.
        tenancy()->initialize($tenant);

        return $next($request);
    }
}
