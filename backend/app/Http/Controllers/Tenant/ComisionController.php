<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ComisionConductorResource;
use App\Http\Resources\Tenant\LiquidacionComisionResource;
use App\Models\Tenant\ComisionConductor;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\LiquidacionComision;
use App\Services\LiquidacionComisionService;
use App\Support\ComisionesConductor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comisiones de conductores vistas desde el Panel (spec tenant/030): resumen del tenant, detalle
 * por conductor y registro de liquidaciones. Todos los números salen de
 * `App\Support\ComisionesConductor`; Vue solo los pinta.
 */
class ComisionController extends Controller
{
    public function __construct(private readonly LiquidacionComisionService $liquidaciones) {}

    public function resumen(): JsonResponse
    {
        return response()->json([
            ...ComisionesConductor::resumenTenant(),
            'modalidad_conductores' => ConfiguracionTenant::obtener(ConfiguracionTenant::MODALIDAD, 'Prepago'),
            'comision_porcentaje' => (float) ConfiguracionTenant::obtener(ConfiguracionTenant::COMISION_PORCENTAJE, '0'),
        ]);
    }

    public function conductor(Conductor $conductor): JsonResponse
    {
        // Sin `AmbienteScope` sobre el pedido: las comisiones no distinguen ambiente (supuesto 4)
        // y desde el Panel en LIVE los viajes TEST saldrían sin número de pedido.
        $viajes = ComisionConductor::where('id_conductor', $conductor->id_conductor)
            ->with(['pedido' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderByDesc('created_at')
            ->orderByDesc('id_comision')
            ->get();

        $liquidaciones = LiquidacionComision::where('id_conductor', $conductor->id_conductor)
            ->with('usuario')
            ->withCount('comisiones')
            ->orderByDesc('created_at')
            ->orderByDesc('id_liquidacion')
            ->get();

        return response()->json([
            'conductor' => $this->nombreDe($conductor),
            'resumen' => ComisionesConductor::resumenDe($conductor),
            'viajes' => ComisionConductorResource::collection($viajes),
            'liquidaciones' => LiquidacionComisionResource::collection($liquidaciones),
        ]);
    }

    /**
     * El tope contra el pendiente (RN-10) lo aplica el servicio, no este `validate()`: tiene que
     * comprobarse dentro del bloqueo, con el número recién leído.
     */
    public function liquidar(Request $request, Conductor $conductor): JsonResponse
    {
        $data = $request->validate([
            'monto' => ['required', 'numeric', 'min:0.01'],
            'referencia' => ['nullable', 'string', 'max:120'],
        ]);

        $liquidacion = $this->liquidaciones->registrar(
            conductor: $conductor,
            monto: (float) $data['monto'],
            registradoPor: $request->user('usuario'),
            referencia: $data['referencia'] ?? null,
        );

        $liquidacion->load('usuario')->loadCount('comisiones');

        return response()->json([
            'liquidacion' => new LiquidacionComisionResource($liquidacion),
            'resumen' => ComisionesConductor::resumenDe($conductor),
        ], 201);
    }

    /**
     * @return array{id_conductor: int, nombre: string}
     */
    private function nombreDe(Conductor $conductor): array
    {
        $conductor->loadMissing('usuario');

        return [
            'id_conductor' => $conductor->id_conductor,
            'nombre' => trim("{$conductor->usuario->nombre} {$conductor->usuario->apellido_paterno}"),
        ];
    }
}
