<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_conductor', 'placa', 'marca', 'modelo', 'anio', 'color', 'tipo', 'numero_economico', 'estado'])]
class Vehiculo extends Model
{
    protected $table = 'vehiculos';

    protected $primaryKey = 'id_vehiculo';

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Conductor::class, 'id_conductor', 'id_conductor');
    }
}
