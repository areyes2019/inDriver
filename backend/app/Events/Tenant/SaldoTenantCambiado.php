<?php

declare(strict_types=1);

namespace App\Events\Tenant;

use App\Support\SaldoViajesTenant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * El saldo de viajes del tenant cambió (spec tenant/027 §12): un envío LIVE entregado consumió un
 * viaje, o ADMIN_CENTRAL acreditó un paquete. El Panel escribe el saldo que recibe —el resultante,
 * nunca el delta (RN-39)— y lo resalta.
 *
 * Va aparte de `pedido.entregado` a propósito: el consumo se inserta en una clausura diferida y
 * ese evento también es diferido, así que colgarle el saldo dependería del orden de dos clausuras
 * en `Pedido::$avisosDiferidos`. Aquí sale desde la misma clausura que inserta la fila. Y la
 * acreditación central no tiene ningún evento de pedido al que subirse.
 *
 * No lleva `ambiente`: el saldo es del tenant, no del ambiente (spec tenant/025, enmienda), y sin
 * el campo el Panel no lo descarta por RN-28. No es crítico (spec tenant/018, RN-04): sin push.
 */
class SaldoTenantCambiado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const MOTIVO_CONSUMO = 'consumo';

    public const MOTIVO_ACREDITACION = 'acreditacion';

    public readonly string $eventId;

    public function __construct(
        public readonly string $motivo,
        public readonly ?int $idPedido,
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
        return 'saldo-tenant.cambiado';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'saldo_viajes_tenant' => SaldoViajesTenant::actual(),
            'motivo' => $this->motivo,
            'id_pedido' => $this->idPedido,
            'event_id' => $this->eventId,
        ];
    }
}
