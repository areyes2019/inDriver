<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Conductor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\VentaViajeConductorController;
use App\Models\Tenant\ConfiguracionTenant;
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
}
