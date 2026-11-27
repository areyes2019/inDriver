<?php

declare(strict_types=1);

namespace App\Http\Resources\Cliente;

use App\Models\Tenant\Pedido;
use App\Services\ModoAutomaticoService;
use App\Support\ColorConductor;
use App\Support\HoraOperacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un pedido como lo ve su cliente en la app (spec tenant/035). Nunca lleva datos internos de la
 * flotilla: comisiones, saldo, despachador ni ambiente.
 *
 * @mixin Pedido
 */
class PedidoAppResource extends JsonResource
{
    /** RN-42: la posición del repartidor solo se comparte con el envío en curso. */
    private const ESTADOS_CON_REPARTIDOR = ['TOMADO', 'ARRIBADO', 'EN_CAMINO', 'ARRIBADO_A_ENTREGA'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $conductor = $this->conductor;
        $usuario = $conductor?->usuario;
        $conRepartidor = $conductor !== null && in_array($this->estado, self::ESTADOS_CON_REPARTIDOR, true);
        $posicion = $conRepartidor ? $conductor->estadoActual : null;

        return [
            'id' => $this->id_pedido,
            'numero' => $this->numero_pedido,
            'tipo' => $this->tipo_servicio ?? 'ENVIAR',
            'estado' => $this->estado,
            'recogida' => [
                'direccion' => $this->direccion_recogida,
                'lat' => (float) $this->latitud_recogida,
                'lng' => (float) $this->longitud_recogida,
            ],
            'referencias_recogida' => $this->referencias_recogida ?? '',
            'contacto_recogida' => [
                'nombre' => $this->contacto_recogida_nombre ?? '',
                'telefono' => $this->contacto_recogida_telefono ?? '',
            ],
            'entrega' => [
                'direccion' => $this->direccion_entrega,
                'lat' => (float) $this->latitud_entrega,
                'lng' => (float) $this->longitud_entrega,
            ],
            'referencias_entrega' => $this->referencias_entrega ?? '',
            'recibe' => [
                'nombre' => $this->nombre_solicitante ?? '',
                'telefono' => $this->telefono_solicitante ?? '',
            ],
            'modalidad' => $this->modalidad_pago,
            'importe_envio' => (float) $this->importe_envio,
            'importe_producto' => (float) $this->importe_cobro,
            'km' => $this->distancia_recorrida_km !== null ? round((float) $this->distancia_recorrida_km, 1) : null,
            'creado_en' => $this->created_at?->toIso8601String(),
            'programado_para' => $this->programadoPara(),
            'repartidor' => $conRepartidor ? [
                'nombre' => trim(($usuario?->nombre ?? '').' '.($usuario?->apellido_paterno ?? '')) ?: 'Tu repartidor',
                'telefono' => $usuario?->telefono ?? '',
                'color' => ColorConductor::para($conductor->id_conductor),
            ] : null,
            'posicion' => $posicion?->ultima_latitud !== null && $posicion->ultima_longitud !== null ? [
                'lat' => (float) $posicion->ultima_latitud,
                'lng' => (float) $posicion->ultima_longitud,
            ] : null,
            'sin_repartidor' => $this->estado === 'CANCELADO'
                && $this->cancelado_por === 'SISTEMA'
                && $this->motivo_cancelacion === ModoAutomaticoService::MOTIVO_SIN_CONDUCTOR,
        ];
    }

    private function programadoPara(): ?string
    {
        if ($this->lo_antes_posible || $this->fecha_servicio === null || $this->hora_desde === null) {
            return null;
        }

        return HoraOperacion::momento($this->fecha_servicio->toDateString(), substr((string) $this->hora_desde, 0, 5))
            ->toIso8601String();
    }
}
