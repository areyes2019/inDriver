<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Models\Tenant\WhatsappConversacion;
use App\Models\WhatsappNumero;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Envía mensajes por la API de WhatsApp de Meta (spec tenant/031).
 */
class WhatsappClient
{
    /**
     * @return string El id que Meta le asignó al mensaje.
     *
     * @throws RequestException si Meta rechaza el envío o no responde.
     * @throws RuntimeException si Meta acepta el envío sin devolver un id.
     */
    public function enviarTexto(WhatsappNumero $numero, WhatsappConversacion $conversacion, string $texto): string
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            config('services.whatsapp.graph_version'),
            $numero->phone_number_id,
        );

        // Meta puede omitir el teléfono de quien adoptó un nombre de usuario (RN-12).
        $destinatario = $conversacion->telefono !== null
            ? ['to' => $conversacion->telefono]
            : ['recipient' => $conversacion->usuario_whatsapp];

        $respuesta = Http::withToken($numero->access_token)
            ->timeout(15)
            ->post($url, [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                ...$destinatario,
                'type' => 'text',
                'text' => ['body' => $texto],
            ])
            ->throw();

        $wamid = $respuesta->json('messages.0.id');

        if (! is_string($wamid) || $wamid === '') {
            throw new RuntimeException('Meta aceptó el mensaje sin devolver su id.');
        }

        return $wamid;
    }
}
