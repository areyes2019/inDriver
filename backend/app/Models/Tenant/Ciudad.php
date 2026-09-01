<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['nombre', 'place_id', 'lat', 'lng', 'bounds'])]
class Ciudad extends Model
{
    protected $table = 'ciudades';

    protected $primaryKey = 'id_ciudad';

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'bounds' => 'array',
        ];
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'usuario_ciudades', 'id_ciudad', 'id_usuario');
    }
}
