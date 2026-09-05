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
 * Un conductor mandó su posición (spec tenant/018, LOCATION_UPDATE) — para que el Panel
 * (AdminCliente/Despachador) vea el punto moverse en el mapa sin recargar. Evento de alta
 * frecuencia (RN-05): va solo por socket, sin `event_id` ni respaldo de push — si se pierde, la
 * siguiente posición lo reemplaza sin problema.
 */
class UbicacionActualizada implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $idConductor,
        public readonly float $latitud,
        public readonly float $longitud,
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
        return 'ubicacion.actualizada';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id_conductor' => $this->idConductor,
            'latitud' => $this->latitud,
            'longitud' => $this->longitud,
        ];
    }
}
