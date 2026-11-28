<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo de bienvenida de un conductor (spec tenant/036, RN-11 a RN-15). Es distinto del de los demás
 * roles (`CredencialesUsuarioTenant`) porque un conductor no entra al Panel: entra a la app Panda
 * Express, que es una sola para todas las flotillas y le pide el código de su flotilla (el slug).
 *
 * Usa una plantilla propia y no el tema de `MailMessage` (D7): el tema pone `APP_NAME` en el
 * encabezado y "Regards" al final, y no admite el logo ni los colores de la marca.
 *
 * La URL del logo la resuelve quien manda el correo, con la tenencia ya inicializada (RN-13).
 */
class CredencialesConductor extends Notification
{
    public function __construct(
        private readonly string $nombreComercial,
        private readonly string $slug,
        private readonly string $email,
        private readonly string $password,
        private readonly ?string $nombreConductor = null,
        private readonly ?string $logoUrl = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $nombre = trim((string) $this->nombreConductor);

        return (new MailMessage)
            ->from((string) config('mail.from.address'), $this->nombreComercial)
            ->subject("Tu acceso como repartidor de {$this->nombreComercial}")
            ->view(
                ['mail.credenciales-conductor', 'mail.credenciales-conductor-texto'],
                [
                    'flotilla' => $this->nombreComercial,
                    'inicial' => mb_strtoupper(mb_substr(trim($this->nombreComercial), 0, 1)),
                    'logoUrl' => $this->logoUrl,
                    'nombre' => $nombre === '' ? null : $nombre,
                    'slug' => $this->slug,
                    'email' => $this->email,
                    'password' => $this->password,
                    'urlPlayStore' => config('services.panda_express.play_store_url') ?: null,
                ],
            );
    }
}
