<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ConductorActivoResource;
use App\Http\Resources\Tenant\ConductorResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Despachador;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\Vehiculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
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
        $query = Conductor::query()
            ->with(['usuario', 'despachador.usuario', 'vehiculo'])
            ->withSum('ventasViajes as viajes_vendidos', 'cantidad_viajes')
            ->withCount(['pedidos as viajes_consumidos' => fn ($q) => $q->where('prepago_descontado', true)])
            ->orderBy('id_conductor');

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

    public function activos(): AnonymousResourceCollection
    {
        $conductores = Conductor::query()
            ->with(['usuario', 'vehiculo', 'estadoActual'])
            ->whereHas('estadoActual', fn ($q) => $q->where('estado', 'ONLINE'))
            ->join('usuarios', 'usuarios.id_usuario', '=', 'conductores.id_usuario')
            ->orderBy('usuarios.nombre')
            ->select('conductores.*')
            ->get();

        return ConductorActivoResource::collection($conductores);
    }

    public function show(Conductor $conductor): JsonResponse
    {
        $conductor->load(['usuario', 'despachador.usuario', 'vehiculo']);

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
            'fecha_vencimiento_licencia' => ['nullable', 'date'],
            'id_despachador' => ['nullable', 'integer', 'exists:despachadores,id_despachador'],
            ...$this->reglasVehiculo(),
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
        $datosVehiculo = $this->extraerDatosVehiculo($data);
        $data = array_diff_key($data, $datosVehiculo);

        $conductor = DB::transaction(function () use ($data, $datosVehiculo) {
            $conductor = Conductor::create([
                ...$data,
                'estado' => 'ACTIVO',
                'disponibilidad' => 'FUERA_DE_SERVICIO',
            ]);

            Vehiculo::create([
                ...$datosVehiculo,
                'id_conductor' => $conductor->id_conductor,
            ]);

            return $conductor;
        });
        $conductor->load(['usuario', 'despachador.usuario', 'vehiculo']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'conductores',
            'accion' => 'ALTA',
            'descripcion' => "Alta del perfil de conductor de {$conductor->usuario->nombre} {$conductor->usuario->apellido_paterno}",
        ]);

        return response()->json(new ConductorResource($conductor), 201);
    }

    /**
     * `disponibilidad` no forma parte de este `PUT` (spec tenant/003): el AdminCliente no decide la
     * disponibilidad operativa de un conductor, la sincroniza el propio conductor al conectarse/
     * desconectarse desde `panda_express` (`Conductor\EstadoController@actualizar`, spec
     * tenant/013). Si el payload la incluye, `validate()` la descarta sin error.
     */
    public function update(Request $request, Conductor $conductor): JsonResponse
    {
        $data = $request->validate([
            'numero_licencia' => ['required', 'string', 'max:255'],
            'fecha_vencimiento_licencia' => ['nullable', 'date'],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO', 'BLOQUEADO'])],
            'id_despachador' => ['nullable', 'integer', 'exists:despachadores,id_despachador'],
            ...$this->reglasVehiculo($conductor->vehiculo?->id_vehiculo),
        ]);

        $data['id_despachador'] = $this->resolverDespachador($this->normalizarIdDespachador($data), $data['estado']);
        $datosVehiculo = $this->extraerDatosVehiculo($data);
        unset($data['placa'], $data['marca']);

        DB::transaction(function () use ($conductor, $data, $datosVehiculo) {
            $conductor->update($data);
            $conductor->vehiculo()->updateOrCreate([], $datosVehiculo);
        });
        $conductor->load(['usuario', 'despachador.usuario', 'vehiculo']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'conductores',
            'accion' => 'EDICION',
            'descripcion' => "Edición del perfil de conductor de {$conductor->usuario->nombre} {$conductor->usuario->apellido_paterno}",
        ]);

        return response()->json(new ConductorResource($conductor));
    }

    /**
     * Reglas del vehículo propio del conductor (spec tenant/004): siempre 1 a 1 y obligatorio, se
     * captura en el mismo formulario de alta/edición del conductor, nunca en una pantalla propia.
     *
     * @return array<string, mixed>
     */
    private function reglasVehiculo(?int $ignorarVehiculoId = null): array
    {
        return [
            'placa' => ['required', 'string', 'max:255', Rule::unique('vehiculos', 'placa')->ignore($ignorarVehiculoId, 'id_vehiculo')],
            'marca' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extraerDatosVehiculo(array $data): array
    {
        return [
            'placa' => $data['placa'],
            'marca' => $data['marca'],
        ];
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
