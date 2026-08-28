<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\ConductorVehiculo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConductorVehiculo
 */
class ConductorVehiculoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_conductor' => $this->id_conductor,
            'conductor_nombre' => "{$this->conductor->usuario->nombre} {$this->conductor->usuario->apellido_paterno}",
            'id_vehiculo' => $this->id_vehiculo,
            'vehiculo_placa' => $this->vehiculo->placa,
            'vehiculo_descripcion' => trim("{$this->vehiculo->marca} {$this->vehiculo->modelo}"),
            'fecha_inicio' => $this->fecha_inicio?->toDateString(),
            'fecha_fin' => $this->fecha_fin?->toDateString(),
            'activo' => $this->activo,
            'created_at' => $this->created_at,
        ];
    }
}
