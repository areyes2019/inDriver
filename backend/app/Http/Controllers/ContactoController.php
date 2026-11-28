<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\ContactoRecibidoMail;
use App\Models\Contacto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Formulario de contacto de la página pública (motopanda.mx). Guarda el mensaje y avisa por correo a
 * `contacto@motopanda.mx`. Si el correo falla, el mensaje ya quedó guardado y el interesado no ve
 * ningún error: no es su culpa.
 *
 * Contra spam: un campo trampa (`sitio_web`) que un humano no ve, y el límite `contacto` por IP.
 */
class ContactoController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Un bot llena todos los campos. Se le responde como si todo saliera bien, sin guardar nada.
        if (filled($request->input('sitio_web'))) {
            return response()->json(['ok' => true], 201);
        }

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'empresa' => ['nullable', 'string', 'max:160'],
            'telefono' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{8,30}$/'],
            'email' => ['required', 'email', 'max:160'],
            'motos' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'mensaje' => ['nullable', 'string', 'max:2000'],
        ], [
            'telefono.regex' => 'Escribe un teléfono válido, con al menos 8 dígitos.',
        ]);

        $contacto = Contacto::create([...$data, 'ip' => $request->ip()]);

        try {
            Mail::to((string) config('services.contacto.correo'))->send(new ContactoRecibidoMail($contacto));
            $contacto->update(['correo_enviado' => true]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo mandar el aviso del formulario de contacto.', [
                'id_contacto' => $contacto->id_contacto,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true], 201);
    }
}
