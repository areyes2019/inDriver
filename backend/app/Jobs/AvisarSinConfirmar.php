<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\Tenant\PedidoSinConfirmar;
use App\Models\Tenant as TenantModel;
use App\Models\Tenant\Pedido;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A los 60s de un cambio que requiere confirmación (cancelación, reprogramación, reubicación), si
 * `pedidos.notificado_conductor_en` sigue nulo, el Panel lo marca en rojo (spec tenant/022,
 * RN-03). No hay reintentos automáticos: de aquí en adelante es intervención humana.
 */
class AvisarSinConfirmar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly TenantModel $tenant,
        private readonly int $idPedido,
    ) {}

    public function handle(): void
    {
        // Mismo cuidado que `ExpirarOfertaPedido` con QUEUE_CONNECTION=sync: no cerrar un
        // contexto de tenant que no abrió este job.
        $yaInicializado = tenancy()->initialized && tenancy()->tenant?->getTenantKey() === $this->tenant->getTenantKey();

        if (! $yaInicializado) {
            tenancy()->initialize($this->tenant);
        }

        try {
            $pedido = Pedido::find($this->idPedido);

            if ($pedido && $pedido->notificado_conductor_en === null) {
                PedidoSinConfirmar::dispatch($pedido->id_pedido, $this->tenant->slug);
            }
        } catch (\Throwable $e) {
            Log::error('No se pudo evaluar la confirmación del conductor', [
                'id_pedido' => $this->idPedido,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if (! $yaInicializado) {
                tenancy()->end();
            }
        }
    }
}
