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

    /**
     * Rectángulo que envuelve los vértices de todas las zonas `Activo` con polígono guardado, para
     * acotar el autocompletado de direcciones al área de servicio del tenant (spec
     * `tenant/016-geocerca-area-servicio.md`). `null` si ninguna zona activa tiene polígono.
     *
     * @return array{north: float, south: float, east: float, west: float}|null
     */
    public static function boundsDeZonasActivas(): ?array
    {
        $puntos = static::where('estado', 'Activo')
            ->whereNotNull('poligono')
            ->pluck('poligono')
            ->flatten(1);

        if ($puntos->isEmpty()) {
            return null;
        }

        return [
            'north' => (float) $puntos->max('lat'),
            'south' => (float) $puntos->min('lat'),
            'east' => (float) $puntos->max('lng'),
            'west' => (float) $puntos->min('lng'),
        ];
    }
}
