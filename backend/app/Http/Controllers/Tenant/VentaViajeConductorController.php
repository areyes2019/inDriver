<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\CompraPaquete;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\VentaViajeConductor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VentaViajeConductorController extends Controller
{
    public function store(Request $request, Conductor $conductor): JsonResponse
    {
        $data = $request->validate([
            'cantidad_viajes' => ['required', 'integer', 'min:1'],
        ]);

        $saldoTenant = (int) CompraPaquete::sum('cantidad_viajes') - (int) VentaViajeConductor::sum('cantidad_viajes');

        if ($data['cantidad_viajes'] > $saldoTenant) {
            throw ValidationException::withMessages([
                'cantidad_viajes' => "El tenant solo tiene {$saldoTenant} viaje(s) disponible(s) para vender.",
            ]);
        }

        $venta = VentaViajeConductor::create([
            'id_conductor' => $conductor->id_conductor,
            'cantidad_viajes' => $data['cantidad_viajes'],
            'id_usuario' => $request->user('usuario')->id_usuario,
            'fecha_venta' => now(),
        ]);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'ventas_viajes_conductor',
            'accion' => 'ALTA',
            'descripcion' => "Venta de {$venta->cantidad_viajes} viaje(s) prepagado(s) al conductor {$conductor->id_conductor}",
        ]);

        return response()->json([
            'id_venta' => $venta->id_venta,
            'cantidad_viajes' => $venta->cantidad_viajes,
            'saldo_conductor' => self::saldoConductor($conductor),
            'saldo_tenant' => $saldoTenant - $data['cantidad_viajes'],
        ], 201);
    }

    public static function saldoConductor(Conductor $conductor): int
    {
        $vendidos = VentaViajeConductor::where('id_conductor', $conductor->id_conductor)->sum('cantidad_viajes');
        $consumidos = $conductor->pedidos()->where('prepago_descontado', true)->count();

        return (int) $vendidos - $consumidos;
    }
}
