<?php

namespace App\Http\Resources\Admin;

use App\Models\AdminCentral;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdminCentral
 */
class AdminCentralResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_admin' => $this->id_admin,
            'nombre' => $this->nombre,
            'apellido_paterno' => $this->apellido_paterno,
            'apellido_materno' => $this->apellido_materno,
            'email' => $this->email,
            'estado' => $this->estado,
            'rol' => $this->rol,
            'ultimo_acceso' => $this->ultimo_acceso,
        ];
    }
}
