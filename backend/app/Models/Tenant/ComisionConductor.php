<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La comisión que un envío entregado generó a favor de su conductor (spec tenant/030). Una fila
 * por envío (`UNIQUE id_pedido`), con el importe base y el porcentaje congelados al momento de
 * entregar. Solo cambia de `PENDIENTE` a `PAGADA`, y eso lo hace únicamente
 * `LiquidacionComisionService`.
 */
#[Fillable([
    'id_conductor',
    'id_pedido',
    'id_liquidacion',
    'importe_envio',
    'porcentaje_aplicado',
    'importe_comision',
    'estado',
    'pagada_en',
])]
class ComisionConductor extends Model
{
    public const UPDATED_AT = null;

    public const ESTADO_PENDIENTE = 'PENDIENTE';

    public const ESTADO_PAGADA = 'PAGADA';

    protected $table = 'comisiones_conductor';

    protected $primaryKey = 'id_comision';

    protected function casts(): array
    {
        return [
            'importe_envio' => 'decimal:2',
            'porcentaje_aplicado' => 'decimal:2',
            'importe_comision' => 'decimal:2',
            'pagada_en' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Conductor::class, 'id_conductor', 'id_conductor');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'id_pedido', 'id_pedido');
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(LiquidacionComision::class, 'id_liquidacion', 'id_liquidacion');
    }
}
