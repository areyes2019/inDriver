<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Observers\WhatsappBandejaObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_conversacion', 'wamid', 'direccion', 'tipo', 'texto', 'latitud', 'longitud', 'boton', 'id_usuario', 'estado_envio', 'enviado_en', 'respondido_en'])]
#[ObservedBy([WhatsappBandejaObserver::class])]
class WhatsappMensaje extends Model
{
    public const ENTRANTE = 'ENTRANTE';

    public const SALIENTE = 'SALIENTE';

    /** Un aviso de estado del pedido: lo manda el sistema, no contesta al cliente (spec tenant/031, 7a). */
    public const TIPO_AVISO = 'aviso';

    protected $table = 'whatsapp_mensajes';

    protected $primaryKey = 'id_mensaje';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enviado_en' => 'datetime',
            'respondido_en' => 'datetime',
            'latitud' => 'float',
            'longitud' => 'float',
        ];
    }

    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversacion::class, 'id_conversacion', 'id_conversacion');
    }

    /** Quién del Panel lo escribió (etapa 7b); nulo para el bot y el cliente. */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }
}
