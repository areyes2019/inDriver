<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Cliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cliente
 */
class ClienteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_cliente' => $this->id_cliente,
            'nombre' => $this->nombre,
            'telefono' => $this->telefono,
            'email' => $this->email,
            'referencia' => $this->referencia,
            'estado' => $this->estado,
            'avisos_whatsapp' => $this->avisos_whatsapp,
            'avisos_whatsapp_en' => $this->avisos_whatsapp_en,
            'avisos_whatsapp_por' => $this->avisos_whatsapp_por,
            'created_at' => $this->created_at,
        ];
    }
}
