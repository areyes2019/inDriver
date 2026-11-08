<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Nombres que un tenant no puede usar como slug (spec tenant/001, enmienda "el slug va
 * inmediatamente después del dominio", RN-S5).
 *
 * Desde esa enmienda el slug es el primer segmento de la URL del Panel (`/{slug}`), así que un
 * tenant llamado `admin` o `gps` pisaría una ruta que ya atiende Vue Router o nginx. La lista es
 * una sola y vive aquí: el frontend no la necesita, porque el único lugar por el que entra un slug
 * es el alta de tenant (`TenantController::generarSlugUnico`).
 *
 * De dónde sale cada palabra:
 * - `admin`, `about`, `t`: rutas de Vue Router (`t` sigue existiendo por las redirecciones de
 *   compatibilidad desde las URLs viejas `/t/{slug}/...`).
 * - `api`, `sanctum`, `up`, `gps`, `gps-health`, `app`, `apps`: `location` de nginx que nunca
 *   llegan al SPA (`deploy/vps/nginx-delivery.prosello.com.mx.conf`).
 * - `assets`: la carpeta del build de Vite.
 * - `privacidad`: página fija del aviso de privacidad (spec 013), servida por nginx antes que el SPA.
 * - `index`, `login`, `panel`: para que `/{slug}` y `/{slug}/panel` nunca sean ambiguos.
 */
class SlugsReservados
{
    public const LISTA = [
        'admin',
        'about',
        't',
        'api',
        'sanctum',
        'up',
        'gps',
        'gps-health',
        'app',
        'apps',
        'assets',
        'privacidad',
        'index',
        'login',
        'panel',
    ];

    public static function esReservado(string $slug): bool
    {
        return in_array($slug, self::LISTA, true);
    }
}
