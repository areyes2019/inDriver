<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Vehiculo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vehiculo
 */
class VehiculoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_vehiculo' => $this->id_vehiculo,
            'placa' => $this->placa,
            'marca' => $this->marca,
            'modelo' => $this->modelo,
            'anio' => $this->anio,
            'color' => $this->color,
            'tipo' => $this->tipo,
            'numero_economico' => $this->numero_economico,
            'estado' => $this->estado,
            'created_at' => $this->created_at,
        ];
    }
}
