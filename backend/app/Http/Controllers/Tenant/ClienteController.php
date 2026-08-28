<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ClienteResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Cliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClienteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Cliente::query()->orderBy('nombre');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('telefono', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return ClienteResource::collection($query->paginate(15));
    }

    public function show(Cliente $cliente): JsonResponse
    {
        return response()->json(new ClienteResource($cliente));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:255'],
        ]);

        $cliente = Cliente::create([...$data, 'estado' => 'Activo']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'clientes',
            'accion' => 'ALTA',
            'descripcion' => "Alta del cliente {$cliente->nombre}",
        ]);

        return response()->json(new ClienteResource($cliente), 201);
    }

    public function update(Request $request, Cliente $cliente): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:255'],
        ]);

        $cliente->update($data);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'clientes',
            'accion' => 'EDICION',
            'descripcion' => "Edición del cliente {$cliente->nombre}",
        ]);

        return response()->json(new ClienteResource($cliente));
    }

    public function cambiarEstado(Request $request, Cliente $cliente): JsonResponse
    {
        $cliente->estado = $cliente->estado === 'Activo' ? 'Inactivo' : 'Activo';
        $cliente->save();

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'clientes',
            'accion' => 'CAMBIO_ESTADO',
            'descripcion' => "Cambio de estado del cliente {$cliente->nombre} a {$cliente->estado}",
        ]);

        return response()->json(new ClienteResource($cliente));
    }
}
