<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['usuario_whatsapp', 'telefono', 'nombre_perfil', 'id_cliente', 'ultimo_mensaje_en'])]
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
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(WhatsappMensaje::class, 'id_conversacion', 'id_conversacion');
    }
}
