<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant as TenantModel;
use App\Models\Tenant\Pedido;
use App\Services\OfertaPedidoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Cierra la ventana de 45s de una oferta (spec tenant/020, RN-03). Se programa con `delay()` desde
 * `OfertaPedidoService::ofertar()`; si para cuando corre el pedido ya se aceptó o canceló, no hace
 * nada — `OfertaPedidoService::expirar()` lo valida.
 */
class ExpirarOfertaPedido implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly TenantModel $tenant,
        private readonly int $idPedido,
    ) {}

    public function handle(OfertaPedidoService $ofertas): void
    {
        // Con `QUEUE_CONNECTION=sync` (tests, o un tenant chico sin worker aparte) este job corre
        // en línea, dentro de la misma petición que ya inicializó el tenant al publicar el pedido
        // (`OfertaPedidoService::ofertar` reoferta recursivamente). `initialize()` a la misma
        // tenant ya es un no-op seguro, pero `end()` sí tumba el contexto — solo se cierra si fue
        // este job quien lo abrió, para no cortarle el piso a quien lo llamó.
        $yaInicializado = tenancy()->initialized && tenancy()->tenant?->getTenantKey() === $this->tenant->getTenantKey();

        if (! $yaInicializado) {
            tenancy()->initialize($this->tenant);
        }

        try {
            $pedido = Pedido::find($this->idPedido);

            if ($pedido && $pedido->estado === 'PUBLICADO') {
                $ofertas->expirar($pedido);
            }
        } catch (\Throwable $e) {
            Log::error('No se pudo expirar la oferta del pedido', [
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
