<?php

use App\Http\Middleware\AsegurarRolTenant;
use App\Http\Middleware\IdentificarTenantPorSlug;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'tenant.slug' => IdentificarTenantPorSlug::class,
            'rol.tenant' => AsegurarRolTenant::class,
        ]);

        // Laravel siempre corre los middleware de tipo "auth" muy temprano (lista de prioridad
        // interna), sin importar en qué grupo de rutas se declaren. Sin este ajuste, "auth:usuario"
        // podría ejecutarse antes que "tenant.slug" y cargaría al usuario contra la base de datos
        // equivocada (la que estuviera activa antes de resolver el tenant).
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: IdentificarTenantPorSlug::class,
        );

        // API pura: no hay ruta 'login' (sin Blade/Inertia), así que las peticiones sin sesión
        // deben recibir un 401 JSON, nunca un redirect.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
