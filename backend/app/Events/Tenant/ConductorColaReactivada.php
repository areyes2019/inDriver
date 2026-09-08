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
 * Un conductor quedó libre y se le reofertaron los pedidos que estaban en cola (spec tenant/026,
 * RN-09): que refresque su pool sin esperar el sondeo de 10s.
 *
 * Va un solo evento con el total, y no un `pedido.disponible` por pedido: tres pedidos en cola
 * significarían tres sonidos, tres vibraciones y tres notificaciones del navegador para lo que es
 * un único hecho —"ya tienes trabajo otra vez"—.
 *
 * El canal es el compartido del tenant, así que la app compara `id_conductor` con el suyo antes de
 * reaccionar (spec tenant/018, RN-09).
 */
class ConductorColaReactivada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public readonly int $idConductor,
        public readonly int $total,
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
        return 'conductor.cola-reactivada';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id_conductor' => $this->idConductor,
            'total' => $this->total,
            'event_id' => $this->eventId,
        ];
    }
}
