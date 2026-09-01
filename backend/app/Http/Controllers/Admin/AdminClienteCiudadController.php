<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\UsuarioResource;
use App\Models\LogCentral;
use App\Models\Tenant as TenantModel;
use App\Models\Tenant\Ciudad;
use App\Models\Tenant\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminClienteCiudadController extends Controller
{
    public function index(TenantModel $tenant): JsonResponse
    {
        tenancy()->initialize($tenant);

        try {
            $usuarios = Usuario::where('rol', 'AdminCliente')
                ->with('ciudades')
                ->orderBy('nombre')
                ->get();

            // Se resuelve el recurso a un arreglo aquí, mientras la conexión `tenant` sigue activa:
            // si se devolviera el resource sin resolver, Laravel lo serializa después de que este
            // método retorna (al construir la respuesta HTTP), para entonces `tenancy()->end()` ya
            // se ejecutó y la conexión `tenant` ya no existe.
            $data = UsuarioResource::collection($usuarios)->resolve();
        } finally {
            tenancy()->end();
        }

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, TenantModel $tenant, int $idUsuario): JsonResponse
    {
        $data = $request->validate([
            'ciudades' => ['present', 'array'],
            'ciudades.*.place_id' => ['required', 'string'],
            'ciudades.*.nombre' => ['required', 'string', 'max:255'],
            'ciudades.*.lat' => ['required', 'numeric'],
            'ciudades.*.lng' => ['required', 'numeric'],
            'ciudades.*.bounds' => ['nullable', 'array'],
        ]);

        tenancy()->initialize($tenant);

        try {
            $usuario = Usuario::where('rol', 'AdminCliente')->findOrFail($idUsuario);

            $idsCiudades = collect($data['ciudades'])->map(function (array $ciudad) {
                $registro = Ciudad::firstOrCreate(
                    ['place_id' => $ciudad['place_id']],
                    [
                        'nombre' => $ciudad['nombre'],
                        'lat' => $ciudad['lat'],
                        'lng' => $ciudad['lng'],
                        'bounds' => $ciudad['bounds'] ?? null,
                    ],
                );

                return $registro->id_ciudad;
            });

            $usuario->ciudades()->sync($idsCiudades);
            $usuario->load('ciudades');

            $nombreUsuario = "{$usuario->nombre} {$usuario->apellido_paterno}";
            $totalCiudades = $idsCiudades->count();
            // Igual que en index(): se resuelve antes de cerrar la tenencia.
            $respuesta = (new UsuarioResource($usuario))->resolve();
        } finally {
            tenancy()->end();
        }

        LogCentral::create([
            'id_tenant' => $tenant->id_tenant,
            'id_admin' => $request->user('admin')->id_admin,
            'tipo' => 'TENANT',
            'accion' => 'CIUDADES_ASIGNADAS',
            'descripcion' => "Se asignaron {$totalCiudades} ciudad(es) al AdminCliente {$nombreUsuario}",
        ]);

        return response()->json($respuesta);
    }
}
