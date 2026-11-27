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

    public const KM_INCLUIDOS = 'km_incluidos_banderazo';

    public const KM_ADICIONAL = 'tarifa_km_adicional';

    public const MODALIDAD = 'modalidad_conductores';

    public const COSTO_VIAJE_PREPAGO = 'costo_viaje_prepago';

    public const COMISION_PORCENTAJE = 'comision_porcentaje';

    public const USAR_DESPACHADORES = 'usar_despachadores';

    /** Modo automático (spec tenant/034): `No`, `Si` o `Pausado`. Sin fijar, `No`. */
    public const MODO_AUTOMATICO = 'modo_automatico';

    /** Última vez que el modo automático pasó a `Si` (spec tenant/034, RN-04 y RN-24). */
    public const MODO_AUTOMATICO_DESDE = 'modo_automatico_desde';

    /** App de clientes (spec tenant/035, D17): `No` o `Si`. Sin fijar, `No`. */
    public const APP_CLIENTES = 'app_clientes';

    /**
     * Horario de atención de la app de clientes (spec tenant/035, RN-28 y RN-29): JSON por día ISO
     * (`1` lunes … `7` domingo) con `["HH:mm", "HH:mm"]` o `null` si ese día no se atiende. Sin
     * fijar, se atiende las 24 horas. Ver `App\Support\HorarioAtencion`.
     */
    public const HORARIO_ATENCION = 'horario_atencion';

    /** Minutos de la ventana de un pedido programado desde la app (RN-28): 15 a 240, 60 por defecto. */
    public const VENTANA_PROGRAMADO_MIN = 'ventana_programado_min';

    /**
     * Logo y banner de la app de clientes (spec tenant/035, RN-60 y RN-61): la marca de versión de
     * los archivos guardados por `App\Support\MarcaFlotilla`. Sin fijar, la app usa los de MotoPanda.
     */
    public const APP_LOGO = 'app_logo';

    public const APP_BANNER = 'app_banner';

    public static function appClientesPrendida(): bool
    {
        return static::obtener(self::APP_CLIENTES, 'No') === 'Si';
    }

    public static function obtener(string $clave, ?string $default = null): ?string
    {
        return static::query()->where('clave', $clave)->value('valor') ?? $default;
    }

    public static function establecer(string $clave, ?string $valor): void
    {
        static::query()->updateOrCreate(['clave' => $clave], ['valor' => $valor]);
    }
}
