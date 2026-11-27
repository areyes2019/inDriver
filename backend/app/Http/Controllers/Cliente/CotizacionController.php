<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cliente;

use App\Http\Controllers\Controller;
use App\Services\Cliente\SelloCotizacion;
use App\Services\CotizacionPedidoService;
use App\Services\GeocodificadorService;
use App\Support\HoraOperacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Zona de servicio, dirección de un punto y cotización para la app de clientes (spec tenant/035,
 * RN-20 a RN-28). La app nunca calcula ni manda un precio propio: muestra el que sale de aquí.
 */
class CotizacionController extends Controller
{
    /** RN-28: un programado va al menos 30 minutos en el futuro. */
    private const MINUTOS_MINIMOS_PROGRAMADO = 30;

    /** Hasta cuántos días adelante se puede programar. */
    private const DIAS_MAXIMOS_PROGRAMADO = 7;

    public function __construct(private readonly CotizacionPedidoService $cotizacion) {}

    /** `POST /cliente/zona`: ¿llegamos a este punto? (RN-20). */
    public function zona(Request $request): JsonResponse
    {
        $punto = $request->validate($this->reglasPunto(''));

        return response()->json(['cubre' => $this->cotizacion->cubre((float) $punto['lat'], (float) $punto['lng'])]);
    }

    /** `POST /cliente/direccion`: la dirección legible de un punto elegido en el mapa. */
    public function direccion(Request $request, GeocodificadorService $geocodificador): JsonResponse
    {
        $punto = $request->validate($this->reglasPunto(''));

        return response()->json([
            'direccion' => $geocodificador->direccionDe((float) $punto['lat'], (float) $punto['lng']),
        ]);
    }

    /** `POST /cliente/cotizar` (RN-21 a RN-23, RN-27, RN-28). */
    public function cotizar(Request $request, SelloCotizacion $sellos): JsonResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(['ENVIAR', 'RECIBIR'])],
            ...$this->reglasPunto('recogida.'),
            ...$this->reglasPunto('entrega.'),
            'modalidad' => ['required', Rule::in(['RECEPTOR_PAGA_ENVIO', 'REMITENTE_PAGA_ENVIO', 'RECEPTOR_PAGA_ENVIO_PRODUCTOS'])],
            'importe_producto' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'lo_antes_posible' => ['required', 'boolean'],
            'fecha' => ['exclude_if:lo_antes_posible,true', 'required', 'date_format:Y-m-d'],
            'hora' => ['exclude_if:lo_antes_posible,true', 'required', 'date_format:H:i'],
        ]);

        // RN-27: el producto solo existe cuando quien recibe paga envío + producto.
        if ($datos['modalidad'] !== 'RECEPTOR_PAGA_ENVIO_PRODUCTOS') {
            $datos['importe_producto'] = 0;
        } elseif ((float) ($datos['importe_producto'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['importe_producto' => 'Escribe cuánto cuesta el producto.']);
        }

        if (! $request->boolean('lo_antes_posible')) {
            $this->validarProgramado($datos['fecha'], $datos['hora']);
        }

        $resultado = $this->cotizacion->cotizar(
            ['lat' => (float) $datos['recogida']['lat'], 'lng' => (float) $datos['recogida']['lng']],
            ['lat' => (float) $datos['entrega']['lat'], 'lng' => (float) $datos['entrega']['lng']],
        );

        if (! $resultado->ok()) {
            return response()->json(array_filter([
                'motivo' => $resultado->motivo,
                'punto' => $resultado->punto,
            ]), 422);
        }

        $condiciones = $sellos->condiciones($datos);
        $cliente = $request->user('cliente-token');
        $importeProducto = $condiciones['producto_centavos'] / 100;

        return response()->json([
            'km' => round((float) $resultado->km, 1),
            'minutos' => $resultado->minutos,
            'importe_envio' => $resultado->importeEnvio,
            'importe_producto' => $importeProducto,
            'total' => $resultado->importeEnvio + $importeProducto,
            'sello' => $sellos->sellar(
                $cliente->id_cliente,
                tenant()->slug,
                $condiciones,
                (float) $resultado->km,
                (float) $resultado->importeEnvio,
            ),
            'vence_en' => now()->addMinutes(SelloCotizacion::VIGENCIA_MINUTOS)->toIso8601String(),
        ]);
    }

    private function validarProgramado(string $fecha, string $hora): void
    {
        $momento = HoraOperacion::momento($fecha, $hora);

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
    }

    /**
     * @return array<string, list<string>>
     */
    private function reglasPunto(string $prefijo): array
    {
        return [
            "{$prefijo}lat" => ['required', 'numeric', 'between:-90,90'],
            "{$prefijo}lng" => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
