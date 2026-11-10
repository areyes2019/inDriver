<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Observers\WhatsappBandejaObserver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['usuario_whatsapp', 'telefono', 'nombre_perfil', 'id_cliente', 'ultimo_mensaje_en', 'pide_persona_en', 'bot_pausado_hasta'])]
#[ObservedBy([WhatsappBandejaObserver::class])]
class WhatsappConversacion extends Model
{
    protected $table = 'whatsapp_conversaciones';

    protected $primaryKey = 'id_conversacion';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ultimo_mensaje_en' => 'datetime',
            'pide_persona_en' => 'datetime',
            'bot_pausado_hasta' => 'datetime',
        ];
    }

    /** RN-58: lo que dura la pausa del bot cuando una persona toma la conversación. */
    public const PAUSA_BOT_HORAS = 2;

    /** Fuera de esta ventana, Meta solo admite plantillas (RN-27, RN-57). */
    public const VENTANA_HORAS = 24;

    public function botPausado(): bool
    {
        return $this->bot_pausado_hasta !== null && $this->bot_pausado_hasta->isFuture();
    }

    /** RN-58 y RN-59: el bot se calla dos horas para que una persona atienda. */
    public function pausarBot(): void
    {
        $this->bot_pausado_hasta = now()->addHours(self::PAUSA_BOT_HORAS);
    }

    /**
     * RN-57: hasta cuándo se le puede escribir texto libre, contado desde el último mensaje del
     * cliente. Nulo si el cliente nunca escribió.
     */
    public function ventanaCierraEn(): ?Carbon
    {
        $ultimo = $this->mensajes()->where('direccion', WhatsappMensaje::ENTRANTE)->max('enviado_en');

        return $ultimo !== null ? Carbon::parse($ultimo)->addHours(self::VENTANA_HORAS) : null;
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }

    /** La fila más reciente, para la vista previa y el orden de la bandeja (RN-55). */
    public function ultimoMensaje(): HasOne
    {
        return $this->hasOne(WhatsappMensaje::class, 'id_conversacion', 'id_conversacion')->latestOfMany('id_mensaje');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(WhatsappMensaje::class, 'id_conversacion', 'id_conversacion');
    }
}
