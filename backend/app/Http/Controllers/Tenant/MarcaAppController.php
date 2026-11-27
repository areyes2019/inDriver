<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Auditoria;
use App\Support\MarcaFlotilla;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Logo y banner de la app de clientes, desde la pestaña "App de clientes" de la Configuración
 * (spec tenant/035, RN-60 y RN-61). Solo AdminCliente. Quitar una imagen devuelve la de MotoPanda.
 */
class MarcaAppController extends Controller
{
    /** El logo puede salirse del cuadrado exacto hasta este porcentaje: se recorta al centro. */
    private const TOLERANCIA_CUADRADO = 0.02;

    public function subirLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => [
                'required', 'file', 'mimes:png,jpg,jpeg', 'max:1024',
                'dimensions:min_width='.MarcaFlotilla::LOGO_LADO.',min_height='.MarcaFlotilla::LOGO_LADO,
                $this->cuadrado(),
            ],
        ], [
            'logo.mimes' => 'El logo debe ser PNG o JPG.',
            'logo.max' => 'El logo no puede pesar más de 1 MB.',
            'logo.dimensions' => 'El logo debe medir al menos 512 × 512 píxeles.',
        ]);

        MarcaFlotilla::guardarLogo($request->file('logo'));
        $this->auditar($request, 'Se cambió el logo de la app de clientes');

        return response()->json($this->marca());
    }

    public function subirBanner(Request $request): JsonResponse
    {
        $request->validate([
            'banner' => [
                'required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048',
                'dimensions:min_width=800',
                $this->horizontal(),
            ],
        ], [
            'banner.mimes' => 'El banner debe ser PNG, JPG o WebP.',
            'banner.max' => 'El banner no puede pesar más de 2 MB.',
            'banner.dimensions' => 'El banner debe medir al menos 800 píxeles de ancho.',
        ]);

        MarcaFlotilla::guardarBanner($request->file('banner'));
        $this->auditar($request, 'Se cambió el banner de la app de clientes');

        return response()->json($this->marca());
    }

    public function quitarLogo(Request $request): JsonResponse
    {
        MarcaFlotilla::quitarLogo();
        $this->auditar($request, 'Se quitó el logo de la app de clientes');

        return response()->json($this->marca());
    }

    public function quitarBanner(Request $request): JsonResponse
    {
        MarcaFlotilla::quitarBanner();
        $this->auditar($request, 'Se quitó el banner de la app de clientes');

        return response()->json($this->marca());
    }

    /**
     * @return array{logo_url: string|null, banner_url: string|null}
     */
    private function marca(): array
    {
        return [
            'logo_url' => MarcaFlotilla::logoUrl(),
            'banner_url' => MarcaFlotilla::bannerUrl(),
        ];
    }

    private function cuadrado(): Closure
    {
        return function (string $atributo, mixed $archivo, Closure $falla): void {
            [$ancho, $alto] = $this->medidas($archivo);

            if ($ancho > 0 && abs($ancho - $alto) > max($ancho, $alto) * self::TOLERANCIA_CUADRADO) {
                $falla("El logo debe ser cuadrado (mide {$ancho} × {$alto} píxeles).");
            }
        };
    }

    private function horizontal(): Closure
    {
        return function (string $atributo, mixed $archivo, Closure $falla): void {
            [$ancho, $alto] = $this->medidas($archivo);

            if ($ancho > 0 && $ancho <= $alto) {
                $falla("El banner debe ser horizontal, más ancho que alto (mide {$ancho} × {$alto} píxeles).");
            }
        };
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function medidas(mixed $archivo): array
    {
        if (! $archivo instanceof UploadedFile) {
            return [0, 0];
        }

        $medidas = @getimagesize($archivo->getRealPath());

        return $medidas === false ? [0, 0] : [(int) $medidas[0], (int) $medidas[1]];
    }

    private function auditar(Request $request, string $descripcion): void
    {
        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'configuraciones_tenant',
            'accion' => 'EDICION',
            'descripcion' => $descripcion,
        ]);
    }
}
