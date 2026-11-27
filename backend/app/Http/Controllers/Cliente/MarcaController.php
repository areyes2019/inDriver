<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cliente;

use App\Http\Controllers\Controller;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\ZonaServicio;
use App\Support\HorarioAtencion;
use App\Support\MarcaFlotilla;
use Illuminate\Http\JsonResponse;

/**
 * Lo que la app necesita antes de entrar (spec tenant/035, sección 8): nombre, contacto y si la app
 * está prendida. Es pública.
 *
 * Logo y banner los sube la flotilla desde la Configuración (`MarcaFlotilla`); sin ellos van en
 * nulo y la app usa los de MotoPanda (D16). Los servicios por flotilla siguen con el valor por
 * defecto de la spec (los dos). El horario sale de `HorarioAtencion`.
 */
class MarcaController extends Controller
{
    /** Celaya, cuando la flotilla no tiene zona de servicio dibujada. */
    private const CENTRO_POR_DEFECTO = ['lat' => 20.5235, 'lng' => -100.8157];

    public function show(): JsonResponse
    {
        $tenant = tenant();
        $horario = HorarioAtencion::deLaFlotilla();
        $limites = ZonaServicio::boundsDeZonasActivas();

        return response()->json([
            'slug' => $tenant->slug,
            'nombre' => $tenant->nombre_comercial,
            'logo_url' => MarcaFlotilla::logoUrl(),
            'banner_url' => MarcaFlotilla::bannerUrl(),
            'telefono_contacto' => $tenant->telefono,
            'servicios' => ['enviar' => true, 'recibir' => true],
            'disponible' => ConfiguracionTenant::appClientesPrendida(),
            'centro' => $this->centro($limites),
            // El rectángulo de las zonas activas: el buscador de direcciones no sugiere nada fuera.
            'limites' => $limites,
            'horario' => $horario->texto(),
            'abierto_ahora' => $horario->abiertoAhora(),
        ]);
    }

    /**
     * @param  array{north: float, south: float, east: float, west: float}|null  $limites
     * @return array{lat: float, lng: float}
     */
    private function centro(?array $limites): array
    {
        if ($limites === null) {
            return self::CENTRO_POR_DEFECTO;
        }

        return [
            'lat' => ($limites['north'] + $limites['south']) / 2,
            'lng' => ($limites['east'] + $limites['west']) / 2,
        ];
    }
}
