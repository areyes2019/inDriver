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
 * El conductor canceló en En recogida y el pedido regresó a `PUBLICADO` (spec tenant/006, tenant/027
 * Enmienda 4). El Panel lo regresa a "Viajes pendientes" y libera al conductor; las demás apps
 * refrescan su lista. Va solo por socket: quien canceló ya lo sabe (spec tenant/018, RN-04).
 */
class PedidoDevuelto implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public readonly int $idPedido,
        public readonly string $tenantSlug,
        public readonly int $idConductorAnterior,
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
        return 'pedido.devuelto';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id_pedido' => $this->idPedido,
            ...DatosDeEventoPanel::delPedido($this->idPedido),
            'id_conductor_anterior' => $this->idConductorAnterior,
            'saldo_viajes' => DatosDeEventoPanel::saldoDeConductor($this->idConductorAnterior),
            'event_id' => $this->eventId,
        ];
    }
}
