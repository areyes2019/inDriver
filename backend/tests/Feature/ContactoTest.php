<?php

use App\Mail\ContactoRecibidoMail;
use App\Models\Contacto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * Formulario de contacto de la página pública (motopanda.mx).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);
});

/**
 * @return array<string, mixed>
 */
function datosContacto(array $overrides = []): array
{
    return array_merge([
        'nombre' => 'Laura Torres',
        'empresa' => 'Mensajería Rápida',
        'telefono' => '461 123 4567',
        'email' => 'laura@rapida.mx',
        'motos' => 8,
        'mensaje' => 'Quiero probar Motopanda.',
    ], $overrides);
}

it('saves the message and emails it to contacto@ with the sender as reply-to', function () {
    Mail::fake();

    $this->postJson('/api/v1/contacto', datosContacto())->assertCreated();

    $contacto = Contacto::sole();
    expect($contacto->empresa)->toBe('Mensajería Rápida')
        ->and($contacto->correo_enviado)->toBeTrue();

    Mail::assertSent(ContactoRecibidoMail::class, function (ContactoRecibidoMail $correo) {
        return $correo->hasTo('contacto@motopanda.mx') && $correo->hasReplyTo('laura@rapida.mx');
    });
});

it('validates the required fields and the phone', function () {
    Mail::fake();

    $this->postJson('/api/v1/contacto', datosContacto(['nombre' => '', 'email' => 'no-es-correo', 'telefono' => '12']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nombre', 'email', 'telefono']);

    expect(Contacto::count())->toBe(0);
    Mail::assertNothingSent();
});

it('answers a bot that fills the hidden field as if all went well, without saving or emailing', function () {
    Mail::fake();

    $this->postJson('/api/v1/contacto', datosContacto(['sitio_web' => 'http://spam.example']))->assertCreated();

    expect(Contacto::count())->toBe(0);
    Mail::assertNothingSent();
});

it('keeps the message when the email fails, and still answers ok', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Resend caído'));

    $this->postJson('/api/v1/contacto', datosContacto())->assertCreated();

    expect(Contacto::sole()->correo_enviado)->toBeFalse();
});

it('limits how many messages one IP can send', function () {
    Mail::fake();

    foreach (range(1, 3) as $_) {
        $this->postJson('/api/v1/contacto', datosContacto())->assertCreated();
    }

    $this->postJson('/api/v1/contacto', datosContacto())->assertTooManyRequests();
});
