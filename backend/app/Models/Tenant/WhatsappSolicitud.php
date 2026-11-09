<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La hoja del pedido que se arma por WhatsApp (spec tenant/031, D18).
 */
#[Fillable([
    'id_conversacion', 'estado', 'paso', 'destinatario_nombre', 'destinatario_telefono',
    'entrega_texto', 'entrega_direccion', 'entrega_lat', 'entrega_lng',
    'recogida_texto', 'recogida_direccion', 'recogida_lat', 'recogida_lng', 'id_direccion_ofrecida',
    'quien_paga', 'cobro_producto', 'importe_producto', 'importe_envio', 'km', 'cliente_telefono', 'id_pedido',
])]
class WhatsappSolicitud extends Model
{
    public const ABIERTA = 'ABIERTA';

    public const CONFIRMANDO = 'CONFIRMANDO';

    public const CONFIRMADA = 'CONFIRMADA';

    public const CANCELADA = 'CANCELADA';

    public const DESCARTADA = 'DESCARTADA';

    /** RN-45. */
    public const HORAS_DE_VIDA = 2;

    protected $table = 'whatsapp_solicitudes';

    protected $primaryKey = 'id_solicitud';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entrega_lat' => 'float',
            'entrega_lng' => 'float',
            'recogida_lat' => 'float',
            'recogida_lng' => 'float',
            'importe_producto' => 'float',
            'importe_envio' => 'float',
            'km' => 'float',
        ];
    }

    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversacion::class, 'id_conversacion', 'id_conversacion');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'id_pedido', 'id_pedido');
    }

    public function tieneEntrega(): bool
    {
        return $this->entrega_lat !== null && $this->entrega_lng !== null;
    }

    public function tieneRecogida(): bool
    {
        return $this->recogida_lat !== null && $this->recogida_lng !== null;
    }
}
