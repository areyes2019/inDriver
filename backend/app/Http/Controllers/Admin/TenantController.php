<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\TenantResource;
use App\Jobs\CrearAdminClienteInicial;
use App\Models\LogCentral;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'email' => ['required', 'email', 'max:255', Rule::unique('tenants', 'email')],
            'nombre' => ['required', 'string', 'max:255'],
            'apellido_paterno' => ['required', 'string', 'max:255'],
            'apellido_materno' => ['nullable', 'string', 'max:255'],
        ]);

        // nombre/apellido_paterno/apellido_materno son del AdminCliente inicial, no del tenant:
        // no se guardan en `tenants` (ver 010-alta-admin-cliente-tenant.md), solo se usan abajo.
        $tenant = new Tenant(collect($data)->only([
            'nombre_comercial', 'razon_social', 'rfc', 'telefono', 'email',
        ])->all());
        $tenant->slug = $this->generarSlugUnico($data['nombre_comercial']);

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

            // Si esto falla, no revierte el tenant (ya quedó creado y migrado): el job registra
            // el error a mano y no relanza la excepción.
            CrearAdminClienteInicial::dispatchSync(
                $tenant,
                $data['nombre'],
                $data['apellido_paterno'],
                $data['apellido_materno'] ?? null,
            );
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
            'email' => ['required', 'email', 'max:255', Rule::unique('tenants', 'email')->ignore($tenant->id_tenant, 'id_tenant')],
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

    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], $request->user('admin')->password)) {
            throw ValidationException::withMessages([
                'password' => 'La contraseña no es correcta.',
            ]);
        }

        // Se registra antes de borrar: logs_centrales.id_tenant es nullOnDelete, así que después
        // del borrado ya no se podría asociar el log al tenant.
        LogCentral::create([
            'id_tenant' => $tenant->id_tenant,
            'id_admin' => $request->user('admin')->id_admin,
            'tipo' => 'TENANT',
            'accion' => 'BAJA',
            'descripcion' => "Baja del tenant {$tenant->nombre_comercial}",
        ]);

        // Dispara la eliminación física de la base de datos del tenant (stancl/tenancy), mismo
        // mecanismo que ya usa el rollback de store() cuando falla el aprovisionamiento.
        $tenant->delete();

        return response()->json([
            'message' => 'Tenant eliminado correctamente.',
        ]);
    }

    private function generarSlugUnico(string $nombreComercial): string
    {
        $base = Str::slug($nombreComercial) ?: 'tenant';
        $slug = $base;
        $suffix = 2;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
