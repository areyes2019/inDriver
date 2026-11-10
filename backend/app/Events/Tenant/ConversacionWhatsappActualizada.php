<?php

declare(strict_types=1);

namespace App\Events\Tenant;

use App\Models\Tenant\WhatsappConversacion;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Algo cambió en una conversación de WhatsApp: llegó o salió un mensaje, o alguien la tomó o la
 * devolvió al bot (spec tenant/031, D22 y RN-55).
 *
 * Va al canal del Panel y no al de conductores: ese lo escucha la app de cada repartidor, y una
 * conversación con clientes no es asunto suyo. Tampoco lleva el texto: el Panel vuelve a pedir la
 * conversación, así que el socket solo dice "mira esta".
 */
class ConversacionWhatsappActualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $idConversacion, public readonly string $tenantSlug) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantSlug}.panel")];
    }

    public function broadcastAs(): string
    {
        return 'whatsapp.conversacion';
    }

    /**
     * @return array{id_conversacion: int, esperando: int}
     */
    public function broadcastWith(): array
    {
        return [
            'id_conversacion' => $this->idConversacion,
            'esperando' => WhatsappConversacion::whereNotNull('pide_persona_en')->count(),
        ];
    }
}
