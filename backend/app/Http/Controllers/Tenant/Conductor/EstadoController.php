<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Conductor;

use App\Http\Controllers\Controller;
use App\Services\DisponibilidadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EstadoController extends Controller
{
    public function __construct(private readonly DisponibilidadService $disponibilidad) {}

    /**
     * Conectar/desconectar (spec tenant/013, tenant/019): la app solo maneja ONLINE/OFFLINE, nunca
     * los otros valores del enum de `conductor_estado.estado` (DISPONIBLE, OCUPADO, DESCANSO,
     * FUERA_DE_SERVICIO), que quedan fuera de su alcance. Este mismo cambio sincroniza
     * `conductores.disponibilidad` (ONLINE → DISPONIBLE, OFFLINE → FUERA_DE_SERVICIO): es el
     * conductor quien decide su disponibilidad, no el AdminCliente (spec tenant/003).
     */
    public function actualizar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'estado' => ['required', Rule::in(['ONLINE', 'OFFLINE'])],
        ]);

        $conductor = $request->user('conductor-token')->conductor;

        $estado = $data['estado'] === 'ONLINE'
            ? $this->disponibilidad->conectar($conductor)
            : $this->disponibilidad->desconectar($conductor);

        return response()->json(['estado' => $estado->estado]);
    }
}
