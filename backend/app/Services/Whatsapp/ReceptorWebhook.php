<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Jobs\ProcesarMensajeWhatsapp;
use App\Models\Tenant\WhatsappConversacion;
use App\Models\Tenant\WhatsappMensaje;
use App\Models\WhatsappNumero;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Guarda lo que llega por el webhook en la base de la flotilla dueña del número (spec tenant/031).
 *
 * Solo guarda y encola, para responderle a Meta enseguida: lo demás corre en `ProcesarMensajeWhatsapp`.
 * Un POST puede traer varias entradas, cambios y mensajes, incluso de números distintos.
 */
class ReceptorWebhook
{
    /** Los avisos de Meta pueden llegar desordenados: un "entregado" tardío no deshace un "leído". */
    private const AVANCE_ESTADO = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recibir(array $payload): void
    {
        foreach (Arr::wrap($payload['entry'] ?? []) as $entrada) {
            foreach (Arr::wrap($entrada['changes'] ?? []) as $cambio) {
                if (($cambio['field'] ?? null) === 'messages' && is_array($cambio['value'] ?? null)) {
                    $this->procesarCambio($cambio['value']);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $valor
     */
    private function procesarCambio(array $valor): void
    {
        $idTelefono = $valor['metadata']['phone_number_id'] ?? null;
        $numero = is_string($idTelefono)
            ? WhatsappNumero::with('tenant')->where('phone_number_id', $idTelefono)->where('estado', 'Activo')->first()
            : null;

        if ($numero === null) {
            Log::warning('WhatsApp: llegó un evento de un número sin conectar.', ['phone_number_id' => $idTelefono]);

            return;
        }

        if ($numero->tenant?->estado !== 'Activo') {
            Log::warning('WhatsApp: llegó un evento de una flotilla no activa.', ['id_tenant' => $numero->id_tenant]);

            return;
        }

        tenancy()->initialize($numero->tenant);

        try {
            foreach (Arr::wrap($valor['messages'] ?? []) as $mensaje) {
                $this->guardarMensaje($numero, $mensaje, Arr::wrap($valor['contacts'] ?? []));
            }

            foreach (Arr::wrap($valor['statuses'] ?? []) as $estado) {
                $this->actualizarEstado($estado);
            }
        } finally {
            tenancy()->end();
        }
    }

    /**
     * @param  array<string, mixed>  $mensaje
     * @param  array<int, array<string, mixed>>  $contactos
     */
    private function guardarMensaje(WhatsappNumero $numero, array $mensaje, array $contactos): void
    {
        $wamid = $mensaje['id'] ?? null;
        $telefono = $mensaje['from'] ?? null;
        $usuario = $mensaje['from_user_id'] ?? $telefono;

        if (! is_string($wamid) || ! is_string($usuario)) {
            Log::warning('WhatsApp: mensaje sin id o sin remitente.', ['tipo' => $mensaje['type'] ?? null]);

            return;
        }

        $contacto = collect($contactos)->first(
            fn ($c) => ($c['user_id'] ?? null) === $usuario || ($c['wa_id'] ?? null) === $telefono,
        );

        $conversacion = WhatsappConversacion::firstOrCreate(['usuario_whatsapp' => $usuario]);
        $conversacion->fill(array_filter([
            'telefono' => $telefono,
            'nombre_perfil' => $contacto['profile']['name'] ?? null,
        ]));
        $conversacion->ultimo_mensaje_en = now();
        $conversacion->save();

        $guardado = WhatsappMensaje::firstOrCreate(['wamid' => $wamid], [
            'id_conversacion' => $conversacion->id_conversacion,
            'direccion' => WhatsappMensaje::ENTRANTE,
            'tipo' => (string) ($mensaje['type'] ?? 'desconocido'),
            'texto' => $mensaje['text']['body'] ?? null,
            'enviado_en' => isset($mensaje['timestamp']) ? Carbon::createFromTimestamp((int) $mensaje['timestamp']) : now(),
        ]);

        // Un reintento de Meta ya se atendió la primera vez (RN-21).
        if ($guardado->wasRecentlyCreated) {
            ProcesarMensajeWhatsapp::dispatch($numero->tenant, $numero->id_numero, $guardado->id_mensaje);
        }
    }

    /**
     * Solo cambia el mensaje existente: tres avisos por mensaje no deben crear tres filas (RN-31).
     *
     * @param  array<string, mixed>  $estado
     */
    private function actualizarEstado(array $estado): void
    {
        $nuevo = $estado['status'] ?? null;

        if (! isset(self::AVANCE_ESTADO[$nuevo]) || ! is_string($estado['id'] ?? null)) {
            return;
        }

        $mensaje = WhatsappMensaje::where('wamid', $estado['id'])->first();

        if ($mensaje !== null && self::AVANCE_ESTADO[$nuevo] > (self::AVANCE_ESTADO[$mensaje->estado_envio] ?? 0)) {
            $mensaje->update(['estado_envio' => $nuevo]);
        }
    }
}
