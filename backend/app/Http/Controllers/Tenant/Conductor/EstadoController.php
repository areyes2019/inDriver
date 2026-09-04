<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Conductor;

use App\Http\Controllers\Controller;
use App\Models\Tenant\ConductorEstado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EstadoController extends Controller
{
    /**
     * Conectar/desconectar (spec tenant/013): la app solo maneja ONLINE/OFFLINE, nunca los otros
     * valores del enum de `conductor_estado.estado` (DISPONIBLE, OCUPADO, DESCANSO,
     * FUERA_DE_SERVICIO), que quedan fuera de su alcance.
     */
    public function actualizar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'estado' => ['required', Rule::in(['ONLINE', 'OFFLINE'])],
        ]);

        $conductor = $request->user('conductor-token')->conductor;

        $estado = ConductorEstado::firstOrNew(['id_conductor' => $conductor->id_conductor]);
        $estado->estado = $data['estado'];
        $estado->{$data['estado'] === 'ONLINE' ? 'ultima_conexion' : 'ultima_desconexion'} = now();
        $estado->save();

        return response()->json(['estado' => $estado->estado]);
    }
}
