<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo con el código de acceso a la app de clientes (spec tenant/035, RN-13). El asunto no lleva el
 * código, para que no aparezca en la vista previa de la bandeja ni en notificaciones.
 */
class CodigoAccesoMail extends Mailable
{
    public function __construct(
        public readonly string $codigo,
        public readonly string $nombreFlotilla,
    ) {}

    public function envelope(): Envelope
    {
        $remitente = config('pide.codigo.remitente') ?: config('mail.from.address');

        return new Envelope(
            from: new Address((string) $remitente, $this->nombreFlotilla),
            subject: "Tu código para entrar a {$this->nombreFlotilla}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.codigo-acceso',
            text: 'mail.codigo-acceso-texto',
            with: [
                'codigo' => $this->codigo,
                'flotilla' => $this->nombreFlotilla,
                'minutos' => (int) config('pide.codigo.vigencia_minutos'),
            ],
        );
    }
}
