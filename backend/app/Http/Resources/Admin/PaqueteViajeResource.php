<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\PaqueteViaje;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaqueteViaje
 */
class PaqueteViajeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_paquete' => $this->id_paquete,
            'codigo_paquete' => $this->codigo_paquete,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'cantidad_viajes' => $this->cantidad_viajes,
            'precio' => $this->precio,
            'estado' => $this->estado,
            'created_at' => $this->created_at,
        ];
    }
}
