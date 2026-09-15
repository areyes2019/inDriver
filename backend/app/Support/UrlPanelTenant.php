<?php

declare(strict_types=1);

namespace App\Support;

/**
 * La dirección del panel de un tenant, tal como se la mandamos por correo (spec 011, enmienda
 * "la ruta de acceso en el correo de bienvenida").
 *
 * La base es `FRONTEND_URL` —la misma que ya usan CORS y el correo de recuperación de contraseña—
 * y la ruta es la que resuelve el router de Vue (`/t/:slug/login`). Estaba escrita dentro de
 * `CredencialesUsuarioTenant`; al necesitarla también `CredencialesAdminCliente` se sacó aquí,
 * para que si un día cambia la forma de la ruta se corrija una sola vez.
 */
class UrlPanelTenant
{
    public static function login(string $slug): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/t/{$slug}/login";
    }
}
