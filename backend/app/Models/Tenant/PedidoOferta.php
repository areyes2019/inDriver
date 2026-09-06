<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una oferta de pedido a un conductor concreto (spec tenant/020, SPEC-020): quién la recibió,
 * hasta cuándo puede responder, y en qué quedó. `App\Services\OfertaPedidoService` es el único
 * lugar que las crea y las resuelve.
 */
#[Fillable(['id_pedido', 'id_conductor', 'estado', 'ofrecida_en', 'respondida_en', 'expira_en'])]
class PedidoOferta extends Model
{
    protected $table = 'pedido_ofertas';

    protected $primaryKey = 'id_oferta';

    protected function casts(): array
    {
        return [
            'ofrecida_en' => 'datetime',
            'respondida_en' => 'datetime',
            'expira_en' => 'datetime',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'id_pedido', 'id_pedido');
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Conductor::class, 'id_conductor', 'id_conductor');
    }
}
