<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PaqueteViajeResource;
use App\Models\LogCentral;
use App\Models\PaqueteViaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PaqueteViajeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PaqueteViaje::query()->orderByDesc('id_paquete');

        if ($search = $request->query('search')) {
            $query->where('nombre', 'like', "%{$search}%");
        }

        return PaqueteViajeResource::collection($query->paginate(15));
    }

    public function show(PaqueteViaje $paquete): JsonResponse
    {
        return response()->json(new PaqueteViajeResource($paquete));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo_paquete' => ['required', 'string', 'max:255', Rule::unique('paquetes_viajes', 'codigo_paquete')],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'cantidad_viajes' => ['required', 'integer', 'min:1'],
            'precio' => ['required', 'numeric', 'min:0'],
        ]);

        $paquete = PaqueteViaje::create([...$data, 'estado' => 'Activo']);

        LogCentral::create([
            'id_tenant' => null,
            'id_admin' => $request->user('admin')->id_admin,
            'tipo' => 'PAQUETE',
            'accion' => 'ALTA',
            'descripcion' => "Alta del paquete {$paquete->nombre}",
        ]);

        return response()->json(new PaqueteViajeResource($paquete), 201);
    }

    public function update(Request $request, PaqueteViaje $paquete): JsonResponse
    {
        $data = $request->validate([
            'codigo_paquete' => ['required', 'string', 'max:255', Rule::unique('paquetes_viajes', 'codigo_paquete')->ignore($paquete->id_paquete, 'id_paquete')],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'cantidad_viajes' => ['required', 'integer', 'min:1'],
            'precio' => ['required', 'numeric', 'min:0'],
        ]);

        $paquete->update($data);

        LogCentral::create([
            'id_tenant' => null,
            'id_admin' => $request->user('admin')->id_admin,
            'tipo' => 'PAQUETE',
            'accion' => 'EDICION',
            'descripcion' => "Edición del paquete {$paquete->nombre}",
        ]);

        return response()->json(new PaqueteViajeResource($paquete));
    }

    public function cambiarEstado(Request $request, PaqueteViaje $paquete): JsonResponse
    {
        $paquete->estado = $paquete->estado === 'Activo' ? 'Inactivo' : 'Activo';
        $paquete->save();

        LogCentral::create([
            'id_tenant' => null,
            'id_admin' => $request->user('admin')->id_admin,
            'tipo' => 'PAQUETE',
            'accion' => 'CAMBIO_ESTADO',
            'descripcion' => "Cambio de estado del paquete {$paquete->nombre} a {$paquete->estado}",
        ]);

        return response()->json(new PaqueteViajeResource($paquete));
    }

    public function destroy(Request $request, PaqueteViaje $paquete): JsonResponse
    {
        $paquete->delete();

        LogCentral::create([
            'id_tenant' => null,
            'id_admin' => $request->user('admin')->id_admin,
            'tipo' => 'PAQUETE',
            'accion' => 'BAJA',
            'descripcion' => "Baja del paquete {$paquete->nombre}",
        ]);

        return response()->json(null, 204);
    }
}
