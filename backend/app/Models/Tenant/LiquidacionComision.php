<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un pago de comisión que el tenant registró a un conductor (spec tenant/030). Solo se crea, nunca
 * se edita ni se borra: ver `App\Services\LiquidacionComisionService`.
 */
#[Fillable(['id_conductor', 'id_usuario', 'monto', 'referencia'])]
class LiquidacionComision extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'liquidaciones_comision';

    protected $primaryKey = 'id_liquidacion';

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Conductor::class, 'id_conductor', 'id_conductor');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }

    /** Los viajes que este pago dejó cubiertos por completo (FIFO, spec tenant/030). */
    public function comisiones(): HasMany
    {
        return $this->hasMany(ComisionConductor::class, 'id_liquidacion', 'id_liquidacion');
    }
}
