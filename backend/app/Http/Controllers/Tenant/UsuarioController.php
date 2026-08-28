<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\UsuarioResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\Despachador;
use App\Models\Tenant\Usuario;
use App\Notifications\CredencialesUsuarioTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UsuarioController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Usuario::query()->orderBy('nombre');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('apellido_paterno', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return UsuarioResource::collection($query->paginate(15));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(new UsuarioResource($this->buscarUsuario($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'apellido_paterno' => ['required', 'string', 'max:255'],
            'apellido_materno' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('usuarios', 'email')],
            'rol' => ['required', Rule::in(['AdminCliente', 'Despachador', 'Conductor'])],
        ]);

        $password = Str::password(16);

        $usuario = DB::transaction(function () use ($data, $password) {
            $usuario = Usuario::create([...$data, 'password' => $password, 'estado' => 'Activo']);

            if ($usuario->rol === 'Despachador') {
                Despachador::create(['id_usuario' => $usuario->id_usuario, 'estado' => 'Activo']);
            }

            return $usuario;
        });

        Notification::route('mail', $usuario->email)->notify(new CredencialesUsuarioTenant(
            tenant('nombre_comercial'),
            tenant('slug'),
            $usuario->email,
            $password,
        ));

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'usuarios',
            'accion' => 'ALTA',
            'descripcion' => "Alta del usuario {$usuario->nombre} {$usuario->apellido_paterno} ({$usuario->rol})",
        ]);

        return response()->json(new UsuarioResource($usuario), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $usuario = $this->buscarUsuario($id);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'apellido_paterno' => ['required', 'string', 'max:255'],
            'apellido_materno' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('usuarios', 'email')->ignore($usuario->id_usuario, 'id_usuario')],
            'rol' => ['required', Rule::in(['AdminCliente', 'Despachador', 'Conductor'])],
            'estado' => ['required', Rule::in(['Activo', 'Suspendido', 'Inactivo'])],
        ]);

        if ($usuario->id_usuario === $request->user('usuario')->id_usuario && $data['rol'] !== $usuario->rol) {
            throw ValidationException::withMessages([
                'rol' => 'No puedes cambiar tu propio rol.',
            ]);
        }

        $rolAnterior = $usuario->rol;

        DB::transaction(function () use ($usuario, $data, $rolAnterior) {
            $usuario->update($data);

            if ($rolAnterior !== 'Despachador' && $usuario->rol === 'Despachador') {
                Despachador::firstOrCreate(['id_usuario' => $usuario->id_usuario], ['estado' => 'Activo']);
            } elseif ($rolAnterior === 'Despachador' && $usuario->rol !== 'Despachador') {
                Despachador::where('id_usuario', $usuario->id_usuario)->delete();
            }

            if ($rolAnterior === 'Conductor' && $usuario->rol !== 'Conductor') {
                Conductor::where('id_usuario', $usuario->id_usuario)->delete();
            }
        });

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'usuarios',
            'accion' => 'EDICION',
            'descripcion' => "Edición del usuario {$usuario->nombre} {$usuario->apellido_paterno}",
        ]);

        return response()->json(new UsuarioResource($usuario));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $usuario = $this->buscarUsuario($id);

        if ($usuario->id_usuario === $request->user('usuario')->id_usuario) {
            throw ValidationException::withMessages([
                'password' => 'No puedes eliminar tu propio usuario.',
            ]);
        }

        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], $request->user('usuario')->password)) {
            throw ValidationException::withMessages([
                'password' => 'La contraseña no es correcta.',
            ]);
        }

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'usuarios',
            'accion' => 'BAJA',
            'descripcion' => "Baja del usuario {$usuario->nombre} {$usuario->apellido_paterno}",
        ]);

        $usuario->delete();

        return response()->json([
            'message' => 'Usuario eliminado correctamente.',
        ]);
    }

    /**
     * Resuelve el usuario a mano en vez de usar binding implícito de ruta: el binding implícito
     * corre en `SubstituteBindings`, que por la lista de prioridad de middleware de Laravel no
     * tiene garantizado ejecutarse después de `tenant.slug` (que activa la base del tenant
     * correcta). Resolverlo aquí adentro sí garantiza que la tenencia ya está activa.
     */
    private function buscarUsuario(int $id): Usuario
    {
        return Usuario::findOrFail($id);
    }
}
