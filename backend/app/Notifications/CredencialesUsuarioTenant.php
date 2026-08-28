<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CredencialesUsuarioTenant extends Notification
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
        $url = rtrim(config('app.frontend_url'), '/')."/t/{$this->slug}/login";

        return (new MailMessage)
            ->subject("Acceso a tu panel de {$this->nombreComercial}")
            ->greeting('¡Bienvenido!')
            ->line("Ya se creó tu acceso al panel de {$this->nombreComercial}. Puedes ingresar con estas credenciales:")
            ->line("Correo: {$this->email}")
            ->line("Contraseña: {$this->password}")
            ->action('Ir al panel', $url)
            ->line('Te recomendamos cambiarla después de tu primer ingreso.');
    }
}
