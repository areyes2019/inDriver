<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_conductor', 'cantidad_viajes', 'id_usuario', 'fecha_venta'])]
class VentaViajeConductor extends Model
{
    protected $table = 'ventas_viajes_conductor';

    protected $primaryKey = 'id_venta';

    protected function casts(): array
    {
        return [
            'fecha_venta' => 'datetime',
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
}
