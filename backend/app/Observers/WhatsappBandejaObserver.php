<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\Tenant\ConversacionWhatsappActualizada;
use App\Models\Tenant\WhatsappConversacion;
use App\Models\Tenant\WhatsappMensaje;
use Illuminate\Support\Facades\Log;

/**
 * Le avisa a la bandeja del Panel que una conversación cambió (spec tenant/031, D22). Cubre los
 * mensajes del cliente, del bot, de los avisos y del despachador sin que cada uno lo recuerde.
 */
class WhatsappBandejaObserver
{
    /** Que el aviso salga después de guardar: si la transacción se revierte no hay nada que ver. */
    public bool $afterCommit = true;

    public function created(WhatsappMensaje|WhatsappConversacion $modelo): void
    {
        if ($modelo instanceof WhatsappMensaje) {
            $this->avisar($modelo->id_conversacion);
        }
    }

    /** Tomarla, devolverla al bot o pedir una persona cambian la marca de la lista (RN-55). */
    public function updated(WhatsappMensaje|WhatsappConversacion $modelo): void
    {
        if ($modelo instanceof WhatsappConversacion && $modelo->wasChanged(['pide_persona_en', 'bot_pausado_hasta'])) {
            $this->avisar($modelo->id_conversacion);
        }
    }

    /**
     * Un socket caído no puede tumbar la recepción de un mensaje: la bandeja vuelve a pedir la
     * lista al reconectarse.
     */
    private function avisar(int $idConversacion): void
    {
        $slug = tenant()?->slug;

        if ($slug === null) {
            return;
        }

        try {
            ConversacionWhatsappActualizada::dispatch($idConversacion, $slug);
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar a la bandeja de WhatsApp.', [
                'id_conversacion' => $idConversacion,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
