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

        return round($tarifas['banderazo'] + $kmCobrables * $tarifas['km_adicional'], 2);
    }
}
