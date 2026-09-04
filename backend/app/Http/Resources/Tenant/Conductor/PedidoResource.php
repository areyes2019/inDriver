<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant\Conductor;

use App\Models\Tenant\Pedido;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma del pedido tal como la consume panda_express: mismos nombres de columna y mismo `estado`
 * en mayúsculas que la tabla `pedidos` (spec tenant/013, "los nombres de campo y el estado en
 * mayúsculas del backend son la fuente de verdad; es panda_express quien se adapta a ellos").
 *
 * @mixin Pedido
 */
class PedidoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_pedido' => $this->id_pedido,
            'numero_pedido' => $this->numero_pedido,
            'nombre_solicitante' => $this->nombre_solicitante,
            'telefono_solicitante' => $this->telefono_solicitante,
            'direccion_recogida' => $this->direccion_recogida,
            'latitud_recogida' => $this->latitud_recogida,
            'longitud_recogida' => $this->longitud_recogida,
            'direccion_entrega' => $this->direccion_entrega,
            'latitud_entrega' => $this->latitud_entrega,
            'longitud_entrega' => $this->longitud_entrega,
            'modalidad_pago' => $this->modalidad_pago,
            'importe_envio' => $this->importe_envio,
            'importe_cobro' => $this->importe_cobro,
            'id_vehiculo' => $this->id_vehiculo,
            'vehiculo_placa' => $this->vehiculo?->placa,
            'estado' => $this->estado,
            'fecha_asignacion' => $this->fecha_asignacion,
            'fecha_entrega' => $this->fecha_entrega,
        ];
    }
}
