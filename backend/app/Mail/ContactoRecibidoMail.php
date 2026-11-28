<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Contacto;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso a Motopanda de un mensaje nuevo del formulario de contacto. Sale del mismo remitente que el
 * código de acceso (el único dominio autorizado en Resend) y "Responder" le contesta al interesado.
 */
class ContactoRecibidoMail extends Mailable
{
    public function __construct(public readonly Contacto $contacto) {}

    public function envelope(): Envelope
    {
        $remitente = config('pide.codigo.remitente') ?: config('mail.from.address');

        return new Envelope(
            from: new Address((string) $remitente, 'Motopanda'),
            replyTo: [new Address($this->contacto->email, $this->contacto->nombre)],
            subject: 'Nuevo contacto: '.($this->contacto->empresa ?: $this->contacto->nombre),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.contacto-recibido',
            text: 'mail.contacto-recibido-texto',
            with: ['contacto' => $this->contacto],
        );
    }
}
