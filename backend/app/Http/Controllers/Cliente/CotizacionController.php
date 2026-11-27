<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cliente;

use App\Http\Controllers\Controller;
use App\Services\Cliente\CotizacionApp;
use App\Services\CotizacionPedidoService;
use App\Services\GeocodificadorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Zona de servicio, dirección de un punto y cotización para la app de clientes (spec tenant/035,
 * RN-20 a RN-28). La app nunca calcula ni manda un precio propio: muestra el que sale de aquí.
 */
class CotizacionController extends Controller
{
    public function __construct(private readonly CotizacionPedidoService $cotizacion) {}

    /** `POST /cliente/zona`: ¿llegamos a este punto? (RN-20). */
    public function zona(Request $request): JsonResponse
    {
        $punto = $request->validate(self::reglasPunto(''));

        return response()->json(['cubre' => $this->cotizacion->cubre((float) $punto['lat'], (float) $punto['lng'])]);
    }

    /** `POST /cliente/direccion`: la dirección legible de un punto elegido en el mapa. */
    public function direccion(Request $request, GeocodificadorService $geocodificador): JsonResponse
    {
        $punto = $request->validate(self::reglasPunto(''));

        return response()->json([
            'direccion' => $geocodificador->direccionDe((float) $punto['lat'], (float) $punto['lng']),
        ]);
    }

    /** `POST /cliente/cotizar` (RN-21 a RN-23, RN-27 a RN-29). */
    public function cotizar(Request $request, CotizacionApp $app): JsonResponse
    {
        $datos = $app->normalizar($request->validate(self::reglasCotizacion()));

        if ($motivo = $app->motivoDeHorario($datos)) {
            return response()->json(['motivo' => $motivo], 422);
        }

        [$estado, $cuerpo] = $app->cotizar($request->user('cliente-token')->id_cliente, $datos);

        return response()->json($cuerpo, $estado);
    }

    /**
     * Lo que define el precio. También lo valida la confirmación del pedido.
     *
     * @return array<string, mixed>
     */
    public static function reglasCotizacion(): array
    {
        return [
            'tipo' => ['required', Rule::in(['ENVIAR', 'RECIBIR'])],
            ...self::reglasPunto('recogida.'),
            ...self::reglasPunto('entrega.'),
            'modalidad' => ['required', Rule::in(['RECEPTOR_PAGA_ENVIO', 'REMITENTE_PAGA_ENVIO', 'RECEPTOR_PAGA_ENVIO_PRODUCTOS'])],
            'importe_producto' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'lo_antes_posible' => ['required', 'boolean'],
            'fecha' => ['exclude_if:lo_antes_posible,true', 'required', 'date_format:Y-m-d'],
            'hora' => ['exclude_if:lo_antes_posible,true', 'required', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function reglasPunto(string $prefijo): array
    {
        return [
            "{$prefijo}lat" => ['required', 'numeric', 'between:-90,90'],
            "{$prefijo}lng" => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
