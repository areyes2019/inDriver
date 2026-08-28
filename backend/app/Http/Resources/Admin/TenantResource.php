<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_tenant' => $this->id_tenant,
            'nombre_comercial' => $this->nombre_comercial,
            'razon_social' => $this->razon_social,
            'rfc' => $this->rfc,
            'telefono' => $this->telefono,
            'email' => $this->email,
            'estado' => $this->estado,
            'modo_estado' => $this->modo_estado,
            'created_at' => $this->created_at,
        ];
    }
}
