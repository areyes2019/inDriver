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
 * El Panel cerró por una emergencia un pedido En entrega (spec tenant/006, tenant/022 RN-17). Es uno
 * de los eventos "críticos" (spec tenant/018, RN-04): el conductor lleva el paquete encima y no puede
 * perdérselo, así que además del socket sale por push vía `EnviarPushSiEsCritico`.
 */
class PedidoCerradoPorIncidencia implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const INSTRUCCION = 'CONTACT_SUPPORT';

    public readonly string $eventId;

    public function __construct(
        public readonly int $idPedido,
        public readonly string $tenantSlug,
        public readonly int $idConductor,
        public readonly ?string $motivo = null,
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
        return 'pedido.incidencia';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id_pedido' => $this->idPedido,
            ...DatosDeEventoPanel::delPedido($this->idPedido),
            'saldo_viajes' => DatosDeEventoPanel::saldoDeConductor($this->idConductor),
            'motivo' => $this->motivo,
            'instruction' => self::INSTRUCCION,
            'event_id' => $this->eventId,
        ];
    }
}
