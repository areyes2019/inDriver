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
 * Un pedido con conductor asignado cambió de estado. La app solo conoce el estado que ella misma
 * provoca —lo toma de la respuesta de su propia petición—, así que sin este evento un cambio hecho
 * del lado del servidor (por ejemplo desde el Panel) sería invisible: el viaje podría llegar a
 * `ENTREGADO` mientras el conductor sigue viendo `TOMADO` en pantalla.
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
        $datos = DatosDeEventoPanel::delPedido($this->idPedido);

        return [
            'id_pedido' => $this->idPedido,
            'id_conductor' => $this->idConductor,
            'estado' => $this->estado,
            // spec tenant/027: `seguimiento` viaja con el evento para que el mapa del Panel cambie
            // de hito sin volver a pedir `/conductores/activos`, y `ambiente` para que un envío de
            // un ambiente no se cuele en el Panel del otro (RN-28).
            'seguimiento' => $datos['seguimiento'] ?? null,
            'ambiente' => $datos['ambiente'] ?? null,
            'event_id' => $this->eventId,
        ];
    }
}
