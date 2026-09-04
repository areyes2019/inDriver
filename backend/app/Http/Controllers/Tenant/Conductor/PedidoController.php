<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Conductor;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\Conductor\PedidoResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorVehiculo;
use App\Models\Tenant\Pedido;
use App\Services\PedidoEstadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PedidoController extends Controller
{
    public function __construct(private readonly PedidoEstadoService $estados) {}

    /**
     * Pool abierto (spec tenant/013): todo pedido PUBLICADO sin conductor asignado del tenant del
     * token, visible para cualquier conductor conectado — gana el primero que lo acepta.
     */
    public function disponibles(): AnonymousResourceCollection
    {
        $pedidos = Pedido::where('estado', 'PUBLICADO')
            ->whereNull('id_conductor')
            ->orderBy('fecha_publicacion')
            ->get();

        return PedidoResource::collection($pedidos);
    }

    /**
     * Restaura el pedido activo del conductor al reabrir la app; `null` si no tiene ninguno.
     */
    public function activo(Request $request): JsonResponse
    {
        $pedido = Pedido::where('id_conductor', $this->conductorActual($request)->id_conductor)
            ->whereNotIn('estado', PedidoEstadoService::ESTADOS_FINALES)
            ->first();

        if (! $pedido) {
            // response()->json(null) no produce el JSON `null` literal: Symfony sustituye un data
            // nulo por un ArrayObject vacío (`{}`) al construir la respuesta. Se fuerza el body.
            return response()->json()->setContent('null');
        }

        return response()->json(new PedidoResource($pedido));
    }

    public function aceptar(Request $request, Pedido $pedido): JsonResponse
    {
        $conductor = $this->conductorActual($request);

        if ($pedido->estado !== 'PUBLICADO' || $pedido->id_conductor !== null) {
            throw ValidationException::withMessages([
                'estado' => 'Este pedido ya no está disponible.',
            ]);
        }

        if ($this->tienePedidoActivo($conductor)) {
            throw ValidationException::withMessages([
                'estado' => 'Ya tienes un pedido activo, no puedes aceptar otro.',
            ]);
        }

        DB::transaction(function () use ($pedido, $conductor) {
            $pedido->id_conductor = $conductor->id_conductor;
            $pedido->id_vehiculo = ConductorVehiculo::where('id_conductor', $conductor->id_conductor)
                ->where('activo', true)
                ->value('id_vehiculo');

            $this->estados->transicionar($pedido, 'TOMADO');
            $pedido->save();
        });

        $this->registrarAuditoria($request, $pedido, "El conductor aceptó el pedido {$pedido->numero_pedido}");

        return response()->json(new PedidoResource($pedido));
    }

    /**
     * Avanza el pedido del conductor por su propia máquina de transiciones (spec tenant/013): nunca
     * `RECHAZADO`, y solo sobre su propio pedido activo.
     */
    public function cambiarEstado(Request $request, Pedido $pedido): JsonResponse
    {
        $this->verificarPropiedad($pedido, $this->conductorActual($request));

        $data = $request->validate([
            'estado' => ['required', Rule::in(array_keys(PedidoEstadoService::TRANSICIONES))],
        ]);

        $permitidos = PedidoEstadoService::TRANSICIONES_CONDUCTOR[$pedido->estado] ?? [];

        if (! in_array($data['estado'], $permitidos, true)) {
            throw ValidationException::withMessages([
                'estado' => "No se puede pasar el pedido de {$pedido->estado} a {$data['estado']}.",
            ]);
        }

        $this->estados->transicionar($pedido, $data['estado']);
        $pedido->save();

        $this->registrarAuditoria($request, $pedido, "Cambio de estado del pedido {$pedido->numero_pedido} a {$data['estado']} (conductor)");

        return response()->json(new PedidoResource($pedido));
    }

    public function cancelar(Request $request, Pedido $pedido): JsonResponse
    {
        $this->verificarPropiedad($pedido, $this->conductorActual($request));

        $this->estados->transicionar($pedido, 'CANCELADO');
        $pedido->save();

        $this->registrarAuditoria($request, $pedido, "El conductor canceló el pedido {$pedido->numero_pedido}");

        return response()->json(new PedidoResource($pedido));
    }

    private function conductorActual(Request $request): Conductor
    {
        return $request->user('conductor-token')->conductor;
    }

    private function tienePedidoActivo(Conductor $conductor): bool
    {
        return Pedido::where('id_conductor', $conductor->id_conductor)
            ->whereNotIn('estado', PedidoEstadoService::ESTADOS_FINALES)
            ->exists();
    }

    private function verificarPropiedad(Pedido $pedido, Conductor $conductor): void
    {
        if ($pedido->id_conductor !== $conductor->id_conductor) {
            abort(403, 'Este pedido no te pertenece.');
        }
    }

    private function registrarAuditoria(Request $request, Pedido $pedido, string $descripcion): void
    {
        Auditoria::create([
            'id_usuario' => $request->user('conductor-token')->id_usuario,
            'tabla_afectada' => 'pedidos',
            'accion' => 'CAMBIO_ESTADO',
            'descripcion' => $descripcion,
        ]);
    }
}
