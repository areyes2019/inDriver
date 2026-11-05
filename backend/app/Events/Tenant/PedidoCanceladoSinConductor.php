<?php

declare(strict_types=1);

namespace App\Events\Tenant;

use App\Support\DatosDeEventoPanel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Se canceló un pedido que nadie había tomado (spec tenant/022, RN-01). El Panel lo saca de "Viajes
 * en turno" y los conductores lo quitan de su lista sin esperar el sondeo.
 *
 * Sale con el mismo nombre que `PedidoCanceladoParaConductor`, porque para quien escucha es la misma
 * noticia. Es otra clase porque aquí no hay a quién mandarle push ni qué compensar: solo va por
 * socket, igual que `PedidoYaTomado`.
 */
class PedidoCanceladoSinConductor implements ShouldBroadcastNow
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
        return 'pedido.cancelado';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id_pedido' => $this->idPedido,
            ...DatosDeEventoPanel::delPedido($this->idPedido),
            'event_id' => $this->eventId,
        ];
    }
}
