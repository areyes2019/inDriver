<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Despachador;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Despachador
 */
class DespachadorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_despachador' => $this->id_despachador,
            'id_usuario' => $this->id_usuario,
            'nombre' => $this->usuario->nombre,
            'apellido_paterno' => $this->usuario->apellido_paterno,
            'email' => $this->usuario->email,
            'estado' => $this->estado,
            'created_at' => $this->created_at,
        ];
    }
}
