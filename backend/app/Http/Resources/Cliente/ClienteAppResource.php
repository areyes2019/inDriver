<?php

declare(strict_types=1);

namespace App\Http\Resources\Cliente;

use App\Models\Tenant\Cliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El cliente visto desde su propia app (spec tenant/035). Solo lo que le sirve a él: nada de notas
 * internas ni de permisos de WhatsApp.
 *
 * @mixin Cliente
 */
class ClienteAppResource extends JsonResource
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
            'email' => $this->email_acceso,
        ];
    }
}
