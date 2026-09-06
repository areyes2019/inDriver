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
 * Se aplicó un cambio de destino sobre un envío ya asignado (spec tenant/022, RN-12,
 * DELIVERY_ADDRESS_UPDATED). A diferencia de reprogramar, esto sí se permite con el envío
 * `EN_CAMINO`: es justo el caso de uso — el paquete ya va en camino. Evento "crítico" (spec
 * tenant/018, RN-04): también se manda por push.
 */
class PedidoDireccionActualizada implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public readonly int $idPedido,
        public readonly string $tenantSlug,
        public readonly int $idConductor,
        public readonly string $direccionAnterior,
        public readonly string $direccionNueva,
        public readonly float $latitudNueva,
        public readonly float $longitudNueva,
        public readonly float $extraDistanciaKm,
        public readonly float $extraPago,
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
        return 'pedido.direccion-actualizada';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id_pedido' => $this->idPedido,
            'direccion_anterior' => $this->direccionAnterior,
            'direccion_nueva' => $this->direccionNueva,
            'latitud_nueva' => $this->latitudNueva,
            'longitud_nueva' => $this->longitudNueva,
            'extra_distancia_km' => $this->extraDistanciaKm,
            'extra_pago' => $this->extraPago,
            'requiere_confirmacion' => true,
            'event_id' => $this->eventId,
        ];
    }
}
