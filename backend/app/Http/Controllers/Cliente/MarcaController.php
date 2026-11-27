<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cliente;

use App\Http\Controllers\Controller;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\ZonaServicio;
use App\Support\HorarioAtencion;
use Illuminate\Http\JsonResponse;

/**
 * Lo que la app necesita antes de entrar (spec tenant/035, sección 8): nombre, contacto y si la app
 * está prendida. Es pública.
 *
 * Logo, banner y servicios por flotilla llegan en la Fase 8; mientras tanto van los valores por
 * defecto de la spec (sin logo ni banner, los dos servicios). El horario sale de `HorarioAtencion`.
 */
class MarcaController extends Controller
{
    /** Celaya, cuando la flotilla no tiene zona de servicio dibujada. */
    private const CENTRO_POR_DEFECTO = ['lat' => 20.5235, 'lng' => -100.8157];

    public function show(): JsonResponse
    {
        $tenant = tenant();
        $horario = HorarioAtencion::deLaFlotilla();

        return response()->json([
            'slug' => $tenant->slug,
            'nombre' => $tenant->nombre_comercial,
            'logo_url' => null,
            'banner_url' => null,
            'telefono_contacto' => $tenant->telefono,
            'servicios' => ['enviar' => true, 'recibir' => true],
            'disponible' => ConfiguracionTenant::appClientesPrendida(),
            'centro' => $this->centro(),
            'horario' => $horario->texto(),
            'abierto_ahora' => $horario->abiertoAhora(),
        ]);
    }

    /**
     * @return array{lat: float, lng: float}
     */
    private function centro(): array
    {
        $bounds = ZonaServicio::boundsDeZonasActivas();

        if ($bounds === null) {
            return self::CENTRO_POR_DEFECTO;
        }

        return [
            'lat' => ($bounds['north'] + $bounds['south']) / 2,
            'lng' => ($bounds['east'] + $bounds['west']) / 2,
        ];
    }
}
