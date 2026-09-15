<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\ComisionConductor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un viaje con su comisión, tal como lo lista el detalle por conductor (spec tenant/030).
 *
 * `pedido` viene cargado con `withoutGlobalScopes()` por el controlador: las comisiones no
 * distinguen ambiente, y desde el Panel `AmbienteScope` dejaría sin número de pedido a los viajes
 * del otro ambiente.
 *
 * @mixin ComisionConductor
 */
class ComisionConductorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_comision' => $this->id_comision,
            'id_pedido' => $this->id_pedido,
            'numero_pedido' => $this->whenLoaded('pedido', fn () => $this->pedido?->numero_pedido),
            'fecha_entrega' => $this->whenLoaded('pedido', fn () => $this->pedido?->fecha_entrega),
            'importe_envio' => (float) $this->importe_envio,
            'porcentaje_aplicado' => (float) $this->porcentaje_aplicado,
            'importe_comision' => (float) $this->importe_comision,
            'estado' => $this->estado,
            'id_liquidacion' => $this->id_liquidacion,
            'pagada_en' => $this->pagada_en,
            'created_at' => $this->created_at,
        ];
    }
}
