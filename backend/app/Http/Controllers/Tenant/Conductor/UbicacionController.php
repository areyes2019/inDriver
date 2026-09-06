<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Conductor;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Pedido;
use App\Services\TrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UbicacionController extends Controller
{
    public function __construct(private readonly TrackingService $tracking) {}

    /**
     * Flujo normal, en vivo (spec tenant/021): solo se guarda si el conductor tiene un envío en
     * curso ahora mismo (RN-01) — estar en línea sin envío no genera tracking.
     */
    public function actualizar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitud' => ['required', 'numeric', 'between:-90,90'],
            'longitud' => ['required', 'numeric', 'between:-180,180'],
            'precision' => ['nullable', 'numeric', 'min:0'],
            'velocidad' => ['nullable', 'numeric', 'min:0'],
            'rumbo' => ['nullable', 'integer', 'between:0,359'],
            'bateria' => ['nullable', 'integer', 'between:0,100'],
        ]);

        $conductor = $request->user('conductor-token')->conductor;

        $this->tracking->registrarPosicion($conductor, $data);

        return response()->json(status: 204);
    }

    /**
     * Respaldo por lotes al reconectar (RN-05): la App acumuló hasta 200 puntos localmente sin
     * conexión. A diferencia del flujo normal, estos no se difunden al Panel (RN-07) — son
     * historia, no la posición actual.
     */
    public function lote(Request $request, Pedido $pedido): JsonResponse
    {
        $conductor = $request->user('conductor-token')->conductor;

        if ($pedido->id_conductor !== $conductor->id_conductor) {
            abort(403, 'Este pedido no te pertenece.');
        }

        $data = $request->validate([
            'puntos' => ['required', 'array', 'min:1'],
            'puntos.*.latitud' => ['required', 'numeric', 'between:-90,90'],
            'puntos.*.longitud' => ['required', 'numeric', 'between:-180,180'],
            'puntos.*.fecha_posicion' => ['required', 'date'],
            'puntos.*.precision' => ['nullable', 'numeric', 'min:0'],
            'puntos.*.velocidad' => ['nullable', 'numeric', 'min:0'],
            'puntos.*.rumbo' => ['nullable', 'integer', 'between:0,359'],
            'puntos.*.bateria' => ['nullable', 'integer', 'between:0,100'],
        ]);

        if (count($data['puntos']) > TrackingService::MAX_LOTE) {
            throw ValidationException::withMessages([
                'puntos' => ['BATCH_TOO_LARGE'],
            ]);
        }

        $this->tracking->registrarLote($conductor, $pedido, $data['puntos']);

        return response()->json(status: 204);
    }
}
