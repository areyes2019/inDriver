<?php

declare(strict_types=1);

namespace App\Events\Tenant;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Cambió la fecha/hora agendada de un pedido que ya tiene conductor asignado (spec tenant/018,
 * DELIVERY_SCHEDULE_UPDATED). Es un evento "crítico" (RN-04): además del socket, se manda por push
 * al conductor asignado vía `EnviarPushSiEsCritico`.
 */
class PedidoReprogramado implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public readonly int $idPedido,
        public readonly string $tenantSlug,
        public readonly int $idConductor,
    ) {
        $this->eventId = (string) Str::uuid();
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantSlug}.conductores")];
    }

    public function broadcastAs(): string
    {
        return 'pedido.reprogramado';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['id_pedido' => $this->idPedido, 'event_id' => $this->eventId];
    }
}
