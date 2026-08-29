<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['nombre', 'descripcion', 'estado', 'poligono'])]
class ZonaServicio extends Model
{
    protected $table = 'zonas_servicio';

    protected $primaryKey = 'id_zona';

    protected function casts(): array
    {
        return [
            'poligono' => 'array',
        ];
    }
}
