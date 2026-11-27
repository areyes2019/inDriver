<?php

declare(strict_types=1);

namespace App\Http\Resources\Cliente;

use App\Models\Tenant\DireccionCliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una dirección favorita vista desde la app de clientes (spec tenant/035, RN-56). La dirección
 * completa vive en `calle`, igual que las que guarda el bot de WhatsApp.
 *
 * @mixin DireccionCliente
 */
class DireccionAppResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id_direccion,
            'alias' => $this->alias,
            'direccion' => $this->calle,
            'lat' => (float) $this->latitud,
            'lng' => (float) $this->longitud,
            'referencias' => $this->referencia ?? '',
        ];
    }
}
