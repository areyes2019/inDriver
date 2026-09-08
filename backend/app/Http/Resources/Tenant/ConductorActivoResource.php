<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Conductor;
use App\Support\ColorConductor;
use App\Support\SaldoViajes;
use App\Support\SeguimientoEnvio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conductor
 */
class ConductorActivoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // `pedidos` ya viene filtrado a solo el activo (ver `ConductorController::activos()`):
        // como mucho hay uno (spec tenant/020, RN-06, un conductor no puede tener dos a la vez).
        $pedido = $this->pedidos->first();

        return [
            'id_conductor' => $this->id_conductor,
            'nombre' => trim("{$this->usuario->nombre} {$this->usuario->apellido_paterno}"),
            'disponibilidad' => $this->disponibilidad,
            // Color de su marcador y de su polilínea (spec tenant/026, RN-17/RN-18). Se calcula,
            // no se guarda: así el Panel y panda_express no pueden discrepar.
            'color' => ColorConductor::para($this->id_conductor),
            'placa' => $this->vehiculo?->placa,
            'marca' => $this->vehiculo?->marca,
            // Viajes prepagados restantes (spec tenant/015): vendidos menos consumidos. Se manda
            // siempre, sin mirar la modalidad del tenant, para que el Panel no tenga que pedir
            // `/configuracion` antes de pintar la lista (spec tenant/023). La fórmula vive en
            // `SaldoViajes` desde la spec tenant/027, porque ahora también viaja dentro de los
            // eventos y no puede haber dos versiones del mismo número.
            'saldo_viajes' => SaldoViajes::desdeAgregados($this->resource),
            'latitud' => $this->estadoActual?->ultima_latitud !== null ? (float) $this->estadoActual->ultima_latitud : null,
            'longitud' => $this->estadoActual?->ultima_longitud !== null ? (float) $this->estadoActual->ultima_longitud : null,
            'pedido_asignado' => $pedido ? [
                'id_pedido' => $pedido->id_pedido,
                'numero_pedido' => $pedido->numero_pedido,
                'estado' => $pedido->estado,
                'direccion_recogida' => $pedido->direccion_recogida,
                'latitud_recogida' => (float) $pedido->latitud_recogida,
                'longitud_recogida' => (float) $pedido->longitud_recogida,
                'direccion_entrega' => $pedido->direccion_entrega,
                'latitud_entrega' => (float) $pedido->latitud_entrega,
                'longitud_entrega' => (float) $pedido->longitud_entrega,
                'seguimiento' => SeguimientoEnvio::para($pedido),
            ] : null,
        ];
    }
}
