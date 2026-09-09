<?php

declare(strict_types=1);

namespace App\Events\Tenant;

use App\Http\Resources\Tenant\PedidoResource;
use App\Support\DatosDeEventoPanel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Nació un envío: entra a "Viajes en turno" sin que nadie recargue (spec tenant/027, RN-05).
 *
 * Hasta ahora la única forma de que una fila nueva apareciera sola era `pedido.disponible`, y ese
 * evento no sale siempre: solo cuando el envío se publica **y** hay algún conductor elegible en ese
 * instante (`OfertaPedidoService::ofertar`). Un envío agendado —que nace PENDIENTE y no se publica
 * hasta 15 minutos antes de su horario (spec tenant/024)— o uno dado de alta con la flotilla
 * desconectada no disparaba absolutamente nada, así que el despachador tenía que recargar la
 * página para ver lo que él mismo acababa de crear.
 *
 * Lleva el `PedidoResource` del Panel, no el de la app: el de la app no trae agenda
 * (`fecha_servicio`, `hora_desde`, `lo_antes_posible`) ni `ambiente`, y sin agenda la fila se pinta
 * sin fecha y cae en el lugar equivocado del orden (RN-03).
 *
 * Va al mismo canal del tenant que el resto; panda_express ignora los eventos que no conoce (spec
 * tenant/018, RN-09). No es crítico (RN-05 de la 018): solo socket, sin push — es un aviso para el
 * Panel, no para el teléfono de nadie.
 */
class PedidoCreado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public function __construct(public readonly int $idPedido, public readonly string $tenantSlug)
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
        return 'pedido.creado';
    }

    /**
     * Se relee el pedido por id, sin el scope de ambiente, en vez de arrastrar el modelo: es el
     * mismo criterio del resto de eventos del Panel (ver `DatosDeEventoPanel`), y así la carga no
     * depende de en qué ambiente estaba el que lo disparó.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $pedido = DatosDeEventoPanel::pedido($this->idPedido);

        if ($pedido === null) {
            return ['id_pedido' => $this->idPedido, 'event_id' => $this->eventId];
        }

        return [...(new PedidoResource($pedido))->resolve(), 'event_id' => $this->eventId];
    }
}
