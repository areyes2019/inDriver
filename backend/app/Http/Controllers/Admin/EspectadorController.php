<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SoloLecturaEspectador;
use App\Models\LogCentral;
use App\Models\Tenant as TenantModel;
use App\Support\AdminClienteDelTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Abre el Panel de un tenant en modo espectador para ADMIN_CENTRAL: la misma sesión del navegador
 * queda autenticada en el guard `usuario` como el AdminCliente del tenant, con una marca en la
 * sesión que `SoloLecturaEspectador` usa para rechazar todo lo que no sea leer. Así el admin ve el
 * Panel real —mapa, envíos, conductores, WhatsApp, tiempo real— sin poder mover nada.
 *
 * El tenant no ve que alguien entró: no se toca `ultimo_acceso` ni la auditoría del tenant. El
 * rastro queda solo en la bitácora central.
 */
class EspectadorController extends Controller
{
    public function store(Request $request, TenantModel $tenant): JsonResponse
    {
        tenancy()->initialize($tenant);

        try {
            $adminCliente = AdminClienteDelTenant::principal();

            if (! $adminCliente) {
                throw ValidationException::withMessages([
                    'tenant' => 'El tenant no tiene un AdminCliente activo para mirar su Panel.',
                ]);
            }

            Auth::guard('usuario')->login($adminCliente);
        } finally {
            tenancy()->end();
        }

        $request->session()->put(SoloLecturaEspectador::SESION, [
            'id_tenant' => $tenant->id_tenant,
            'id_admin' => $request->user('admin')->id_admin,
        ]);

        LogCentral::create([
            'id_tenant' => $tenant->id_tenant,
            'id_admin' => $request->user('admin')->id_admin,
            'tipo' => 'TENANT',
            'accion' => 'ESPECTADOR',
            'descripcion' => "Entrada como espectador al Panel del tenant {$tenant->nombre_comercial}",
        ]);

        return response()->json(['slug' => $tenant->slug]);
    }
}
