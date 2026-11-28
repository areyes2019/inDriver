<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo de bienvenida de un conductor (spec tenant/036, RN-11). Es distinto del de los demás roles
 * (`CredencialesUsuarioTenant`) porque un conductor no entra al Panel: entra a la app Panda Express,
 * que es una sola para todas las flotillas y le pide el código de su flotilla (el slug).
 */
class CredencialesConductor extends Notification
{
    public function __construct(
        private readonly string $nombreComercial,
        private readonly string $slug,
        private readonly string $email,
        private readonly string $password,
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
        $urlPlayStore = config('services.panda_express.play_store_url');

        $correo = (new MailMessage)
            ->subject("Tu acceso como repartidor de {$this->nombreComercial}")
            ->greeting('¡Bienvenido!')
            ->line("Ya se creó tu acceso como repartidor de {$this->nombreComercial}.");

        $correo = $urlPlayStore
            ? $correo->line('Descarga la app Panda Express desde Google Play:')->action('Descargar en Google Play', $urlPlayStore)
            : $correo->line('Busca la app Panda Express en Google Play y descárgala.');

        // Código y contraseña van dentro de un code span de Markdown, por la misma razón que en
        // `CredencialesUsuarioTenant`: fuera de él, el parser puede alterar caracteres.
        return $correo
            ->line('Al abrirla, entra con estos datos:')
            ->line("Código de flotilla: `{$this->slug}`")
            ->line("Correo: {$this->email}")
            ->line("Contraseña: `{$this->password}`");
    }
}
