<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\UrlPanelTenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CredencialesAdminCliente extends Notification
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

    /**
     * Primero "dónde", luego "con qué" (spec 011, enmienda): la dirección del panel va como texto
     * —para copiarla o guardarla— y también como botón; las credenciales quedan igual que antes.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = UrlPanelTenant::login($this->slug);

        return (new MailMessage)
            ->subject("Acceso a tu panel de {$this->nombreComercial}")
            ->greeting("¡Bienvenido, {$this->nombreComercial}!")
            ->line("Tu dirección de acceso es: {$url}")
            ->line("Esa dirección es exclusiva de {$this->nombreComercial}: el nombre que va justo después del dominio (`/{$this->slug}`) identifica a tu negocio. Úsala siempre para entrar y compártela con tus despachadores y conductores; es la misma para todo tu equipo.")
            ->line('Dentro de esa dirección, tu usuario administrador entra con estas credenciales:')
            ->line("Correo: {$this->email}")
            // La contraseña va dentro de un code span de Markdown: las líneas de un
            // `MailMessage` se renderizan como Markdown, y fuera de un code span el parser se
            // come caracteres de escape y deja al usuario con una contraseña que no es la
            // guardada (ver App\Support\PasswordGenerada).
            ->line("Contraseña: `{$this->password}`")
            ->action('Ir al panel', $url)
            ->line('Te recomendamos cambiarla después de tu primer ingreso.');
    }
}
