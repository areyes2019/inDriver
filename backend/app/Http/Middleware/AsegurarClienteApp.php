<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant\Cliente;
use App\Models\Tenant\ConfiguracionTenant;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Va después de `auth:cliente-token` en las rutas de la app de clientes (spec tenant/035).
 *
 * - RN-14: exige un token real (no una sesión) de un `Cliente` con la habilidad `cliente`.
 * - RN-03 y RN-04: un cliente que ya no está `Activo` pierde su token.
 * - RN-01: con la app apagada en la flotilla, nadie pasa.
 */
class AsegurarClienteApp
{
    public function handle(Request $request, Closure $next): Response
    {
        $cliente = $request->user('cliente-token');
        $token = $cliente instanceof Cliente ? $cliente->currentAccessToken() : null;

        if (! $token instanceof PersonalAccessToken || ! $token->can(Cliente::HABILIDAD_APP)) {
            throw new AuthenticationException;
        }

        if ($cliente->estado !== 'Activo') {
            $token->delete();

            return response()->json(['motivo' => 'CLIENTE_INACTIVO'], 403);
        }

        if (! ConfiguracionTenant::appClientesPrendida()) {
            return response()->json(['motivo' => 'NO_DISPONIBLE'], 403);
        }

        return $next($request);
    }
}
