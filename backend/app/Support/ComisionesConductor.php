<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\ComisionConductor;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\LiquidacionComision;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cuánto le ha generado, pagado y debe el tenant a sus conductores por comisión (spec tenant/030).
 *
 * Único lugar que suma, por la misma razón que `SaldoViajes` y `SaldoViajesTenant`: el resumen del
 * tenant, el detalle por conductor y el tope de liquidación leen estas mismas dos consultas, para
 * que ninguna pantalla pueda decir un número distinto. Todo se calcula desde las tablas; no hay
 * contadores (RN-09). Los totales no dependen del `estado` de cada fila: ese solo dice qué viajes ya
 * quedaron cubiertos por completo.
 */
class ComisionesConductor
{
    /**
     * @return array{viajes: int, importe_envios: float, generado: float, pagado: float, pendiente: float}
     */
    public static function resumenTenant(): array
    {
        return self::resumir(ComisionConductor::query(), LiquidacionComision::query());
    }

    /**
     * @return array{viajes: int, importe_envios: float, generado: float, pagado: float, pendiente: float, porcentaje_actual: float}
     */
    public static function resumenDe(Conductor $conductor): array
    {
        $resumen = self::resumir(
            ComisionConductor::where('id_conductor', $conductor->id_conductor),
            LiquidacionComision::where('id_conductor', $conductor->id_conductor),
        );

        // Solo informativo: es el porcentaje configurado hoy, no el de ningún viaje en particular.
        $resumen['porcentaje_actual'] = (float) ConfiguracionTenant::obtener(ConfiguracionTenant::COMISION_PORCENTAJE, '0');

        return $resumen;
    }

    public static function pendienteDe(Conductor $conductor): float
    {
        return self::resumenDe($conductor)['pendiente'];
    }

    /**
     * @return array{viajes: int, importe_envios: float, generado: float, pagado: float, pendiente: float}
     */
    private static function resumir(Builder $comisiones, Builder $liquidaciones): array
    {
        $viajes = (clone $comisiones)->count();
        $importeEnvios = round((float) (clone $comisiones)->sum('importe_envio'), 2);
        $generado = round((float) $comisiones->sum('importe_comision'), 2);
        $pagado = round((float) $liquidaciones->sum('monto'), 2);

        return [
            'viajes' => $viajes,
            'importe_envios' => $importeEnvios,
            'generado' => $generado,
            'pagado' => $pagado,
            'pendiente' => round($generado - $pagado, 2),
        ];
    }
}
