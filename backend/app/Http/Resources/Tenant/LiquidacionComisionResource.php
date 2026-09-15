<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\LiquidacionComision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un pago de comisión registrado por el tenant (spec tenant/030).
 *
 * @mixin LiquidacionComision
 */
class LiquidacionComisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_liquidacion' => $this->id_liquidacion,
            'id_conductor' => $this->id_conductor,
            'monto' => (float) $this->monto,
            'referencia' => $this->referencia,
            'registrado_por' => $this->whenLoaded('usuario', fn () => $this->usuario !== null
                ? trim("{$this->usuario->nombre} {$this->usuario->apellido_paterno}")
                : null),
            'viajes_cubiertos' => $this->whenCounted('comisiones'),
            'created_at' => $this->created_at,
        ];
    }
}
