<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Ciudad;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ciudad
 */
class CiudadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_ciudad' => $this->id_ciudad,
            'nombre' => $this->nombre,
            'place_id' => $this->place_id,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'bounds' => $this->bounds,
        ];
    }
}
