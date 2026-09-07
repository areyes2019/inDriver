<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Pedido;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
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
            'id_cliente' => $this->id_cliente,
            'cliente_nombre' => $this->cliente?->nombre,
            'nombre_solicitante' => $this->nombre_solicitante,
            'telefono_solicitante' => $this->telefono_solicitante,
            'direccion_recogida' => $this->direccion_recogida,
            'latitud_recogida' => $this->latitud_recogida,
            'longitud_recogida' => $this->longitud_recogida,
            'direccion_entrega' => $this->direccion_entrega,
            'latitud_entrega' => $this->latitud_entrega,
            'longitud_entrega' => $this->longitud_entrega,
            'fecha_servicio' => $this->fecha_servicio?->toDateString(),
            'hora_desde' => $this->hora_desde,
            'hora_hasta' => $this->hora_hasta,
            'lo_antes_posible' => $this->lo_antes_posible,
            'modalidad_pago' => $this->modalidad_pago,
            'importe_envio' => $this->importe_envio,
            'importe_cobro' => $this->importe_cobro,
            'id_despachador' => $this->id_despachador,
            'despachador_nombre' => $this->despachador?->usuario !== null
                ? trim("{$this->despachador->usuario->nombre} {$this->despachador->usuario->apellido_paterno}")
                : null,
            'id_conductor' => $this->id_conductor,
            'conductor_nombre' => $this->conductor?->usuario !== null
                ? trim("{$this->conductor->usuario->nombre} {$this->conductor->usuario->apellido_paterno}")
                : null,
            'id_vehiculo' => $this->id_vehiculo,
            'vehiculo_placa' => $this->vehiculo?->placa,
            'estado' => $this->estado,
            'fecha_publicacion' => $this->fecha_publicacion,
            'fecha_asignacion' => $this->fecha_asignacion,
            'fecha_entrega' => $this->fecha_entrega,
            'fecha_cancelacion' => $this->fecha_cancelacion,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
