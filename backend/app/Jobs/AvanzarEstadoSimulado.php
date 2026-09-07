<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant as TenantModel;
use App\Models\Tenant\Pedido;
use App\Services\PedidoEstadoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Los dos pasos de la simulación que no son un recorrido sino una pausa (spec tenant/025): recoger
 * el paquete (`ARRIBADO` → `EN_CAMINO`) y cerrar la entrega (`ARRIBADO_A_ENTREGA` → `ENTREGADO`).
 * No hay puntos que escribir, solo esperar un momento y avanzar.
 *
 * Igual que `SimularSiguientePunto`, se apaga solo si el pedido ya no está en `$estadoEsperado`: si
 * el conductor adelantó el hito con el botón, este job no tiene nada que hacer (RN-04).
 */
class AvanzarEstadoSimulado implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly TenantModel $tenant,
        private readonly int $idPedido,
        private readonly string $estadoEsperado,
        private readonly string $estadoNuevo,
    ) {}

    public function handle(PedidoEstadoService $estados): void
    {
        // Mismo cuidado que `ExpirarOfertaPedido` con QUEUE_CONNECTION=sync: no cerrar un contexto
        // de tenant que no abrió este job.
        $yaInicializado = tenancy()->initialized && tenancy()->tenant?->getTenantKey() === $this->tenant->getTenantKey();

        if (! $yaInicializado) {
            tenancy()->initialize($this->tenant);
        }

        try {
            $pedido = Pedido::find($this->idPedido);

            if (! $pedido || ! $pedido->es_prueba || $pedido->estado !== $this->estadoEsperado) {
                return;
            }

            $estados->transicionar($pedido, $this->estadoNuevo);
            $pedido->save();
        } catch (\Throwable $e) {
            // spec tenant/025, RN-07: el viaje se queda donde estaba y el conductor lo saca adelante
            // a mano con los botones de hito.
            Log::error('No se pudo avanzar el estado simulado del pedido', [
                'id_pedido' => $this->idPedido,
                'estado_nuevo' => $this->estadoNuevo,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if (! $yaInicializado) {
                tenancy()->end();
            }
        }
    }
}
