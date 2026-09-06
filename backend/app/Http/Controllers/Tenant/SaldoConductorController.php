<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\MovimientoSaldo;
use App\Services\SaldoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saldo en dinero del conductor (spec tenant/022, SPEC-023). Es un ledger nuevo, separado del
 * modelo de viajes prepagados (`ventas_viajes_conductor`, spec tenant/013): coexisten según la
 * modalidad del tenant, ver `ConfiguracionTenant::MODALIDAD`.
 */
class SaldoConductorController extends Controller
{
    public function __construct(private readonly SaldoService $saldos) {}

    /**
     * El Panel solo puede acreditar o corregir a mano (CREDITO, AJUSTE); COMISION y COMPENSACION
     * las registra el propio sistema en sus flujos (liquidación al entregar, SPEC-022).
     */
    public function store(Request $request, Conductor $conductor): JsonResponse
    {
        $data = $request->validate([
            // COMISION queda fuera: esa la registra el sistema al entregar (spec tenant/022,
            // SPEC-023 RN-04), nunca a mano.
            'tipo' => ['required', Rule::in(['CREDITO', 'AJUSTE', 'COMPENSACION'])],
            'monto' => ['required', 'numeric'],
            'referencia' => ['nullable', 'string', 'max:120'],
        ]);

        if ((float) $data['monto'] === 0.0) {
            throw ValidationException::withMessages(['monto' => ['INVALID_AMOUNT']]);
        }

        $movimiento = $this->saldos->registrar(
            conductor: $conductor,
            tipo: $data['tipo'],
            monto: (float) $data['monto'],
            creadoPor: $request->user('usuario'),
            referencia: $data['referencia'] ?? null,
        );

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'movimientos_saldo',
            'accion' => 'ALTA',
            'descripcion' => "Movimiento {$movimiento->tipo} de {$movimiento->monto} al saldo del conductor {$conductor->id_conductor}",
        ]);

        return response()->json([
            'id_movimiento' => $movimiento->id_movimiento,
            'tipo' => $movimiento->tipo,
            'monto' => (float) $movimiento->monto,
            'saldo_resultante' => (float) $movimiento->saldo_resultante,
            'created_at' => $movimiento->created_at,
        ], 201);
    }

    public function historial(Conductor $conductor): JsonResponse
    {
        $movimientos = MovimientoSaldo::where('id_conductor', $conductor->id_conductor)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($movimientos);
    }
}
