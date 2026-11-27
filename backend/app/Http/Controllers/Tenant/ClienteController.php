<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ClienteResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Cliente;
use App\Services\Cliente\ConciliacionClientesService;
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

    public function store(Request $request, ConciliacionClientesService $conciliacion): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:255'],
            'avisos_whatsapp' => ['sometimes', 'boolean'],
        ]);

        $cliente = Cliente::create([...collect($data)->except('avisos_whatsapp')->all(), 'estado' => 'Activo']);
        $cliente->forceFill(['origen' => Cliente::ORIGEN_PANEL])->save();
        $this->guardarPermisoDeAvisos($request, $cliente, $data);
        // spec tenant/035, RN-19: un teléfono que ya tiene otro cliente queda por revisar.
        $conciliacion->asignarTelefono($cliente);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'clientes',
            'accion' => 'ALTA',
            'descripcion' => "Alta del cliente {$cliente->nombre}",
        ]);

        return response()->json(new ClienteResource($cliente), 201);
    }

    public function update(Request $request, Cliente $cliente, ConciliacionClientesService $conciliacion): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:255'],
            'avisos_whatsapp' => ['sometimes', 'boolean'],
        ]);

        $cliente->update(collect($data)->except('avisos_whatsapp')->all());
        $this->guardarPermisoDeAvisos($request, $cliente, $data);
        $conciliacion->asignarTelefono($cliente);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'clientes',
            'accion' => 'EDICION',
            'descripcion' => "Edición del cliente {$cliente->nombre}",
        ]);

        return response()->json(new ClienteResource($cliente));
    }

    public function cambiarEstado(Request $request, Cliente $cliente, ConciliacionClientesService $conciliacion): JsonResponse
    {
        $cliente->estado = $cliente->estado === 'Activo' ? 'Inactivo' : 'Activo';
        $cliente->save();
        // Un cliente inactivo no cuenta para los teléfonos repetidos (spec tenant/035, RN-16).
        $conciliacion->asignarTelefono($cliente);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'clientes',
            'accion' => 'CAMBIO_ESTADO',
            'descripcion' => "Cambio de estado del cliente {$cliente->nombre} a {$cliente->estado}",
        ]);

        return response()->json(new ClienteResource($cliente));
    }

    /**
     * spec tenant/031, D21 y RN-50: el permiso para avisos por WhatsApp se guarda con la fecha y
     * quién lo marcó, como evidencia de que el cliente aceptó.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardarPermisoDeAvisos(Request $request, Cliente $cliente, array $data): void
    {
        if (! array_key_exists('avisos_whatsapp', $data)) {
            return;
        }

        $usuario = $request->user('usuario');
        $cliente->cambiarAvisosWhatsapp((bool) $data['avisos_whatsapp'], "Panel: {$usuario->nombre} (usuario {$usuario->id_usuario})");
    }
}
