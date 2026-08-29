<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['clave', 'valor'])]
class ConfiguracionTenant extends Model
{
    protected $table = 'configuraciones_tenant';

    protected $primaryKey = 'id_configuracion';

    public const BANDERAZO = 'tarifa_banderazo';

    public const KM_ADICIONAL = 'tarifa_km_adicional';

    public const MODALIDAD = 'modalidad_conductores';

    public const COSTO_VIAJE_PREPAGO = 'costo_viaje_prepago';

    public const COMISION_PORCENTAJE = 'comision_porcentaje';

    public static function obtener(string $clave, ?string $default = null): ?string
    {
        return static::query()->where('clave', $clave)->value('valor') ?? $default;
    }

    public static function establecer(string $clave, ?string $valor): void
    {
        static::query()->updateOrCreate(['clave' => $clave], ['valor' => $valor]);
    }
}
