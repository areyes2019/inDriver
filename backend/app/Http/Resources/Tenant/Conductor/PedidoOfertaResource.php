<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant\Conductor;

use App\Models\Tenant\PedidoOferta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma de una oferta vigente para el pool del conductor (spec tenant/020): a diferencia de
 * `PedidoResource` (pedido ya aceptado), sin `telefono_solicitante` — ese dato del cliente solo se
 * entrega después de aceptar.
 *
 * @mixin PedidoOferta
 */
class PedidoOfertaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_pedido' => $this->pedido->id_pedido,
            'numero_pedido' => $this->pedido->numero_pedido,
            'nombre_solicitante' => $this->pedido->nombre_solicitante,
            'direccion_recogida' => $this->pedido->direccion_recogida,
            'latitud_recogida' => $this->pedido->latitud_recogida,
            'longitud_recogida' => $this->pedido->longitud_recogida,
            'direccion_entrega' => $this->pedido->direccion_entrega,
            'latitud_entrega' => $this->pedido->latitud_entrega,
            'longitud_entrega' => $this->pedido->longitud_entrega,
            'modalidad_pago' => $this->pedido->modalidad_pago,
            'importe_envio' => $this->pedido->importe_envio,
            'estado' => $this->pedido->estado,
            'expira_en' => $this->expira_en,
        ];
    }
}
