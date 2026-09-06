<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Conductor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\VentaViajeConductorController;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\MovimientoSaldo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaldoController extends Controller
{
    /**
     * Saldo de viajes prepagados del conductor (spec tenant/013). Solo aplica con modalidad
     * `Prepago`; con `Comision` no hay saldo que consultar.
     */
    public function show(Request $request): JsonResponse
    {
        $modalidad = ConfiguracionTenant::obtener(ConfiguracionTenant::MODALIDAD, 'Prepago');

        if ($modalidad === 'Comision') {
            return response()->json(['saldo' => null]);
        }

        $conductor = $request->user('conductor-token')->conductor;

        return response()->json(['saldo' => VentaViajeConductorController::saldoConductor($conductor)]);
    }

    /**
     * Saldo en dinero del conductor (spec tenant/022, SPEC-023) — ledger nuevo, separado del de
     * viajes prepagados. Es el que SPEC-019 (RN-02) exige mayor a cero para poder ponerse en línea
     * cuando el tenant usa modalidad `Comision`.
     */
    public function dinero(Request $request): JsonResponse
    {
        $conductor = $request->user('conductor-token')->conductor;

        return response()->json(['saldo' => (float) $conductor->saldo]);
    }

    public function movimientos(Request $request): JsonResponse
    {
        $conductor = $request->user('conductor-token')->conductor;

        $movimientos = MovimientoSaldo::where('id_conductor', $conductor->id_conductor)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($movimientos);
    }
}
