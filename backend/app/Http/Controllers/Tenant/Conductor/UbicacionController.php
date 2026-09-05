<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Conductor;

use App\Events\Tenant\UbicacionActualizada;
use App\Http\Controllers\Controller;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConductorPosicion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UbicacionController extends Controller
{
    /**
     * Cada envío actualiza la posición "actual" en `conductor_estado` (para el mapa del panel) y
     * además deja registro histórico en `conductor_posiciones` (spec tenant/013).
     */
    public function actualizar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitud' => ['required', 'numeric', 'between:-90,90'],
            'longitud' => ['required', 'numeric', 'between:-180,180'],
            'precision' => ['nullable', 'numeric', 'min:0'],
            'velocidad' => ['nullable', 'numeric', 'min:0'],
            'rumbo' => ['nullable', 'integer', 'between:0,359'],
            'bateria' => ['nullable', 'integer', 'between:0,100'],
        ]);

        $conductor = $request->user('conductor-token')->conductor;

        ConductorEstado::updateOrCreate(
            ['id_conductor' => $conductor->id_conductor],
            [
                'ultima_latitud' => $data['latitud'],
                'ultima_longitud' => $data['longitud'],
                'ultima_actualizacion' => now(),
            ],
        );

        ConductorPosicion::create([
            'id_conductor' => $conductor->id_conductor,
            'latitud' => $data['latitud'],
            'longitud' => $data['longitud'],
            'precision' => $data['precision'] ?? null,
            'velocidad' => $data['velocidad'] ?? null,
            'rumbo' => $data['rumbo'] ?? null,
            'bateria' => $data['bateria'] ?? null,
            'fecha_posicion' => now(),
        ]);

        // Tiempo real (spec tenant/018): el Panel ve el punto moverse en el mapa sin recargar. Es
        // de alta frecuencia (RN-05) — solo socket, sin respaldo de push.
        if ($slug = tenant()?->slug) {
            UbicacionActualizada::dispatch($conductor->id_conductor, $data['latitud'], $data['longitud'], $slug);
        }

        return response()->json(status: 204);
    }
}
