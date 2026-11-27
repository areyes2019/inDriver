<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cliente;

use App\Http\Controllers\Controller;
use App\Support\UrlAppClientesTenant;
use Illuminate\Http\JsonResponse;

/**
 * Manifiesto de la PWA de cada flotilla (spec tenant/035, RN-05). Cada flotilla es una app distinta
 * para el teléfono: su propio `id`, su `scope` y su nombre. Las URLs van absolutas contra
 * `FRONTEND_URL` porque el manifiesto lo sirve Laravel, no la app.
 *
 * Los íconos son los de MotoPanda hasta la Fase 8, cuando cada flotilla sube su logo.
 */
class ManifiestoController extends Controller
{
    private const MORADO = '#5b21b6';

    private const FONDO = '#f7f6fb';

    public function show(): JsonResponse
    {
        $tenant = tenant();
        $base = rtrim((string) config('app.frontend_url'), '/');
        $inicio = UrlAppClientesTenant::inicio($tenant->slug);
        $iconos = "{$base}/pide/iconos";

        return response()
            ->json([
                'id' => $inicio,
                'name' => $tenant->nombre_comercial,
                'short_name' => mb_substr($tenant->nombre_comercial, 0, 12),
                'description' => "Pide tus mandados con {$tenant->nombre_comercial}",
                'lang' => 'es-MX',
                'start_url' => $inicio,
                'scope' => $inicio,
                'display' => 'standalone',
                'orientation' => 'portrait',
                'theme_color' => self::MORADO,
                'background_color' => self::FONDO,
                'icons' => [
                    ['src' => "{$iconos}/icono-192.png", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                    ['src' => "{$iconos}/icono-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                    ['src' => "{$iconos}/icono-maskable-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ],
            ], options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'no-cache');
    }
}
