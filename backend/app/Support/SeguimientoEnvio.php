<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Pedido;
use App\Services\PedidoEstadoService;

/**
 * Qué línea toca dibujar para un envío (spec tenant/026, RN-11 a RN-15).
 *
 * Es el único lugar del sistema que traduce "estado del pedido" a "hito, estilo, color y extremos
 * de la polilínea". El Panel y panda_express solo pintan lo que sale de aquí; antes cada uno lo
 * deducía por su cuenta y no coincidían —la app cambiaba de tramo en `EN_CAMINO` y el Panel en
 * `ARRIBADO`, así que el despachador y el conductor veían mapas distintos del mismo envío.
 */
class SeguimientoEnvio
{
    /** RN-11: el conductor va hacia el punto de recogida. */
    public const HITO_ACERCAMIENTO = 'H1';

    /** RN-11: el conductor ya está en la recogida y va hacia la entrega. */
    public const HITO_ENTREGA = 'H2';

    /**
     * `null` cuando no hay nada que dibujar: sin conductor asignado, o el envío ya terminó (RN-21).
     *
     * @return array<string, mixed>|null
     */
    public static function para(Pedido $pedido): ?array
    {
        if ($pedido->id_conductor === null || in_array($pedido->estado, PedidoEstadoService::ESTADOS_FINALES, true)) {
            return null;
        }

        $hito = $pedido->estado === 'TOMADO' ? self::HITO_ACERCAMIENTO : self::HITO_ENTREGA;

        if ($hito === self::HITO_ACERCAMIENTO) {
            return [
                'hito' => self::HITO_ACERCAMIENTO,
                'estilo' => 'GUIONES',
                'color' => ColorConductor::para($pedido->id_conductor),
                // `origen` nulo a propósito (RN-14): el origen de H1 es la posición viva del
                // conductor, que las dos apps ya tienen —la suya propia, o la que llega por
                // `ubicacion.actualizada`—. Mandar aquí una coordenada la dejaría vieja al segundo.
                'origen' => null,
                'destino' => [
                    'lat' => (float) $pedido->latitud_recogida,
                    'lng' => (float) $pedido->longitud_recogida,
                ],
            ];
        }

        return [
            'hito' => self::HITO_ENTREGA,
            'estilo' => 'SOLIDO',
            'color' => ColorConductor::para($pedido->id_conductor),
            'origen' => [
                'lat' => (float) $pedido->latitud_recogida,
                'lng' => (float) $pedido->longitud_recogida,
            ],
            'destino' => [
                'lat' => (float) $pedido->latitud_entrega,
                'lng' => (float) $pedido->longitud_entrega,
            ],
        ];
    }
}
