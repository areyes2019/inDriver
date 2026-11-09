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
    /** Límites de Meta para los botones de respuesta. */
    public const MAX_BOTONES = 3;

    public const MAX_TITULO_BOTON = 20;

    /**
     * @return string El id que Meta le asignó al mensaje.
     *
     * @throws RequestException si Meta rechaza el envío o no responde.
     * @throws RuntimeException si Meta acepta el envío sin devolver un id.
     */
    public function enviarTexto(WhatsappNumero $numero, WhatsappConversacion $conversacion, string $texto): string
    {
        return $this->enviar($numero, $conversacion, [
            'type' => 'text',
            'text' => ['body' => $texto],
        ]);
    }

    /**
     * Un mensaje con botones de respuesta (RN-19). Meta admite hasta 3, con títulos de hasta 20
     * caracteres.
     *
     * @param  array<string, string>  $botones  id => título
     * @return string El id que Meta le asignó al mensaje.
     *
     * @throws RequestException si Meta rechaza el envío o no responde.
     * @throws RuntimeException si Meta acepta el envío sin devolver un id.
     */
    public function enviarBotones(WhatsappNumero $numero, WhatsappConversacion $conversacion, string $texto, array $botones): string
    {
        $botones = array_slice($botones, 0, self::MAX_BOTONES, true);

        return $this->enviar($numero, $conversacion, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $texto],
                'action' => [
                    'buttons' => array_map(
                        fn (string $id, string $titulo) => ['type' => 'reply', 'reply' => ['id' => $id, 'title' => mb_substr($titulo, 0, self::MAX_TITULO_BOTON)]],
                        array_keys($botones),
                        array_values($botones),
                    ),
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $contenido
     */
    private function enviar(WhatsappNumero $numero, WhatsappConversacion $conversacion, array $contenido): string
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
                ...$contenido,
            ])
            ->throw();

        $wamid = $respuesta->json('messages.0.id');

        if (! is_string($wamid) || $wamid === '') {
            throw new RuntimeException('Meta aceptó el mensaje sin devolver su id.');
        }

        return $wamid;
    }
}
