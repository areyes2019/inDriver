<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\CompraPaquete;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\VentaViajeConductor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VentaViajeConductorController extends Controller
{
    public function store(Request $request, Conductor $conductor): JsonResponse
    {
        $data = $request->validate([
            'monto_pagado' => ['required', 'numeric', 'min:0.01'],
        ]);

        $costoViajePrepago = (float) (ConfiguracionTenant::obtener(ConfiguracionTenant::COSTO_VIAJE_PREPAGO) ?? 0);

        if ($costoViajePrepago <= 0) {
            throw ValidationException::withMessages([
                'monto_pagado' => 'El tenant debe configurar el costo del viaje prepagado antes de poder acreditar viajes por monto.',
            ]);
        }

        $cantidadViajes = (int) floor($data['monto_pagado'] / $costoViajePrepago);

        if ($cantidadViajes < 1) {
            throw ValidationException::withMessages([
                'monto_pagado' => "El monto pagado no alcanza para acreditar al menos 1 viaje (costo del viaje: {$costoViajePrepago}).",
            ]);
        }

        $saldoTenant = (int) CompraPaquete::sum('cantidad_viajes') - (int) VentaViajeConductor::sum('cantidad_viajes');

        if ($cantidadViajes > $saldoTenant) {
            throw ValidationException::withMessages([
                'monto_pagado' => "Ese monto equivale a {$cantidadViajes} viaje(s), pero el tenant solo tiene {$saldoTenant} viaje(s) disponible(s) para vender.",
            ]);
        }

        $venta = VentaViajeConductor::create([
            'id_conductor' => $conductor->id_conductor,
            'cantidad_viajes' => $cantidadViajes,
            'monto_pagado' => $data['monto_pagado'],
            'id_usuario' => $request->user('usuario')->id_usuario,
            'fecha_venta' => now(),
        ]);

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
            'saldo_tenant' => $saldoTenant - $cantidadViajes,
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
        $vendidos = VentaViajeConductor::where('id_conductor', $conductor->id_conductor)->sum('cantidad_viajes');
        $consumidos = $conductor->pedidos()->where('prepago_descontado', true)->count();

        return (int) $vendidos - $consumidos;
    }
}
