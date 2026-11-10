<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\WhatsappConversacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una fila de la bandeja (spec tenant/031, RN-55). El detalle con los mensajes lo arma el
 * controlador encima de esto.
 *
 * @mixin WhatsappConversacion
 */
class WhatsappConversacionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ultimo = $this->relationLoaded('ultimoMensaje') ? $this->ultimoMensaje : null;

        return [
            'id_conversacion' => $this->id_conversacion,
            'nombre' => $this->cliente?->nombre ?? $this->nombre_perfil ?? $this->telefono ?? 'Sin nombre',
            'telefono' => $this->telefono,
            'id_cliente' => $this->id_cliente,
            'pide_persona' => $this->pide_persona_en !== null,
            'pide_persona_en' => $this->pide_persona_en,
            'bot_pausado_hasta' => $this->botPausado() ? $this->bot_pausado_hasta : null,
            'ultimo_mensaje' => $ultimo !== null ? [
                'texto' => $ultimo->texto,
                'tipo' => $ultimo->tipo,
                'entrante' => $ultimo->direccion === 'ENTRANTE',
                'enviado_en' => $ultimo->enviado_en,
            ] : null,
        ];
    }
}
