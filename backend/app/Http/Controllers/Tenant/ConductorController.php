<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ConductorResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConductorController extends Controller
{
    public function usuariosDisponibles(): JsonResponse
    {
        $usuarios = Usuario::query()
            ->where('rol', 'Conductor')
            ->where('estado', 'Activo')
            ->whereNotIn('id_usuario', Conductor::query()->pluck('id_usuario'))
            ->orderBy('nombre')
            ->get(['id_usuario', 'nombre', 'apellido_paterno', 'email']);

        return response()->json(['data' => $usuarios]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Conductor::query()->with('usuario')->orderBy('id_conductor');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('numero_licencia', 'like', "%{$search}%")
                    ->orWhereHas('usuario', function ($q) use ($search) {
                        $q->where('nombre', 'like', "%{$search}%")
                            ->orWhere('apellido_paterno', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return ConductorResource::collection($query->paginate(15));
    }

    public function show(Conductor $conductor): JsonResponse
    {
        $conductor->load('usuario');

        return response()->json(new ConductorResource($conductor));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_usuario' => ['required', 'integer'],
            'numero_licencia' => ['required', 'string', 'max:255'],
            'tipo_licencia' => ['nullable', 'string', 'max:255'],
            'fecha_vencimiento_licencia' => ['nullable', 'date'],
            'telefono_emergencia' => ['nullable', 'string', 'max:255'],
        ]);

        $usuario = Usuario::find($data['id_usuario']);

        if (! $usuario || $usuario->rol !== 'Conductor') {
            throw ValidationException::withMessages([
                'id_usuario' => 'El usuario seleccionado no existe o no tiene rol Conductor.',
            ]);
        }

        if (Conductor::where('id_usuario', $usuario->id_usuario)->exists()) {
            throw ValidationException::withMessages([
                'id_usuario' => 'Este usuario ya tiene un perfil de conductor.',
            ]);
        }

        $conductor = Conductor::create([
            ...$data,
            'estado' => 'ACTIVO',
            'disponibilidad' => 'FUERA_DE_SERVICIO',
        ]);
        $conductor->load('usuario');

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'conductores',
            'accion' => 'ALTA',
            'descripcion' => "Alta del perfil de conductor de {$conductor->usuario->nombre} {$conductor->usuario->apellido_paterno}",
        ]);

        return response()->json(new ConductorResource($conductor), 201);
    }

    public function update(Request $request, Conductor $conductor): JsonResponse
    {
        $data = $request->validate([
            'numero_licencia' => ['required', 'string', 'max:255'],
            'tipo_licencia' => ['nullable', 'string', 'max:255'],
            'fecha_vencimiento_licencia' => ['nullable', 'date'],
            'telefono_emergencia' => ['nullable', 'string', 'max:255'],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO', 'BLOQUEADO'])],
            'disponibilidad' => ['required', Rule::in(['DISPONIBLE', 'OCUPADO', 'DESCANSO', 'FUERA_DE_SERVICIO'])],
        ]);

        $conductor->update($data);
        $conductor->load('usuario');

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'conductores',
            'accion' => 'EDICION',
            'descripcion' => "Edición del perfil de conductor de {$conductor->usuario->nombre} {$conductor->usuario->apellido_paterno}",
        ]);

        return response()->json(new ConductorResource($conductor));
    }
}
