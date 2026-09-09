<?php

declare(strict_types=1);

namespace App\Services\Gps;

use App\Models\Tenant\Conductor;
use App\Support\TokenGps;

/**
 * Emite y cancela el permiso con el que el conductor le habla al microservicio GPS (spec
 * tenant/028, §5.3 y RN-07).
 */
class PermisoGpsService
{
    public function __construct(private readonly BuzonGps $buzon) {}

    /**
     * Devuelve null cuando la integración está apagada: la App lo entiende como "sigue mandando la
     * posición a Laravel" (RN-19), que es exactamente el comportamiento de hoy.
     *
     * @return array{token: string, expira_en: string, renovar_en: string, url: string}|null
     */
    public function emitir(Conductor $conductor): ?array
    {
        $slug = tenant()?->slug;

        if (! $this->buzon->habilitado() || $slug === null) {
            return null;
        }

        $ahora = now();
        $expira = $ahora->clone()->addSeconds((int) config('gps.vida_permiso'));

        $token = TokenGps::firmar([
            'jti' => TokenGps::identificador(),
            'tenant' => $slug,
            'sub' => $conductor->id_conductor,
            // `iat` no es decorativo: es lo que permite cancelar todos los permisos vivos de un
            // conductor de una vez, sin llevar una lista de los que se le han emitido.
            'iat' => $ahora->getTimestamp(),
            'exp' => $expira->getTimestamp(),
        ], (string) config('gps.secreto'));

        return [
            'token' => $token,
            'expira_en' => $expira->toIso8601String(),
            'renovar_en' => $ahora->clone()->addSeconds((int) config('gps.renovar_en'))->toIso8601String(),
            'url' => config('gps.url').'/gps/v1/ping',
        ];
    }

    /**
     * Cancela de golpe todos los permisos vivos del conductor (RN-10).
     *
     * Se cancela por conductor y no por permiso concreto a propósito: un conductor puede tener más
     * de uno vigente a la vez —acaba de renovar, o entró desde un segundo teléfono—, y revocar
     * solo el último dejaría al anterior mandando posiciones hasta media hora después de cerrar
     * sesión. El servicio rechaza todo permiso emitido antes de este instante.
     */
    public function cancelar(Conductor $conductor): void
    {
        $slug = tenant()?->slug;

        if ($slug === null) {
            return;
        }

        $this->buzon->permisoCancelado($slug, $conductor->id_conductor, now()->getTimestamp());
    }
}
