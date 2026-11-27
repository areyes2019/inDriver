<?php

declare(strict_types=1);

namespace App\Support;

/**
 * La dirección de la PWA de clientes de un tenant (spec tenant/035): `/pide/{slug}/`. La usan el
 * manifiesto de la PWA —su `id`, `start_url` y `scope`— y el detalle del tenant en ADMIN_CENTRAL,
 * para que las dos digan siempre la misma URL.
 */
class UrlAppClientesTenant
{
    public static function inicio(string $slug): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/pide/{$slug}/";
    }
}
