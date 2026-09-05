<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Manda push nativo vía la API HTTP v1 de Firebase Cloud Messaging (spec tenant/018) — solo como
 * servicio de envío de mensajes, sin ninguna base de datos de Firebase de por medio. Llama directo
 * con el cliente HTTP de Laravel en vez de instalar el SDK (`kreait/firebase-php`), firmando el JWT
 * de la cuenta de servicio con las funciones `openssl_*` que ya trae PHP — no agrega dependencias
 * nuevas a `composer.json`.
 */
class FcmSender
{
    /**
     * Si FCM no está configurado (`.env` sin credenciales), no manda nada y no revienta al
     * llamador — mismo criterio que `realtime.js` cuando Reverb no está configurado.
     *
     * @param  array<string, string>  $datos
     */
    public function enviar(string $fcmToken, string $titulo, string $cuerpo, array $datos = []): void
    {
        $projectId = config('services.fcm.project_id');
        $credentialsPath = config('services.fcm.credentials_path');

        if (! $projectId || ! $credentialsPath) {
            return;
        }

        $accessToken = $this->obtenerAccessToken($credentialsPath);

        if ($accessToken === null) {
            return;
        }

        Http::withToken($accessToken)
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $fcmToken,
                    'notification' => ['title' => $titulo, 'body' => $cuerpo],
                    'data' => $datos,
                ],
            ]);
    }

    /**
     * Intercambia la cuenta de servicio por un access token OAuth2 de Google, cacheado (los
     * tokens de Google duran 1 hora) para no firmar un JWT nuevo en cada push.
     */
    private function obtenerAccessToken(string $credentialsPath): ?string
    {
        return Cache::remember('fcm_access_token', now()->addMinutes(55), function () use ($credentialsPath) {
            if (! File::exists($credentialsPath)) {
                Log::warning('FcmSender: no se encontró el archivo de credenciales de Firebase.', ['path' => $credentialsPath]);

                return null;
            }

            $credenciales = json_decode(File::get($credentialsPath), true);
            $jwt = $this->firmarJwt($credenciales['client_email'], $credenciales['private_key']);

            $respuesta = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            return $respuesta->json('access_token');
        });
    }

    private function firmarJwt(string $clienteEmail, string $llavePrivada): string
    {
        $ahora = time();

        $header = $this->base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode((string) json_encode([
            'iss' => $clienteEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $ahora,
            'exp' => $ahora + 3600,
        ]));

        openssl_sign("{$header}.{$payload}", $firma, $llavePrivada, OPENSSL_ALGO_SHA256);

        return "{$header}.{$payload}.".$this->base64UrlEncode($firma);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
