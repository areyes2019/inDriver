<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\DireccionCliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DireccionCliente
 */
class DireccionClienteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_direccion' => $this->id_direccion,
            'id_cliente' => $this->id_cliente,
            'alias' => $this->alias,
            'calle' => $this->calle,
            'numero' => $this->numero,
            'colonia' => $this->colonia,
            'cp' => $this->cp,
            'ciudad' => $this->ciudad,
            'estado' => $this->estado,
            'referencia' => $this->referencia,
            'latitud' => $this->latitud,
            'longitud' => $this->longitud,
            'instrucciones_entrega' => $this->instrucciones_entrega,
            'created_at' => $this->created_at,
        ];
    }
}
