<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fila de la libreta de saldo de un conductor (spec tenant/022, SPEC-023). Solo se crea, nunca se
 * edita ni se borra: ver `App\Services\SaldoService`.
 */
#[Fillable(['id_conductor', 'id_pedido', 'id_usuario', 'tipo', 'monto', 'saldo_resultante', 'referencia'])]
class MovimientoSaldo extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'movimientos_saldo';

    protected $primaryKey = 'id_movimiento';

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'saldo_resultante' => 'decimal:2',
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

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }
}
