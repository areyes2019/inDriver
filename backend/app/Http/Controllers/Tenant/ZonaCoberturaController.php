<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ZonaServicioResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\ZonaServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ZonaCoberturaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ZonaServicioResource::collection(ZonaServicio::orderBy('nombre')->get());
    }

    public function show(ZonaServicio $zona): JsonResponse
    {
        return response()->json(new ZonaServicioResource($zona));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validarDatos($request);

        $zona = ZonaServicio::create([...$data, 'estado' => 'Activo']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'zonas_servicio',
            'accion' => 'ALTA',
            'descripcion' => "Alta de la zona de cobertura {$zona->nombre}",
        ]);

        return response()->json(new ZonaServicioResource($zona), 201);
    }

    public function update(Request $request, ZonaServicio $zona): JsonResponse
    {
        $data = $this->validarDatos($request);

        $zona->update($data);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'zonas_servicio',
            'accion' => 'EDICION',
            'descripcion' => "Edición de la zona de cobertura {$zona->nombre}",
        ]);

        return response()->json(new ZonaServicioResource($zona));
    }

    public function cambiarEstado(Request $request, ZonaServicio $zona): JsonResponse
    {
        $zona->estado = $zona->estado === 'Activo' ? 'Inactivo' : 'Activo';
        $zona->save();

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'zonas_servicio',
            'accion' => 'CAMBIO_ESTADO',
            'descripcion' => "Cambio de estado de la zona {$zona->nombre} a {$zona->estado}",
        ]);

        return response()->json(new ZonaServicioResource($zona));
    }

    public function destroy(Request $request, ZonaServicio $zona): JsonResponse
    {
        $nombre = $zona->nombre;
        $zona->delete();

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'zonas_servicio',
            'accion' => 'BAJA',
            'descripcion' => "Baja de la zona de cobertura {$nombre}",
        ]);

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validarDatos(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'poligono' => ['nullable', 'array', 'min:3'],
            'poligono.*.lat' => ['required_with:poligono', 'numeric'],
            'poligono.*.lng' => ['required_with:poligono', 'numeric'],
        ]);
    }
}
