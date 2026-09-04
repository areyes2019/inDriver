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

/**
 * Un pedido pasó a PUBLICADO: avisa a los conductores conectados del tenant (spec tenant/013) para
 * que lo vean en su pool al instante, sin esperar el sondeo de 10s que panda_express ya trae.
 */
class PedidoDisponible implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Pedido $pedido, public readonly string $tenantSlug) {}

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
        return (new PedidoResource($this->pedido))->resolve();
    }
}
