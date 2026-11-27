<?php

declare(strict_types=1);

namespace App\Services\Cliente;

use App\Services\CotizacionPedidoService;
use App\Support\HoraOperacion;
use App\Support\HorarioAtencion;
use Illuminate\Validation\ValidationException;

/**
 * La cotización de la app de clientes (spec tenant/035, RN-21 a RN-29): la usan `cotizar` y la
 * confirmación del pedido cuando el sello ya venció (RN-24), para que las dos respondan igual.
 */
class CotizacionApp
{
    /** RN-28: un programado va al menos 30 minutos en el futuro. */
    public const MINUTOS_MINIMOS_PROGRAMADO = 30;

    /** Hasta cuántos días adelante se puede programar. */
    public const DIAS_MAXIMOS_PROGRAMADO = 7;

    public function __construct(
        private readonly CotizacionPedidoService $cotizacion,
        private readonly SelloCotizacion $sellos,
    ) {}

    /**
     * RN-27: el producto solo existe cuando quien recibe paga envío + producto; en las demás
     * modalidades se fuerza a 0.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function normalizar(array $datos): array
    {
        if ($datos['modalidad'] !== 'RECEPTOR_PAGA_ENVIO_PRODUCTOS') {
            $datos['importe_producto'] = 0;
        } elseif ((float) ($datos['importe_producto'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['importe_producto' => 'Escribe cuánto cuesta el producto.']);
        }

        return $datos;
    }

    /**
     * RN-28 y RN-29. Un programado fuera de tiempo o de horario es un error de captura (422 con el
     * campo); "lo antes posible" con la flotilla cerrada devuelve el motivo `FUERA_DE_HORARIO`.
     *
     * @param  array<string, mixed>  $datos
     */
    public function motivoDeHorario(array $datos): ?string
    {
        $horario = HorarioAtencion::deLaFlotilla();

        if ((bool) $datos['lo_antes_posible']) {
            return $horario->abiertoAhora() ? null : 'FUERA_DE_HORARIO';
        }

        $momento = HoraOperacion::momento((string) $datos['fecha'], (string) $datos['hora']);

        if ($momento->lt(now()->addMinutes(self::MINUTOS_MINIMOS_PROGRAMADO))) {
            throw ValidationException::withMessages([
                'hora' => 'Elige una hora al menos 30 minutos después de ahora.',
            ]);
        }

        if ($momento->gt(now()->addDays(self::DIAS_MAXIMOS_PROGRAMADO))) {
            throw ValidationException::withMessages([
                'fecha' => 'Puedes programar hasta 7 días adelante.',
            ]);
        }

        if (! $horario->abiertoEn($momento)) {
            throw ValidationException::withMessages([
                'hora' => 'Elige una hora dentro del horario: '.$horario->texto().'.',
            ]);
        }

        return null;
    }

    /**
     * Cotiza y sella. Devuelve el código HTTP y el cuerpo: 200 con la cotización, o 422 con
     * `motivo` (`FUERA_DE_ZONA` y `punto`, `SIN_RUTA` o `SIN_TARIFAS`).
     *
     * @param  array<string, mixed>  $datos  Ya validados y pasados por `normalizar()`.
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function cotizar(int $idCliente, array $datos): array
    {
        $resultado = $this->cotizacion->cotizar(
            ['lat' => (float) $datos['recogida']['lat'], 'lng' => (float) $datos['recogida']['lng']],
            ['lat' => (float) $datos['entrega']['lat'], 'lng' => (float) $datos['entrega']['lng']],
        );

        if (! $resultado->ok()) {
            return [422, array_filter([
                'motivo' => $resultado->motivo,
                'punto' => $resultado->punto,
            ])];
        }

        $condiciones = $this->sellos->condiciones($datos);
        $importeProducto = $condiciones['producto_centavos'] / 100;

        return [200, [
            'km' => round((float) $resultado->km, 1),
            'minutos' => $resultado->minutos,
            'importe_envio' => $resultado->importeEnvio,
            'importe_producto' => $importeProducto,
            'total' => $resultado->importeEnvio + $importeProducto,
            'sello' => $this->sellos->sellar(
                $idCliente,
                tenant()->slug,
                $condiciones,
                (float) $resultado->km,
                (float) $resultado->importeEnvio,
            ),
            'vence_en' => now()->addMinutes(SelloCotizacion::VIGENCIA_MINUTOS)->toIso8601String(),
        ]];
    }
}
