<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CredencialesAdminCliente extends Notification
{
    public function __construct(
        private readonly string $nombreComercial,
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
        return (new MailMessage)
            ->subject("Acceso a tu panel de {$this->nombreComercial}")
            ->greeting("¡Bienvenido, {$this->nombreComercial}!")
            ->line('Ya se creó tu acceso al panel. Puedes ingresar con estas credenciales:')
            ->line("Correo: {$this->email}")
            // La contraseña va dentro de un code span de Markdown: las líneas de un
            // `MailMessage` se renderizan como Markdown, y fuera de un code span el parser se
            // come caracteres de escape y deja al usuario con una contraseña que no es la
            // guardada (ver App\Support\PasswordGenerada).
            ->line("Contraseña: `{$this->password}`")
            ->line('Te recomendamos cambiarla después de tu primer ingreso.');
    }
}
