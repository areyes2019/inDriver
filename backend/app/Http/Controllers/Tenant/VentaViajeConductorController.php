<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\VentaViajeConductor;
use App\Services\VentaViajesConductorService;
use App\Support\SaldoViajes;
use App\Support\SaldoViajesTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VentaViajeConductorController extends Controller
{
    public function __construct(private readonly VentaViajesConductorService $ventas) {}

    public function store(Request $request, Conductor $conductor): JsonResponse
    {
        $data = $request->validate([
            'monto_pagado' => ['required', 'numeric', 'min:0.01'],
        ]);

        $venta = $this->ventas->vender($conductor, (float) $data['monto_pagado'], $request->user('usuario')->id_usuario);
        $saldoTenant = SaldoViajesTenant::actual();

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'ventas_viajes_conductor',
            'accion' => 'ALTA',
            'descripcion' => "Pago de {$venta->monto_pagado} acreditado como {$venta->cantidad_viajes} viaje(s) prepagado(s) al conductor {$conductor->id_conductor}",
        ]);

        return response()->json([
            'id_venta' => $venta->id_venta,
            'cantidad_viajes' => $venta->cantidad_viajes,
            'monto_pagado' => $venta->monto_pagado,
            'saldo_conductor' => self::saldoConductor($conductor),
            // La venta ya no descuenta del saldo del tenant (spec tenant/015, RN-C4).
            'saldo_tenant' => $saldoTenant,
        ], 201);
    }

    public function historialConductor(Conductor $conductor): JsonResponse
    {
        $pagos = VentaViajeConductor::where('id_conductor', $conductor->id_conductor)
            ->orderByDesc('fecha_venta')
            ->get(['id_venta', 'fecha_venta', 'monto_pagado', 'cantidad_viajes']);

        return response()->json([
            'data' => $pagos,
            'total_pagado' => (float) $pagos->sum('monto_pagado'),
        ]);
    }

    public function reportePagos(): JsonResponse
    {
        $pagos = VentaViajeConductor::with('conductor.usuario')
            ->orderByDesc('fecha_venta')
            ->get(['id_venta', 'id_conductor', 'fecha_venta', 'monto_pagado', 'cantidad_viajes']);

        return response()->json([
            'data' => $pagos,
            'total_general' => (float) $pagos->sum('monto_pagado'),
        ]);
    }

    public static function saldoConductor(Conductor $conductor): int
    {
        return SaldoViajes::para($conductor);
    }
}
