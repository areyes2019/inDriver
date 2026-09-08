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
 * Se acreditaron viajes prepagados a un conductor (spec tenant/018, BALANCE_CREDITED). Es un evento
 * "crítico" (RN-04): además del socket, se manda por push al conductor vía
 * `EnviarPushSiEsCritico`.
 *
 * `ShouldBroadcastNow` y no `ShouldBroadcast`: no hay ningún `queue:work` corriendo, así que en
 * cola el aviso se quedaría esperando en `jobs` y el conductor nunca vería la acreditación en
 * pantalla (el push de respaldo sí sale, porque `EnviarPushSiEsCritico` corre síncrono).
 */
class SaldoAcreditado implements ShouldBroadcastNow
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
            // spec tenant/027: el saldo resultante, no solo el delta. El Panel escribe el número
            // que recibe en vez de sumarle al que tenía, así que un evento repetido o perdido no
            // puede dejar la cifra corrida.
            'saldo_viajes' => DatosDeEventoPanel::saldoDeConductor($this->idConductor),
            'event_id' => $this->eventId,
        ];
    }
}
