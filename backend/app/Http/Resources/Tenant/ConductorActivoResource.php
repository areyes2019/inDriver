<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Conductor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conductor
 */
class ConductorActivoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_conductor' => $this->id_conductor,
            'nombre' => trim("{$this->usuario->nombre} {$this->usuario->apellido_paterno}"),
            'disponibilidad' => $this->disponibilidad,
            'placa' => $this->vehiculo?->placa,
        ];
    }
}
