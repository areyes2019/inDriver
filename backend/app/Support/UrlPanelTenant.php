<?php

declare(strict_types=1);

namespace App\Support;

/**
 * La dirección del panel de un tenant, tal como se la mandamos por correo (spec 011, enmienda
 * "la ruta de acceso en el correo de bienvenida").
 *
 * La base es `FRONTEND_URL` —la misma que ya usan CORS y el correo de recuperación de contraseña—
 * y la ruta es la que resuelve el router de Vue. Estaba escrita dentro de
 * `CredencialesUsuarioTenant`; al necesitarla también `CredencialesAdminCliente` se sacó aquí,
 * para que si un día cambia la forma de la ruta se corrija una sola vez.
 *
 * Y cambió: desde la enmienda "el slug va inmediatamente después del dominio" (spec tenant/001,
 * RN-S6) la entrada del tenant es su raíz, `/{slug}`, que muestra el login sin sesión y manda al
 * Panel con ella. `/t/{slug}/login` sigue existiendo solo como redirección para los correos que ya
 * se enviaron.
 */
class UrlPanelTenant
{
    public static function login(string $slug): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/{$slug}";
    }
}
