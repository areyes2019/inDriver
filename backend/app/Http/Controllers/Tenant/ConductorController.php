<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ConductorResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Despachador;
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
        $query = Conductor::query()->with(['usuario', 'despachador.usuario'])->orderBy('id_conductor');

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
        $conductor->load(['usuario', 'despachador.usuario']);

        return response()->json(new ConductorResource($conductor));
    }

    public function saldoViajes(Conductor $conductor): JsonResponse
    {
        return response()->json([
            'id_conductor' => $conductor->id_conductor,
            'saldo_viajes' => VentaViajeConductorController::saldoConductor($conductor),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_usuario' => ['required', 'integer'],
            'numero_licencia' => ['required', 'string', 'max:255'],
            'tipo_licencia' => ['nullable', 'string', 'max:255'],
            'fecha_vencimiento_licencia' => ['nullable', 'date'],
            'telefono_emergencia' => ['nullable', 'string', 'max:255'],
            'id_despachador' => ['nullable', 'integer', 'exists:despachadores,id_despachador'],
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

        $data['id_despachador'] = $this->resolverDespachador($this->normalizarIdDespachador($data), 'ACTIVO');

        $conductor = Conductor::create([
            ...$data,
            'estado' => 'ACTIVO',
            'disponibilidad' => 'FUERA_DE_SERVICIO',
        ]);
        $conductor->load(['usuario', 'despachador.usuario']);

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
            'id_despachador' => ['nullable', 'integer', 'exists:despachadores,id_despachador'],
        ]);

        $data['id_despachador'] = $this->resolverDespachador($this->normalizarIdDespachador($data), $data['estado']);

        $conductor->update($data);
        $conductor->load(['usuario', 'despachador.usuario']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'conductores',
            'accion' => 'EDICION',
            'descripcion' => "Edición del perfil de conductor de {$conductor->usuario->nombre} {$conductor->usuario->apellido_paterno}",
        ]);

        return response()->json(new ConductorResource($conductor));
    }

    /**
     * El frontend envía `''` (no `null`) cuando el selector de despachador no aplica o no se ha
     * tocado — normaliza a `int|null` antes de pasarlo a un método con `strict_types`.
     *
     * @param  array<string, mixed>  $data
     */
    private function normalizarIdDespachador(array $data): ?int
    {
        $valor = $data['id_despachador'] ?? null;

        return ($valor === null || $valor === '') ? null : (int) $valor;
    }

    /**
     * Resuelve el `id_despachador` real a guardar, según cuántos despachadores `Activo` existen y
     * si el tenant usa despachadores (spec tenant/011): con 1 solo despachador activo, se ignora lo
     * enviado y se fuerza ese id; con 2+, es obligatorio solo si el conductor queda `ACTIVO`; con 0,
     * no hay nada que asignar. Con `usar_despachadores = No`, siempre se guarda `null`.
     */
    private function resolverDespachador(?int $idDespachadorEnviado, string $estado): ?int
    {
        $usaDespachadores = ConfiguracionTenant::obtener(ConfiguracionTenant::USAR_DESPACHADORES, 'No') === 'Sí';

        if (! $usaDespachadores) {
            return null;
        }

        $despachadoresActivos = Despachador::where('estado', 'Activo')->pluck('id_despachador');

        if ($despachadoresActivos->count() === 1) {
            return $despachadoresActivos->first();
        }

        if ($despachadoresActivos->count() === 0) {
            return null;
        }

        if ($idDespachadorEnviado !== null && ! $despachadoresActivos->contains($idDespachadorEnviado)) {
            throw ValidationException::withMessages([
                'id_despachador' => 'El despachador seleccionado no existe o no está activo.',
            ]);
        }

        if ($estado === 'ACTIVO' && $idDespachadorEnviado === null) {
            throw ValidationException::withMessages([
                'id_despachador' => 'Selecciona el despachador responsable de este conductor.',
            ]);
        }

        return $idDespachadorEnviado;
    }
}
