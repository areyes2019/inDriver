<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\DireccionClienteResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\DireccionCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class DireccionClienteController extends Controller
{
    public function index(Cliente $cliente): AnonymousResourceCollection
    {
        return DireccionClienteResource::collection(
            $cliente->direcciones()->orderBy('id_direccion')->get()
        );
    }

    public function show(Cliente $cliente, DireccionCliente $direccion): JsonResponse
    {
        $this->asegurarPertenece($cliente, $direccion);

        return response()->json(new DireccionClienteResource($direccion));
    }

    public function store(Request $request, Cliente $cliente): JsonResponse
    {
        $data = $this->validarDatos($request);

        $direccion = $cliente->direcciones()->create($data);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'direcciones_clientes',
            'accion' => 'ALTA',
            'descripcion' => "Alta de dirección ({$direccion->alias}) para el cliente {$cliente->nombre}",
        ]);

        return response()->json(new DireccionClienteResource($direccion), 201);
    }

    public function update(Request $request, Cliente $cliente, DireccionCliente $direccion): JsonResponse
    {
        $this->asegurarPertenece($cliente, $direccion);

        $data = $this->validarDatos($request);

        $direccion->update($data);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'direcciones_clientes',
            'accion' => 'EDICION',
            'descripcion' => "Edición de dirección ({$direccion->alias}) del cliente {$cliente->nombre}",
        ]);

        return response()->json(new DireccionClienteResource($direccion));
    }

    public function destroy(Request $request, Cliente $cliente, DireccionCliente $direccion): JsonResponse
    {
        $this->asegurarPertenece($cliente, $direccion);

        $direccion->delete();

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'direcciones_clientes',
            'accion' => 'BAJA',
            'descripcion' => "Baja de dirección ({$direccion->alias}) del cliente {$cliente->nombre}",
        ]);

        return response()->json(['message' => 'Dirección eliminada correctamente.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validarDatos(Request $request): array
    {
        return $request->validate([
            'alias' => ['nullable', 'string', 'max:255'],
            'calle' => ['required', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:255'],
            'colonia' => ['nullable', 'string', 'max:255'],
            'cp' => ['nullable', 'string', 'max:10'],
            'ciudad' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', 'string', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:255'],
            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],
            'instrucciones_entrega' => ['nullable', 'string'],
        ]);
    }

    private function asegurarPertenece(Cliente $cliente, DireccionCliente $direccion): void
    {
        if ($direccion->id_cliente !== $cliente->id_cliente) {
            throw ValidationException::withMessages([
                'id_cliente' => 'Esta dirección no pertenece al cliente indicado.',
            ]);
        }
    }
}
