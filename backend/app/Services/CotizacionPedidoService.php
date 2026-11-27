<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\ZonaServicio;

/**
 * El único motor de cotización fuera del Panel (spec tenant/035, RN-20 y RN-21): lo usan el bot de
 * WhatsApp y la app de clientes. Área de servicio, distancia por calles y tarifa de la flotilla.
 *
 * Nunca cotiza en línea recta: sin una ruta real de Google no hay precio (spec 031, RN-02).
 */
class CotizacionPedidoService
{
    public const SIN_TARIFAS = 'SIN_TARIFAS';

    public const SIN_RUTA = 'SIN_RUTA';

    public const FUERA_DE_ZONA = 'FUERA_DE_ZONA';

    public function __construct(
        private readonly RutaService $rutas,
        private readonly TarifaService $tarifas,
    ) {}

    /** ¿El punto cae dentro del área de servicio de la flotilla? (spec 016). */
    public function cubre(float $lat, float $lng): bool
    {
        return ZonaServicio::cubrePunto($lat, $lng);
    }

    /**
     * @param  array{lat: float, lng: float}  $recogida
     * @param  array{lat: float, lng: float}  $entrega
     * @param  bool  $validarZona  WhatsApp ya valida cada punto al recibirlo; la app lo valida aquí.
     */
    public function cotizar(array $recogida, array $entrega, bool $validarZona = true): ResultadoCotizacion
    {
        if ($validarZona) {
            foreach (['recogida' => $recogida, 'entrega' => $entrega] as $nombre => $punto) {
                if (! $this->cubre($punto['lat'], $punto['lng'])) {
                    return ResultadoCotizacion::falla(self::FUERA_DE_ZONA, $nombre);
                }
            }
        }

        $tarifas = $this->tarifas->vigentes();
        if ($tarifas === null) {
            return ResultadoCotizacion::falla(self::SIN_TARIFAS);
        }

        $medida = $this->rutas->medirPorCalle($recogida, $entrega);
        if ($medida === null) {
            return ResultadoCotizacion::falla(self::SIN_RUTA);
        }

        return ResultadoCotizacion::lista(
            $medida['km'],
            $medida['minutos'],
            $this->tarifas->importe($medida['km'], $tarifas),
        );
    }
}
