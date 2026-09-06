<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_pedido', 'direccion_nueva', 'latitud_nueva', 'longitud_nueva', 'distancia_extra_km', 'cargo_extra', 'pago_extra', 'usada', 'expira_en'])]
class PedidoCotizacion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'pedido_cotizaciones';

    protected $primaryKey = 'id_cotizacion';

    protected function casts(): array
    {
        return [
            'latitud_nueva' => 'decimal:7',
            'longitud_nueva' => 'decimal:7',
            'distancia_extra_km' => 'decimal:2',
            'cargo_extra' => 'decimal:2',
            'pago_extra' => 'decimal:2',
            'usada' => 'boolean',
            'expira_en' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'id_pedido', 'id_pedido');
    }

    public function estaVigente(): bool
    {
        return ! $this->usada && $this->expira_en->isFuture();
    }
}
