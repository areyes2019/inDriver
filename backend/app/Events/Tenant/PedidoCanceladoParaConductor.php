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
 * Un pedido con conductor asignado pasó a CANCELADO (spec tenant/013, tenant/022) — típicamente
 * cancelado desde el panel de despachador mientras el conductor ya lo tenía activo en la app; se
 * le avisa al instante en vez de que se entere hasta el próximo sondeo. Es uno de los eventos
 * "críticos" (spec tenant/018, RN-04): además del socket, se manda por push al conductor asignado
 * vía `EnviarPushSiEsCritico`.
 */
class PedidoCanceladoParaConductor implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public readonly int $idPedido,
        public readonly string $tenantSlug,
        public readonly int $idConductor,
        public readonly ?string $canceladoPor = null,
        public readonly ?string $motivo = null,
        public readonly bool $compensationEligible = false,
        public readonly ?string $instruction = null,
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
        return 'pedido.cancelado';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id_pedido' => $this->idPedido,
            'cancelado_por' => $this->canceladoPor,
            'motivo' => $this->motivo,
            'compensation_eligible' => $this->compensationEligible,
            'instruction' => $this->instruction,
            'event_id' => $this->eventId,
        ];
    }
}
