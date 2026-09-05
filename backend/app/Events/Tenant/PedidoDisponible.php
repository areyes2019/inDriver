<?php

declare(strict_types=1);

namespace App\Events\Tenant;

use App\Http\Resources\Tenant\Conductor\PedidoResource;
use App\Models\Tenant\Pedido;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Un pedido pasó a PUBLICADO: avisa a los conductores conectados del tenant (spec tenant/013) para
 * que lo vean en su pool al instante, sin esperar el sondeo de 10s que panda_express ya trae.
 * Es uno de los eventos "críticos" (spec tenant/018, RN-04): además del socket, se manda por push a
 * los conductores disponibles vía `EnviarPushSiEsCritico`.
 */
class PedidoDisponible implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public function __construct(public readonly Pedido $pedido, public readonly string $tenantSlug)
    {
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
        return 'pedido.disponible';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [...(new PedidoResource($this->pedido))->resolve(), 'event_id' => $this->eventId];
    }
}
