<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Conductor;

use App\Http\Controllers\Controller;
use App\Services\Gps\PermisoGpsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Emite el permiso con el que la App le manda la posición al microservicio GPS (spec tenant/028,
 * §6.2). Se pide al iniciar sesión y se renueva a los 20 minutos, en segundo plano (RN-07).
 *
 * Autenticado con Sanctum como el resto de la App: el permiso GPS no sustituye al login, se
 * obtiene teniéndolo.
 */
class GpsTokenController extends Controller
{
    public function __construct(private readonly PermisoGpsService $permisos) {}

    public function emitir(Request $request): JsonResponse
    {
        $conductor = $request->user('conductor-token')->conductor;

        $permiso = $this->permisos->emitir($conductor);

        // Integración apagada (sin `GPS_SERVICE_URL`): la App lo lee como "sigue mandando la
        // posición por el endpoint de Laravel" (RN-19), que es el comportamiento actual. No es un
        // error del conductor ni algo que deba reintentar.
        if ($permiso === null) {
            return response()->json(['error' => 'GPS_SERVICE_DISABLED'], 503);
        }

        return response()->json($permiso);
    }
}
