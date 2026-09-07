<?php

declare(strict_types=1);

namespace App\Events\Tenant;

use App\Models\Tenant\MovimientoSaldo;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * El saldo en dinero de un conductor cambió, por el motivo que sea (spec tenant/022, SPEC-023,
 * BALANCE_CREDITED). Un solo evento sirve para los 4 tipos de movimiento; el conductor decide en
 * la App qué icono mostrar según `tipo`. Es un evento "crítico" (spec tenant/018, RN-04): además
 * del socket, se manda por push vía `EnviarPushSiEsCritico`.
 *
 * `ShouldBroadcastNow` y no `ShouldBroadcast`: no hay ningún `queue:work` corriendo, así que en
 * cola el aviso se quedaría esperando en `jobs` y el conductor nunca vería la acreditación en
 * pantalla (el push de respaldo sí sale, porque `EnviarPushSiEsCritico` corre síncrono).
 */
class SaldoCambiado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public readonly MovimientoSaldo $movimiento,
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
        return 'saldo.cambiado';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id_conductor' => $this->movimiento->id_conductor,
            'id_movimiento' => $this->movimiento->id_movimiento,
            'tipo' => $this->movimiento->tipo,
            'monto' => (float) $this->movimiento->monto,
            'saldo_resultante' => (float) $this->movimiento->saldo_resultante,
            'referencia' => $this->movimiento->referencia,
            'event_id' => $this->eventId,
        ];
    }
}
