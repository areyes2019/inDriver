<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\ConfiguracionTenant;

/**
 * La fórmula del importe de envío en el servidor (spec tenant/031, RN-01).
 *
 * Es la misma de `totalViaje` en `NuevaEntregaPanel.vue`: el Panel sigue calculando en el navegador
 * para mostrarlo mientras se captura, y este servicio atiende a quien no tiene navegador. Si la
 * fórmula cambia, cambia en los dos sitios.
 */
class TarifaService
{
    /**
     * Las tres tarifas del tenant (spec 015), o `null` si falta alguna.
     *
     * @return array{banderazo: float, km_incluidos: float, km_adicional: float}|null
     */
    public function vigentes(): ?array
    {
        $banderazo = ConfiguracionTenant::obtener(ConfiguracionTenant::BANDERAZO);
        $incluidos = ConfiguracionTenant::obtener(ConfiguracionTenant::KM_INCLUIDOS);
        $adicional = ConfiguracionTenant::obtener(ConfiguracionTenant::KM_ADICIONAL);

        if ($banderazo === null || $incluidos === null || $adicional === null) {
            return null;
        }

        return [
            'banderazo' => (float) $banderazo,
            'km_incluidos' => (float) $incluidos,
            'km_adicional' => (float) $adicional,
        ];
    }

    /**
     * @param  array{banderazo: float, km_incluidos: float, km_adicional: float}  $tarifas
     */
    public function importe(float $km, array $tarifas): float
    {
        $kmCobrables = max(0.0, $km - $tarifas['km_incluidos']);

        return $this->redondear($tarifas['banderazo'] + $kmCobrables * $tarifas['km_adicional']);
    }

    /**
     * Lleva el total a un múltiplo de $5, con mínimo de $5 (spec 006): si pasa menos de $1.50 del
     * múltiplo inferior baja a él; si pasa $1.50 o más, sube al siguiente.
     *
     * Opera en centavos enteros para que un `66.50` que llega como `66.4999999` no baje a 65. Es la
     * misma operación que `redondearTarifa()` en `frontend/src/lib/tarifa.ts`.
     */
    public function redondear(float $total): float
    {
        $centavos = max(0, (int) round($total * 100));

        return (float) max(5, intdiv($centavos + 350, 500) * 5);
    }
}
