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
 * Se acreditaron viajes prepagados a un conductor (spec tenant/018, BALANCE_CREDITED). Es un evento
 * "crítico" (RN-04): además del socket, se manda por push al conductor vía
 * `EnviarPushSiEsCritico`.
 */
class SaldoAcreditado implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public readonly int $idConductor,
        public readonly int $viajesAcreditados,
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
        return 'saldo.acreditado';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id_conductor' => $this->idConductor,
            'viajes_acreditados' => $this->viajesAcreditados,
            'event_id' => $this->eventId,
        ];
    }
}
