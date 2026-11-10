<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use App\Console\Commands\PurgarMensajesWhatsapp;
use App\Models\Tenant\WhatsappMensaje;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un mensaje tal como se ve en la bandeja del Panel (spec tenant/031, RN-56).
 *
 * @mixin WhatsappMensaje
 */
class WhatsappMensajeResource extends JsonResource
{
    /**
     * @param  array<string, string>  $folios  wamid de un aviso => folio de su pedido
     */
    public function __construct(WhatsappMensaje $mensaje, private readonly array $folios = [])
    {
        parent::__construct($mensaje);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tieneUbicacion = $this->latitud !== null && $this->longitud !== null;

        return [
            'id_mensaje' => $this->id_mensaje,
            'autor' => $this->autor(),
            'nombre_usuario' => $this->usuario !== null ? trim($this->usuario->nombre.' '.$this->usuario->apellido_paterno) : null,
            'tipo' => $this->tipo,
            'texto' => $this->texto,
            // RN-56 y RN-30: pasados los 90 días la fila sigue, pero su contenido ya no.
            'borrado' => $this->texto === null && ! $tieneUbicacion
                && $this->enviado_en->lt(now()->subDays(PurgarMensajesWhatsapp::RETENCION_DIAS)),
            'mapa_url' => $tieneUbicacion ? "https://www.google.com/maps?q={$this->latitud},{$this->longitud}" : null,
            'boton' => $this->boton,
            'folio' => $this->folios[$this->wamid] ?? null,
            'estado_envio' => $this->estado_envio,
            'enviado_en' => $this->enviado_en,
        ];
    }

    /**
     * @return 'cliente'|'bot'|'persona'|'aviso'
     */
    private function autor(): string
    {
        return match (true) {
            $this->direccion === WhatsappMensaje::ENTRANTE => 'cliente',
            $this->tipo === WhatsappMensaje::TIPO_AVISO => 'aviso',
            $this->id_usuario !== null => 'persona',
            default => 'bot',
        };
    }
}
