<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_conductor', 'estado', 'ultima_conexion', 'ultima_desconexion', 'ultima_latitud', 'ultima_longitud', 'ultima_actualizacion'])]
class ConductorEstado extends Model
{
    protected $table = 'conductor_estado';

    protected function casts(): array
    {
        return [
            'ultima_conexion' => 'datetime',
            'ultima_desconexion' => 'datetime',
            'ultima_latitud' => 'decimal:7',
            'ultima_longitud' => 'decimal:7',
            'ultima_actualizacion' => 'datetime',
        ];
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Conductor::class, 'id_conductor', 'id_conductor');
    }
}
