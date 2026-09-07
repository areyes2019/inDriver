<?php

declare(strict_types=1);

namespace App\Events\Tenant;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Un pedido pasó a TOMADO: avisa a los demás conductores conectados del tenant (spec tenant/013)
 * para que lo quiten de su pool sin esperar el sondeo. No es un evento "crítico" (spec tenant/018,
 * RN-05): va solo por socket, sin respaldo de push — si no hay conexión, el pool se corrige solo en
 * el siguiente sondeo o `/conductor/sync`.
 */
class PedidoYaTomado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public function __construct(public readonly int $idPedido, public readonly string $tenantSlug)
    {
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
        return 'pedido.tomado';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['id_pedido' => $this->idPedido, 'event_id' => $this->eventId];
    }
}
