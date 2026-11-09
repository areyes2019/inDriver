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
     * Un aviso con plantilla aprobada (RN-27 y RN-49). Se manda a un teléfono, porque un pedido del
     * Panel no siempre tiene conversación.
     *
     * @param  list<string>  $variables  En el orden de los `{{n}}` de la plantilla.
     * @return string El id que Meta le asignó al mensaje.
     *
     * @throws RequestException si Meta rechaza el envío, por ejemplo con la plantilla sin aprobar.
     * @throws RuntimeException si Meta acepta el envío sin devolver un id.
     */
    public function enviarPlantilla(WhatsappNumero $numero, string $telefono, string $plantilla, array $variables): string
    {
        return $this->enviar($numero, ['to' => $telefono], [
            'type' => 'template',
            'template' => [
                'name' => $plantilla,
                'language' => ['code' => PlantillasWhatsapp::IDIOMA],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(fn (string $valor) => ['type' => 'text', 'text' => $valor], $variables),
                ]],
            ],
        ]);
    }

    /**
     * Da de alta una plantilla de utilidad en la cuenta de WhatsApp (RN-53).
     *
     * @param  list<string>  $ejemplo
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function crearPlantilla(WhatsappNumero $numero, string $nombre, string $texto, array $ejemplo): array
    {
        return Http::withToken($numero->access_token)
            ->timeout(15)
            ->post($this->urlGraph("{$numero->waba_id}/message_templates"), [
                'name' => $nombre,
                'language' => PlantillasWhatsapp::IDIOMA,
                'category' => 'UTILITY',
                'components' => [[
                    'type' => 'BODY',
                    'text' => $texto,
                    'example' => ['body_text' => [$ejemplo]],
                ]],
            ])
            ->throw()
            ->json();
    }

    /**
     * El estado de cada plantilla en Meta: APPROVED, PENDING, REJECTED, PAUSED…
     *
     * @return array<string, string> nombre => estado
     *
     * @throws RequestException
     */
    public function estadoPlantillas(WhatsappNumero $numero): array
    {
        $plantillas = Http::withToken($numero->access_token)
            ->timeout(15)
            ->get($this->urlGraph("{$numero->waba_id}/message_templates"), ['fields' => 'name,status,language', 'limit' => 100])
            ->throw()
            ->json('data') ?? [];

        return collect($plantillas)
            ->filter(fn ($p) => ($p['language'] ?? null) === PlantillasWhatsapp::IDIOMA)
            ->mapWithKeys(fn ($p) => [(string) $p['name'] => (string) $p['status']])
            ->all();
    }

    /**
     * @param  WhatsappConversacion|array{to: string}  $destino
     * @param  array<string, mixed>  $contenido
     */
    private function enviar(WhatsappNumero $numero, WhatsappConversacion|array $destino, array $contenido): string
    {
        $url = $this->urlGraph("{$numero->phone_number_id}/messages");

        // Meta puede omitir el teléfono de quien adoptó un nombre de usuario (RN-12).
        $destinatario = match (true) {
            is_array($destino) => $destino,
            $destino->telefono !== null => ['to' => $destino->telefono],
            default => ['recipient' => $destino->usuario_whatsapp],
        };

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

    private function urlGraph(string $ruta): string
    {
        return sprintf('https://graph.facebook.com/%s/%s', config('services.whatsapp.graph_version'), $ruta);
    }
}
