<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ConductorActivoResource;
use App\Http\Resources\Tenant\ConductorResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\Vehiculo;
use App\Notifications\CredencialesUsuarioTenant;
use App\Support\DespachadorDeConductor;
use App\Support\PasswordGenerada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
            ->withCount(['pedidosParaSaldo as viajes_consumidos' => fn ($q) => $q->where('prepago_descontado', true)])
            // Comisión pendiente por fila, con la misma técnica que `saldo_viajes` (spec tenant/030,
            // adición 8): dos sumas por página, ninguna consulta por conductor.
            ->withSum('comisiones as comision_generada', 'importe_comision')
            ->withSum('liquidacionesComision as comision_pagada', 'monto')
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

    /**
     * Para el mapa y la lista lateral del Panel (spec tenant/009, tenant/014).
     */
    public function activos(): AnonymousResourceCollection
    {
        // Las relaciones, el pedido activo y las dos subconsultas del saldo viven en
        // `Conductor::scopeConDatosDePanel` desde la spec tenant/027: el evento
        // `conductor.disponibilidad-cambiada` arma la misma fila para insertarla en el Panel sin
        // recargar, y las dos tienen que salir idénticas. Va al final de la cadena porque su
        // `select('conductores.*')` borraría los agregados si se encadenara antes.
        $conductores = Conductor::query()
            ->whereHas('estadoActual', fn ($q) => $q->where('estado', 'ONLINE'))
            ->join('usuarios', 'usuarios.id_usuario', '=', 'conductores.id_usuario')
            ->orderBy('usuarios.nombre')
            ->conDatosDePanel()
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

        $data['id_despachador'] = DespachadorDeConductor::resolver(DespachadorDeConductor::normalizar($data), 'ACTIVO');
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

        $data['id_despachador'] = DespachadorDeConductor::resolver(DespachadorDeConductor::normalizar($data), $data['estado']);
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
     * Vuelve a mandar el correo de bienvenida con una contraseña nueva. La original solo existe
     * hasheada, así que no hay forma de reenviarla: se genera otra con `PasswordGenerada` (la misma
     * del alta) y la anterior deja de servir.
     */
    public function reenviarCredenciales(Request $request, Conductor $conductor): JsonResponse
    {
        $usuario = $conductor->usuario;
        $password = PasswordGenerada::generar();

        $usuario->update(['password' => $password]);

        Notification::route('mail', $usuario->email)->notify(new CredencialesUsuarioTenant(
            tenant('nombre_comercial'),
            tenant('slug'),
            $usuario->email,
            $password,
        ));

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'usuarios',
            'accion' => 'EDICION',
            'descripcion' => "Reenvío de credenciales al conductor {$usuario->nombre} {$usuario->apellido_paterno}",
        ]);

        return response()->json([
            'message' => "Se enviaron nuevas credenciales a {$usuario->email}.",
        ]);
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
}
