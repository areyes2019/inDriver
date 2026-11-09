<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant as TenantModel;
use App\Models\Tenant\WhatsappMensaje;
use App\Models\WhatsappNumero;
use App\Services\Whatsapp\ConversacionWhatsappService;
use App\Services\Whatsapp\WhatsappClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Atiende un mensaje recibido (spec tenant/031). Desde la etapa 6 lleva la conversación con la que
 * el cliente arma, confirma y crea su envío (`ConversacionWhatsappService`).
 *
 * Va en su propia cola para que una respuesta lenta no retrase `ExpirarOfertaPedido`.
 */
class ProcesarMensajeWhatsapp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const COLA = 'whatsapp';

    /** Fuera de esta ventana, Meta solo admite plantillas (RN-27). */
    private const VENTANA_HORAS = 24;

    public int $tries = 3;

    /** La IA (hasta ~30 s con su reintento), dos geocodificaciones y la ruta caben aquí. */
    public int $timeout = 90;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public function __construct(
        private readonly TenantModel $tenant,
        private readonly int $idNumero,
        private readonly int $idMensaje,
    ) {
        $this->onQueue(self::COLA);
    }

    public function handle(WhatsappClient $cliente, ConversacionWhatsappService $conversacion): void
    {
        // Mismo cuidado que `ExpirarOfertaPedido`: con `sync` corre dentro de la petición que ya abrió
        // el tenant, y cerrarlo aquí le cortaría el piso.
        $yaInicializado = tenancy()->initialized && tenancy()->tenant?->getTenantKey() === $this->tenant->getTenantKey();

        if (! $yaInicializado) {
            tenancy()->initialize($this->tenant);
        }

        try {
            $this->atender($cliente, $conversacion);
        } finally {
            if (! $yaInicializado) {
                tenancy()->end();
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('WhatsApp: no se pudo responder un mensaje.', [
            'id_tenant' => $this->tenant->getTenantKey(),
            'id_mensaje' => $this->idMensaje,
            'error' => $e->getMessage(),
        ]);
    }

    private function atender(WhatsappClient $cliente, ConversacionWhatsappService $conversacion): void
    {
        $mensaje = WhatsappMensaje::with('conversacion')->find($this->idMensaje);
        $numero = WhatsappNumero::find($this->idNumero);

        // `respondido_en` evita contestar dos veces si un reintento llega después de un envío correcto.
        if ($mensaje === null || $numero === null || $mensaje->respondido_en !== null) {
            return;
        }

        // Meta reintenta hasta 7 días: un mensaje que llegó tarde ya no se puede contestar con texto.
        if ($mensaje->enviado_en->lt(now()->subHours(self::VENTANA_HORAS))) {
            Log::info('WhatsApp: mensaje fuera de la ventana de 24 horas, no se responde.', ['id_mensaje' => $mensaje->id_mensaje]);

            return;
        }

        // RN-34: si el cliente ya escribió algo más que sigue sin respuesta, ese job contesta todo
        // junto con la conversación completa. Este se da por atendido sin enviar nada.
        if ($this->hayMensajeMasNuevoSinResponder($mensaje)) {
            $mensaje->update(['respondido_en' => now()]);

            return;
        }

        $respuesta = $conversacion->atender($mensaje->conversacion, $this->pendientes($mensaje), $this->tenant->telefono);

        $wamid = $respuesta->botones === []
            ? $cliente->enviarTexto($numero, $mensaje->conversacion, $respuesta->texto)
            : $cliente->enviarBotones($numero, $mensaje->conversacion, $respuesta->texto, $respuesta->botones);

        $mensaje->update(['respondido_en' => now()]);

        WhatsappMensaje::create([
            'id_conversacion' => $mensaje->id_conversacion,
            'wamid' => $wamid,
            'direccion' => WhatsappMensaje::SALIENTE,
            'tipo' => $respuesta->botones === [] ? 'text' : 'interactive',
            'texto' => $respuesta->texto,
            'enviado_en' => now(),
        ]);
    }

    /**
     * RN-34: lo que el cliente mandó desde la última respuesta del bot, incluidos los mensajes que
     * otros jobs dieron por atendidos para que este los contestara juntos.
     *
     * @return Collection<int, WhatsappMensaje>
     */
    private function pendientes(WhatsappMensaje $mensaje): Collection
    {
        $ultimaRespuesta = WhatsappMensaje::where('id_conversacion', $mensaje->id_conversacion)
            ->where('direccion', WhatsappMensaje::SALIENTE)
            ->max('id_mensaje') ?? 0;

        return WhatsappMensaje::where('id_conversacion', $mensaje->id_conversacion)
            ->where('direccion', WhatsappMensaje::ENTRANTE)
            ->where('id_mensaje', '>', $ultimaRespuesta)
            ->where('id_mensaje', '<=', $mensaje->id_mensaje)
            ->orderBy('id_mensaje')
            ->get();
    }

    private function hayMensajeMasNuevoSinResponder(WhatsappMensaje $mensaje): bool
    {
        return WhatsappMensaje::where('id_conversacion', $mensaje->id_conversacion)
            ->where('direccion', WhatsappMensaje::ENTRANTE)
            ->where('id_mensaje', '>', $mensaje->id_mensaje)
            ->whereNull('respondido_en')
            ->exists();
    }
}
