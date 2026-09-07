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
 * Un pedido con conductor asignado cambió de estado (spec tenant/025). Hasta ahora la app solo
 * conocía el estado que ella misma provocaba —lo tomaba de la respuesta de su propia petición—, así
 * que cualquier cambio hecho del lado del servidor era invisible: el simulador del modo prueba podía
 * llevar el viaje hasta `ENTREGADO` mientras el conductor seguía viendo `TOMADO` en pantalla.
 *
 * Se emite para todos los pedidos con conductor, no solo los de prueba: un cambio hecho desde el
 * Panel tiene el mismo problema.
 *
 * Va dirigido a una persona, así que lleva `id_conductor` para que el cliente descarte lo ajeno
 * (spec tenant/018, RN-09). No es crítico (RN-05): solo socket, sin respaldo de push — si se pierde,
 * `/conductor/sync` reconstruye el estado real.
 */
class PedidoEstadoCambiado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public readonly int $idPedido,
        public readonly int $idConductor,
        public readonly string $estado,
        public readonly string $tenantSlug,
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
        return 'pedido.estado-cambiado';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id_pedido' => $this->idPedido,
            'id_conductor' => $this->idConductor,
            'estado' => $this->estado,
            'event_id' => $this->eventId,
        ];
    }
}
