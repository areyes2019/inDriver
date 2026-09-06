<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Conductor;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\Conductor\PedidoOfertaResource;
use App\Http\Resources\Tenant\Conductor\PedidoResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\PedidoOferta;
use App\Services\CambioEnvioService;
use App\Services\OfertaPedidoService;
use App\Services\PedidoEstadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PedidoController extends Controller
{
    public function __construct(
        private readonly PedidoEstadoService $estados,
        private readonly OfertaPedidoService $ofertas,
        private readonly CambioEnvioService $cambios,
    ) {}

    /**
     * Pool del conductor (spec tenant/013, tenant/020): ya no es "todo lo PUBLICADO del tenant",
     * sino sus propias ofertas vigentes en `pedido_ofertas` — así nunca ve un pedido que ya
     * rechazó, perdió, o que no le corresponde en esta ronda (RN-05).
     */
    public function disponibles(Request $request): AnonymousResourceCollection
    {
        $ofertas = PedidoOferta::where('id_conductor', $this->conductorActual($request)->id_conductor)
            ->where('estado', 'PENDIENTE')
            ->where('expira_en', '>', now())
            ->with('pedido')
            ->orderBy('ofrecida_en')
            ->get();

        return PedidoOfertaResource::collection($ofertas);
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

    /**
     * Resuelve la carrera con `lockForUpdate` (spec tenant/020, RN-02): dos conductores pueden
     * llegar aquí casi al mismo tiempo, pero solo uno bloquea la fila primero y la encuentra
     * `PUBLICADO`; el otro la ve ya `TOMADO` y recibe el mismo error de siempre.
     */
    public function aceptar(Request $request, Pedido $pedido): JsonResponse
    {
        $conductor = $this->conductorActual($request);

        if ($conductor->tienePedidoActivo()) {
            throw ValidationException::withMessages([
                'estado' => 'Ya tienes un pedido activo, no puedes aceptar otro.',
            ]);
        }

        $pedido = DB::transaction(function () use ($pedido, $conductor) {
            /** @var Pedido $pedidoBloqueado */
            $pedidoBloqueado = Pedido::query()->lockForUpdate()->findOrFail($pedido->id_pedido);

            if ($pedidoBloqueado->estado !== 'PUBLICADO' || $pedidoBloqueado->id_conductor !== null) {
                throw ValidationException::withMessages([
                    'estado' => 'Este pedido ya no está disponible.',
                ]);
            }

            $pedidoBloqueado->id_conductor = $conductor->id_conductor;
            $pedidoBloqueado->id_vehiculo = $conductor->vehiculo?->id_vehiculo;

            $this->estados->transicionar($pedidoBloqueado, 'TOMADO');
            $pedidoBloqueado->save();

            $this->ofertas->cerrarPorAceptacion($pedidoBloqueado, $conductor);

            return $pedidoBloqueado;
        });

        $this->registrarAuditoria($request, $pedido, "El conductor aceptó el pedido {$pedido->numero_pedido}");

        return response()->json(new PedidoResource($pedido));
    }

    /**
     * Rechazo explícito, sin castigo (spec tenant/020, RN-07): a diferencia de dejar expirar, no
     * cuenta para el apagado automático por 3 expiraciones seguidas.
     */
    public function rechazar(Request $request, Pedido $pedido): JsonResponse
    {
        $this->ofertas->rechazar($pedido, $this->conductorActual($request));

        $this->registrarAuditoria($request, $pedido, "El conductor rechazó la oferta del pedido {$pedido->numero_pedido}");

        return response()->json(status: 204);
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

    /**
     * El "ack" del sistema (spec tenant/022, RN-03/RN-16): confirma que la App vio el último
     * cambio (cancelación, reprogramación o reubicación) que le tocaba a este pedido.
     */
    public function notificado(Request $request, Pedido $pedido): JsonResponse
    {
        $this->cambios->confirmar($this->conductorActual($request), $pedido);

        return response()->json(status: 204);
    }

    private function conductorActual(Request $request): Conductor
    {
        return $request->user('conductor-token')->conductor;
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
