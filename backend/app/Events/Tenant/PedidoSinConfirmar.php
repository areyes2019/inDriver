<?php

declare(strict_types=1);

namespace App\Events\Tenant;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * El conductor no confirmó un cambio (cancelación, reprogramación o reubicación) a los 60
 * segundos (spec tenant/022, RN-03): el Panel lo marca en rojo con botón de llamada. Va solo por
 * socket — es un aviso para el Panel, no para el conductor.
 */
class PedidoSinConfirmar implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $idPedido,
        public readonly string $tenantSlug,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantSlug}.conductores")];
    }

    public function broadcastAs(): string
    {
        return 'pedido.sin-confirmar';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['id_pedido' => $this->idPedido];
    }
}
