<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * La hora en la que opera la flotilla (spec tenant/031).
 *
 * El sistema guarda y compara sus fechas en UTC (`app.timezone`), pero `fecha_servicio`, `hora_desde`
 * y `hora_hasta` se capturan en hora local y se guardan sin zona. Cualquier cálculo con esos campos
 * pasa por aquí: leídas como UTC, un agendado de las 17:00 se publicaba 6 horas antes.
 */
final class HoraOperacion
{
    public static function zona(): string
    {
        return (string) config('app.timezone_operacion');
    }

    /** La fecha de hoy para la flotilla, 'YYYY-MM-DD'. */
    public static function hoy(): string
    {
        return now()->setTimezone(self::zona())->toDateString();
    }

    /** El instante real de una fecha y una hora de servicio. Se compara bien contra `now()`. */
    public static function momento(string $fecha, string $hora): Carbon
    {
        return Carbon::parse("{$fecha} {$hora}", self::zona());
    }
}
