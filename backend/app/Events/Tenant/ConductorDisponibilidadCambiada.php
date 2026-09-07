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
 * Un conductor se conectó o desconectó (spec tenant/019): el Panel lo refleja al instante en el
 * menú de conductores en línea, con un toast. No es un evento "crítico" (spec tenant/018, RN-04):
 * a nadie en la App le urge enterarse de esto, así que va solo por socket, sin respaldo de push.
 *
 * `ShouldBroadcastNow` y no `ShouldBroadcast`: no hay ningún `queue:work` corriendo (ni en local
 * ni en el VPS — `deploy/README.md` solo contempla `schedule:run`), así que un broadcast en cola
 * se quedaría esperando para siempre en `jobs` y el Panel nunca se enteraría. Al instante, además,
 * es lo que pide la spec ("lo refleja al instante").
 */
class ConductorDisponibilidadCambiada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public readonly int $idConductor,
        public readonly string $disponibilidad,
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
        return 'conductor.disponibilidad-cambiada';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id_conductor' => $this->idConductor,
            'disponibilidad' => $this->disponibilidad,
            'event_id' => $this->eventId,
        ];
    }
}
