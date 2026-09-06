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

    /**
     * ¿El punto cae dentro de alguna zona `Activo` con polígono? (spec tenant/022, RN-15,
     * `DROPOFF_OUT_OF_COVERAGE`). Si ningún tenant activo tiene un polígono cargado, no hay nada
     * que restrinja la cobertura y se considera cubierto — la geocerca es opcional (spec
     * tenant/016).
     */
    public static function cubrePunto(float $lat, float $lng): bool
    {
        $zonas = static::where('estado', 'Activo')->whereNotNull('poligono')->get(['poligono']);

        if ($zonas->isEmpty()) {
            return true;
        }

        return $zonas->contains(fn (self $zona) => $zona->contienePunto($lat, $lng));
    }

    /**
     * Ray casting estándar: cuenta cuántas veces un rayo horizontal desde el punto cruza los
     * lados del polígono. Impar = adentro, par = afuera.
     */
    public function contienePunto(float $lat, float $lng): bool
    {
        $vertices = $this->poligono ?? [];
        $total = count($vertices);
        $dentro = false;

        for ($i = 0, $j = $total - 1; $i < $total; $j = $i++) {
            $latI = (float) $vertices[$i]['lat'];
            $lngI = (float) $vertices[$i]['lng'];
            $latJ = (float) $vertices[$j]['lat'];
            $lngJ = (float) $vertices[$j]['lng'];

            $cruza = ($latI > $lat) !== ($latJ > $lat)
                && $lng < ($lngJ - $lngI) * ($lat - $latI) / ($latJ - $latI) + $lngI;

            if ($cruza) {
                $dentro = ! $dentro;
            }
        }

        return $dentro;
    }
}
