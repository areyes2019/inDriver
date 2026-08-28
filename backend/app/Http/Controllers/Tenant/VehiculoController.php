<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\VehiculoResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Vehiculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class VehiculoController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Vehiculo::query()->orderBy('placa');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('placa', 'like', "%{$search}%")
                    ->orWhere('marca', 'like', "%{$search}%")
                    ->orWhere('modelo', 'like', "%{$search}%")
                    ->orWhere('numero_economico', 'like', "%{$search}%");
            });
        }

        return VehiculoResource::collection($query->paginate(15));
    }

    public function show(Vehiculo $vehiculo): JsonResponse
    {
        return response()->json(new VehiculoResource($vehiculo));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'placa' => ['required', 'string', 'max:255', Rule::unique('vehiculos', 'placa')],
            'marca' => ['nullable', 'string', 'max:255'],
            'modelo' => ['nullable', 'string', 'max:255'],
            'anio' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'color' => ['nullable', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', 'max:255'],
            'numero_economico' => ['nullable', 'string', 'max:255'],
        ]);

        $vehiculo = Vehiculo::create([...$data, 'estado' => 'ACTIVO']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'vehiculos',
            'accion' => 'ALTA',
            'descripcion' => "Alta del vehículo {$vehiculo->placa}",
        ]);

        return response()->json(new VehiculoResource($vehiculo), 201);
    }

    public function update(Request $request, Vehiculo $vehiculo): JsonResponse
    {
        $data = $request->validate([
            'placa' => ['required', 'string', 'max:255', Rule::unique('vehiculos', 'placa')->ignore($vehiculo->id_vehiculo, 'id_vehiculo')],
            'marca' => ['nullable', 'string', 'max:255'],
            'modelo' => ['nullable', 'string', 'max:255'],
            'anio' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'color' => ['nullable', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', 'max:255'],
            'numero_economico' => ['nullable', 'string', 'max:255'],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO', 'MANTENIMIENTO'])],
        ]);

        $vehiculo->update($data);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'vehiculos',
            'accion' => 'EDICION',
            'descripcion' => "Edición del vehículo {$vehiculo->placa}",
        ]);

        return response()->json(new VehiculoResource($vehiculo));
    }
}
