<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Whatsapp\ReceptorWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * La puerta de entrada de Meta (spec tenant/031). Es una URL pública y sin tenant: una sola para
 * todas las flotillas. Lo único que la protege es la firma.
 */
class WhatsappWebhookController extends Controller
{
    /**
     * Meta comprueba que la URL es nuestra al configurarla.
     */
    public function verificar(Request $request): Response
    {
        $esperado = (string) config('services.whatsapp.verify_token');
        $recibido = (string) $request->query('hub_verify_token', '');

        if ($request->query('hub_mode') !== 'subscribe' || $esperado === '' || ! hash_equals($esperado, $recibido)) {
            return response('', 403);
        }

        return response((string) $request->query('hub_challenge', ''), 200, ['Content-Type' => 'text/plain']);
    }

    public function recibir(Request $request, ReceptorWebhook $receptor): Response
    {
        $cuerpo = $request->getContent();

        if (! $this->firmaValida($cuerpo, (string) $request->header('X-Hub-Signature-256'))) {
            return response('', 403);
        }

        $payload = json_decode($cuerpo, true);

        // Si guardar falla, la excepción responde 500 y Meta reintenta: no se pierde el mensaje.
        if (is_array($payload)) {
            $receptor->recibir($payload);
        }

        return response('', 200);
    }

    /**
     * Sobre el cuerpo crudo: decodificarlo y volver a codificarlo cambiaría los bytes firmados. Sin
     * secreto configurado no se acepta nada.
     */
    private function firmaValida(string $cuerpo, string $firma): bool
    {
        $secreto = (string) config('services.whatsapp.app_secret');

        return $secreto !== '' && hash_equals('sha256='.hash_hmac('sha256', $cuerpo, $secreto), $firma);
    }
}
