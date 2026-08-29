<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LogCentral;
use App\Models\PaqueteViaje;
use App\Models\Tenant as TenantModel;
use App\Models\Tenant\CompraPaquete;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreditoPaqueteController extends Controller
{
    public function store(Request $request, TenantModel $tenant): JsonResponse
    {
        $data = $request->validate([
            'id_paquete' => ['required', 'integer', Rule::exists('paquetes_viajes', 'id_paquete')],
            'cantidad_paquetes' => ['required', 'integer', 'min:1'],
        ]);

        $paquete = PaqueteViaje::findOrFail($data['id_paquete']);

        if ($paquete->estado !== 'Activo') {
            return response()->json([
                'message' => 'Solo se pueden acreditar paquetes activos.',
            ], 422);
        }

        $cantidadViajes = $paquete->cantidad_viajes * $data['cantidad_paquetes'];

        tenancy()->initialize($tenant);

        try {
            CompraPaquete::create([
                'codigo_paquete' => $paquete->codigo_paquete,
                'cantidad_paquetes' => $data['cantidad_paquetes'],
                'cantidad_viajes' => $cantidadViajes,
                'precio_unitario' => $paquete->precio,
                'importe_total' => $paquete->precio * $data['cantidad_paquetes'],
                'forma_pago' => null,
                'estado' => 'Activo',
                'fecha_compra' => now(),
            ]);
        } finally {
            tenancy()->end();
        }

        LogCentral::create([
            'id_tenant' => $tenant->id_tenant,
            'id_admin' => $request->user('admin')->id_admin,
            'tipo' => 'PAQUETE',
            'accion' => 'ACREDITACION',
            'descripcion' => "Acreditación de {$data['cantidad_paquetes']} paquete(s) '{$paquete->nombre}' ({$cantidadViajes} viajes) al tenant {$tenant->nombre_comercial}",
        ]);

        return response()->json([
            'cantidad_viajes_acreditados' => $cantidadViajes,
        ], 201);
    }
}
