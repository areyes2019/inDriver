<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ConductorVehiculoResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorVehiculo;
use App\Models\Tenant\Vehiculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConductorVehiculoController extends Controller
{
    public function disponibles(): JsonResponse
    {
        $conductores = Conductor::query()
            ->with('usuario:id_usuario,nombre,apellido_paterno')
            ->where('estado', 'ACTIVO')
            ->get(['id_conductor', 'id_usuario'])
            ->map(fn (Conductor $conductor) => [
                'id_conductor' => $conductor->id_conductor,
                'nombre' => "{$conductor->usuario->nombre} {$conductor->usuario->apellido_paterno}",
            ])
            ->values();

        $vehiculos = Vehiculo::query()
            ->where('estado', 'ACTIVO')
            ->orderBy('placa')
            ->get(['id_vehiculo', 'placa', 'marca', 'modelo']);

        return response()->json(['conductores' => $conductores, 'vehiculos' => $vehiculos]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ConductorVehiculo::query()
            ->with(['conductor.usuario', 'vehiculo'])
            ->orderByDesc('fecha_inicio');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('conductor.usuario', function ($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%")
                        ->orWhere('apellido_paterno', 'like', "%{$search}%");
                })->orWhereHas('vehiculo', function ($q) use ($search) {
                    $q->where('placa', 'like', "%{$search}%");
                });
            });
        }

        return ConductorVehiculoResource::collection($query->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_conductor' => ['required', 'integer', 'exists:conductores,id_conductor'],
            'id_vehiculo' => ['required', 'integer', 'exists:vehiculos,id_vehiculo'],
            'fecha_inicio' => ['required', 'date'],
        ]);

        $asignacion = DB::transaction(function () use ($data) {
            ConductorVehiculo::where('id_conductor', $data['id_conductor'])
                ->where('activo', true)
                ->update(['activo' => false, 'fecha_fin' => $data['fecha_inicio']]);

            ConductorVehiculo::where('id_vehiculo', $data['id_vehiculo'])
                ->where('activo', true)
                ->update(['activo' => false, 'fecha_fin' => $data['fecha_inicio']]);

            return ConductorVehiculo::create([...$data, 'activo' => true]);
        });

        $asignacion->load(['conductor.usuario', 'vehiculo']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'conductor_vehiculo',
            'accion' => 'ASIGNACION',
            'descripcion' => "Asignación del vehículo {$asignacion->vehiculo->placa} al conductor {$asignacion->conductor->usuario->nombre} {$asignacion->conductor->usuario->apellido_paterno}",
        ]);

        return response()->json(new ConductorVehiculoResource($asignacion), 201);
    }

    public function finalizar(Request $request, ConductorVehiculo $conductorVehiculo): JsonResponse
    {
        if (! $conductorVehiculo->activo) {
            throw ValidationException::withMessages([
                'activo' => 'Esta asignación ya está finalizada.',
            ]);
        }

        $conductorVehiculo->update(['activo' => false, 'fecha_fin' => now()->toDateString()]);
        $conductorVehiculo->load(['conductor.usuario', 'vehiculo']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'conductor_vehiculo',
            'accion' => 'FINALIZACION',
            'descripcion' => "Finalización de la asignación del vehículo {$conductorVehiculo->vehiculo->placa} al conductor {$conductorVehiculo->conductor->usuario->nombre} {$conductorVehiculo->conductor->usuario->apellido_paterno}",
        ]);

        return response()->json(new ConductorVehiculoResource($conductorVehiculo));
    }
}
