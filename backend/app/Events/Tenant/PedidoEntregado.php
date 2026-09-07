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
 * Un pedido pasó a ENTREGADO: el conductor que lo traía quedó libre y, en modalidad Prepago, se le
 * descontó un viaje. Sin este aviso el Panel (spec tenant/023) dejaría al conductor pintado como
 * "Ocupado" y con su saldo viejo hasta la siguiente recarga, porque el badge se calcula por pedido
 * asignado y no por `conductores.disponibilidad`.
 *
 * No es un evento "crítico" (spec tenant/018, RN-05): va solo por socket, sin respaldo de push — si
 * se pierde, la lista se corrige sola en la siguiente carga de `/panel`.
 */
class PedidoEntregado implements ShouldBroadcastNow
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
        return 'pedido.entregado';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['id_pedido' => $this->idPedido, 'event_id' => $this->eventId];
    }
}
