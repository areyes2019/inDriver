<?php

declare(strict_types=1);

namespace App\Events\Tenant;

use App\Http\Resources\Tenant\Conductor\PedidoResource;
use App\Http\Resources\Tenant\PedidoResource as PanelPedidoResource;
use App\Models\Tenant\Pedido;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Un pedido pasó a PUBLICADO: avisa a los conductores conectados del tenant (spec tenant/013) para
 * que lo vean en su pool al instante, sin esperar el sondeo de 10s que panda_express ya trae.
 * Es uno de los eventos "críticos" (spec tenant/018, RN-04): además del socket, se manda por push a
 * los conductores disponibles vía `EnviarPushSiEsCritico`.
 */
class PedidoDisponible implements ShouldBroadcastNow
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
     * La forma que consume panda_express, más los campos que el Panel necesita para insertar la
     * fila sin recargar (spec tenant/027, RN-05 y RN-28).
     *
     * `PedidoResource` de la app no trae agenda ni ambiente, y el Panel usa los tres: sin
     * `lo_antes_posible` y `hora_desde` la fila se pinta sin fecha y se ordena mal (RN-03), y sin
     * `ambiente` un envío TEST se cuela en un Panel LIVE, porque el canal es por tenant y el filtro
     * de ambiente lo hace el navegador con este campo. La app ignora lo que no conoce (spec
     * tenant/018, RN-09).
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            ...(new PedidoResource($this->pedido))->resolve(),
            ...Arr::only((new PanelPedidoResource($this->pedido))->resolve(), [
                'fecha_servicio',
                'hora_desde',
                'hora_hasta',
                'lo_antes_posible',
                'id_conductor',
                'conductor_nombre',
                'ambiente',
            ]),
            'event_id' => $this->eventId,
        ];
    }
}
