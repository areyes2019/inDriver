<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\TenantResource;
use App\Models\LogCentral;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Tenant::query()->orderByDesc('id_tenant');

        if ($search = $request->query('search')) {
            $query->where('nombre_comercial', 'like', "%{$search}%");
        }

        return TenantResource::collection($query->paginate(15));
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json(new TenantResource($tenant));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre_comercial' => ['required', 'string', 'max:255'],
            'razon_social' => ['required', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'rfc')],
            'telefono' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('tenants', 'email')],
        ]);

        $tenant = new Tenant($data);

        try {
            // Sin DB::transaction: el aprovisionamiento de la base del tenant (stancl/tenancy)
            // reconecta la conexión central por debajo y la invalida ("no active transaction").
            // El rollback se hace a mano: si algo falla después de insertar el tenant, se borra.
            $tenant->save();

            LogCentral::create([
                'id_tenant' => $tenant->id_tenant,
                'id_admin' => $request->user('admin')->id_admin,
                'tipo' => 'TENANT',
                'accion' => 'ALTA',
                'descripcion' => "Alta del tenant {$tenant->nombre_comercial}",
            ]);
        } catch (\Throwable $e) {
            if ($tenant->exists) {
                $tenant->delete();
            }

            Log::error('No se pudo crear el tenant', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'No se pudo crear el tenant, intenta de nuevo.',
            ], 500);
        }

        return response()->json(new TenantResource($tenant), 201);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'nombre_comercial' => ['required', 'string', 'max:255'],
            'razon_social' => ['required', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'rfc')->ignore($tenant->id_tenant, 'id_tenant')],
            'telefono' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('tenants', 'email')->ignore($tenant->id_tenant, 'id_tenant')],
        ]);

        $tenant->update($data);

        LogCentral::create([
            'id_tenant' => $tenant->id_tenant,
            'id_admin' => $request->user('admin')->id_admin,
            'tipo' => 'TENANT',
            'accion' => 'EDICION',
            'descripcion' => "Edición del tenant {$tenant->nombre_comercial}",
        ]);

        return response()->json(new TenantResource($tenant));
    }

    public function cambiarEstado(Request $request, Tenant $tenant): JsonResponse
    {
        if (! in_array($tenant->estado, ['Activo', 'Suspendido'], true)) {
            return response()->json([
                'message' => 'Solo se puede alternar entre Activo y Suspendido.',
            ], 422);
        }

        $tenant->estado = $tenant->estado === 'Activo' ? 'Suspendido' : 'Activo';
        $tenant->modo_estado = 'MANUAL';
        $tenant->save();

        LogCentral::create([
            'id_tenant' => $tenant->id_tenant,
            'id_admin' => $request->user('admin')->id_admin,
            'tipo' => 'TENANT',
            'accion' => 'CAMBIO_ESTADO',
            'descripcion' => "Cambio de estado del tenant {$tenant->nombre_comercial} a {$tenant->estado}",
        ]);

        return response()->json(new TenantResource($tenant));
    }
}
