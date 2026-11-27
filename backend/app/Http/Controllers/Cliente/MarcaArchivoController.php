<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cliente;

use App\Http\Controllers\Controller;
use App\Support\MarcaFlotilla;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sirve el logo, el banner y los íconos de la app de clientes de una flotilla (spec tenant/035,
 * RN-60 a RN-62). Es pública, como `/cliente/marca`. Cada archivo lleva su versión en el nombre, así
 * que se cachea un año: una imagen nueva siempre llega con otra URL.
 */
class MarcaArchivoController extends Controller
{
    public function show(string $archivo): Response
    {
        $ruta = MarcaFlotilla::ruta($archivo);
        $disco = Storage::disk('local');

        if ($ruta === null || ! $disco->exists($ruta)) {
            abort(404);
        }

        return response($disco->get($ruta), 200, [
            'Content-Type' => match (pathinfo($archivo, PATHINFO_EXTENSION)) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            },
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
