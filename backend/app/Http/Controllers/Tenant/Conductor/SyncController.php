<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Conductor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\VentaViajeConductorController;
use App\Http\Resources\Tenant\Conductor\PedidoOfertaResource;
use App\Http\Resources\Tenant\Conductor\PedidoResource;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\PedidoOferta;
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

        // Latido de vida del conductor (spec tenant/019, RN-04): sin esto, uno en línea pero sin
        // pedido activo (que no manda LOCATION_UPDATE) se vería inactivo aunque siga sondeando.
        ConductorEstado::updateOrCreate(
            ['id_conductor' => $conductor->id_conductor],
            ['ultima_actualizacion' => now()],
        );

        $pedidoActivo = Pedido::where('id_conductor', $conductor->id_conductor)
            ->whereNotIn('estado', PedidoEstadoService::ESTADOS_FINALES)
            ->first();

        // Ofertas propias vigentes (spec tenant/020), no "todo lo PUBLICADO del tenant": mismo
        // criterio que `PedidoController::disponibles()`.
        $ofertas = PedidoOferta::where('pedido_ofertas.id_conductor', $conductor->id_conductor)
            ->where('pedido_ofertas.estado', 'PENDIENTE')
            ->where('pedido_ofertas.expira_en', '>', now())
            ->with('pedido')
            // Por antigüedad del pedido (spec tenant/026, RN-08), igual que `disponibles()`: al
            // reconectar el conductor tiene que ver la misma lista, en el mismo orden.
            ->join('pedidos', 'pedidos.id_pedido', '=', 'pedido_ofertas.id_pedido')
            ->orderBy('pedidos.created_at')
            ->select('pedido_ofertas.*')
            ->get();

        $modalidad = ConfiguracionTenant::obtener(ConfiguracionTenant::MODALIDAD, 'Prepago');
        $saldo = $modalidad === 'Comision' ? null : VentaViajeConductorController::saldoConductor($conductor);

        return response()->json([
            'pedido_activo' => $pedidoActivo ? new PedidoResource($pedidoActivo) : null,
            'pedidos_disponibles' => PedidoOfertaResource::collection($ofertas),
            'saldo' => $saldo,
        ]);
    }
}
