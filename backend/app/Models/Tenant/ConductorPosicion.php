<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_conductor', 'latitud', 'longitud', 'precision', 'velocidad', 'rumbo', 'bateria', 'fecha_posicion'])]
class ConductorPosicion extends Model
{
    protected $table = 'conductor_posiciones';

    protected $primaryKey = 'id_posicion';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'latitud' => 'decimal:7',
            'longitud' => 'decimal:7',
            'precision' => 'decimal:2',
            'velocidad' => 'decimal:2',
            'fecha_posicion' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Conductor::class, 'id_conductor', 'id_conductor');
    }
}
