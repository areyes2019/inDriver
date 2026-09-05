<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Conductor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\VentaViajeConductorController;
use App\Http\Resources\Tenant\Conductor\PedidoResource;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Services\PedidoEstadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    /**
     * Fuente de la verdad a la que la app llama al reconectar (spec tenant/018, RN-02/RN-07):
     * junta en una sola respuesta lo que hoy dan por separado /pedidos/activo, /pedidos/disponibles
     * y /saldo-viajes, para que la app descarte cualquier cosa que tenía en pantalla antes de
     * perder la conexión.
     */
    public function show(Request $request): JsonResponse
    {
        $conductor = $request->user('conductor-token')->conductor;

        $pedidoActivo = Pedido::where('id_conductor', $conductor->id_conductor)
            ->whereNotIn('estado', PedidoEstadoService::ESTADOS_FINALES)
            ->first();

        $pedidosDisponibles = Pedido::where('estado', 'PUBLICADO')
            ->whereNull('id_conductor')
            ->orderBy('fecha_publicacion')
            ->get();

        $modalidad = ConfiguracionTenant::obtener(ConfiguracionTenant::MODALIDAD, 'Prepago');
        $saldo = $modalidad === 'Comision' ? null : VentaViajeConductorController::saldoConductor($conductor);

        return response()->json([
            'pedido_activo' => $pedidoActivo ? new PedidoResource($pedidoActivo) : null,
            'pedidos_disponibles' => PedidoResource::collection($pedidosDisponibles),
            'saldo' => $saldo,
        ]);
    }
}
