<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Conductor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conductor
 */
class ConductorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_conductor' => $this->id_conductor,
            'id_usuario' => $this->id_usuario,
            'nombre' => $this->usuario->nombre,
            'apellido_paterno' => $this->usuario->apellido_paterno,
            'email' => $this->usuario->email,
            'numero_licencia' => $this->numero_licencia,
            'fecha_vencimiento_licencia' => $this->fecha_vencimiento_licencia?->toDateString(),
            'estado' => $this->estado,
            'disponibilidad' => $this->disponibilidad,
            'id_despachador' => $this->id_despachador,
            'despachador' => $this->whenLoaded('despachador', fn () => $this->despachador ? [
                'id_despachador' => $this->despachador->id_despachador,
                'nombre' => $this->despachador->usuario->nombre,
                'apellido_paterno' => $this->despachador->usuario->apellido_paterno,
            ] : null),
            'vehiculo' => $this->whenLoaded('vehiculo', fn () => $this->vehiculo ? [
                'id_vehiculo' => $this->vehiculo->id_vehiculo,
                'placa' => $this->vehiculo->placa,
                'marca' => $this->vehiculo->marca,
            ] : null),
            'saldo_viajes' => $this->when(
                array_key_exists('viajes_vendidos', $this->resource->getAttributes()),
                fn () => (int) $this->viajes_vendidos - (int) $this->viajes_consumidos,
            ),
            'created_at' => $this->created_at,
        ];
    }
}
