<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\ConfiguracionTenant;
use Illuminate\Support\Carbon;

/**
 * Cuándo recibe pedidos la app de clientes (spec tenant/035, RN-28 y RN-29) y de cuánto es la
 * ventana de un programado.
 *
 * El horario vive en `configuraciones_tenant.horario_atencion` (ver la constante). Sin horario, o
 * con uno que no se puede leer, la flotilla atiende las 24 horas: un JSON mal guardado no debe
 * dejar a la app sin pedidos.
 */
final class HorarioAtencion
{
    public const VENTANA_POR_DEFECTO = 60;

    private const VENTANA_MINIMA = 15;

    private const VENTANA_MAXIMA = 240;

    private const DIAS = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];

    /**
     * @param  array<int, array{0: string, 1: string}|null>|null  $dias  `null` = 24 horas.
     */
    private function __construct(private readonly ?array $dias) {}

    public static function deLaFlotilla(): self
    {
        $valor = ConfiguracionTenant::obtener(ConfiguracionTenant::HORARIO_ATENCION);
        $datos = $valor !== null ? json_decode($valor, true) : null;

        if (! is_array($datos)) {
            return new self(null);
        }

        $dias = [];
        foreach (array_keys(self::DIAS) as $dia) {
            $tramo = $datos[$dia] ?? $datos[(string) $dia] ?? null;
            $dias[$dia] = self::tramoValido($tramo) ? [$tramo[0], $tramo[1]] : null;
        }

        return new self($dias);
    }

    /** Minutos de la ventana de un programado (RN-28), dentro de 15 a 240. */
    public static function ventanaMinutos(): int
    {
        $valor = (int) ConfiguracionTenant::obtener(ConfiguracionTenant::VENTANA_PROGRAMADO_MIN, (string) self::VENTANA_POR_DEFECTO);

        return $valor < self::VENTANA_MINIMA || $valor > self::VENTANA_MAXIMA ? self::VENTANA_POR_DEFECTO : $valor;
    }

    public function veinticuatroHoras(): bool
    {
        return $this->dias === null;
    }

    /** ¿Se atiende en este instante? Se compara en la hora local de la flotilla. */
    public function abiertoEn(Carbon $momento): bool
    {
        if ($this->dias === null) {
            return true;
        }

        $local = $momento->copy()->setTimezone(HoraOperacion::zona());
        $tramo = $this->dias[$local->dayOfWeekIso] ?? null;

        if ($tramo === null) {
            return false;
        }

        $hora = $local->format('H:i');

        return $hora >= $tramo[0] && $hora < $tramo[1];
    }

    public function abiertoAhora(): bool
    {
        return $this->abiertoEn(now());
    }

    /** Texto corto para la app: "Todos los días, a cualquier hora" o "Lun 09:00–20:00 · …". */
    public function texto(): string
    {
        if ($this->dias === null) {
            return 'Todos los días, a cualquier hora';
        }

        $partes = [];
        foreach ($this->dias as $dia => $tramo) {
            if ($tramo !== null) {
                $partes[] = self::DIAS[$dia].' '.$tramo[0].'–'.$tramo[1];
            }
        }

        return $partes === [] ? 'Por ahora no estamos recibiendo pedidos' : implode(' · ', $partes);
    }

    private static function tramoValido(mixed $tramo): bool
    {
        return is_array($tramo)
            && count($tramo) === 2
            && is_string($tramo[0] ?? null) && is_string($tramo[1] ?? null)
            && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $tramo[0]) === 1
            && preg_match('/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/', $tramo[1]) === 1
            && $tramo[0] < $tramo[1];
    }
}
