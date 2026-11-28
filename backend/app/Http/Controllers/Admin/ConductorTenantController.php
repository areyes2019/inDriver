<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ConductorResource;
use App\Models\LogCentral;
use App\Models\Tenant as TenantModel;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Despachador;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\Vehiculo;
use App\Notifications\CredencialesConductor;
use App\Services\VentaViajesConductorService;
use App\Support\AdminClienteDelTenant;
use App\Support\DespachadorDeConductor;
use App\Support\PasswordGenerada;
use App\Support\SaldoViajes;
use App\Support\SaldoViajesTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Conductores de un tenant vistos desde ADMIN_CENTRAL (detalle del tenant): listarlos, darlos de
 * alta y venderles viajes prepagados. Sigue las mismas reglas que el Panel del tenant —la venta
 * pasa por `VentaViajesConductorService`, el despachador por `DespachadorDeConductor`—, pero el
 * rastro queda en la bitácora central, no en la auditoría del tenant.
 */
class ConductorTenantController extends Controller
{
    public function __construct(private readonly VentaViajesConductorService $ventas) {}

    public function index(TenantModel $tenant): JsonResponse
    {
        return response()->json($this->enTenant($tenant, function () {
            $conductores = Conductor::query()
                ->with(['usuario', 'vehiculo'])
                ->withSum('ventasViajes as viajes_vendidos', 'cantidad_viajes')
                ->withCount(['pedidosParaSaldo as viajes_consumidos' => fn ($q) => $q->where('prepago_descontado', true)])
                ->orderBy('id_conductor')
                ->get();

            $usaDespachadores = ConfiguracionTenant::obtener(ConfiguracionTenant::USAR_DESPACHADORES, 'No') === 'Sí';

            return [
                'data' => ConductorResource::collection($conductores)->resolve(),
                'meta' => [
                    'costo_viaje_prepago' => (float) (ConfiguracionTenant::obtener(ConfiguracionTenant::COSTO_VIAJE_PREPAGO) ?? 0),
                    'saldo_viajes_tenant' => SaldoViajesTenant::actualONull(),
                    'despachadores' => $usaDespachadores
                        ? Despachador::with('usuario')->where('estado', 'Activo')->get()
                            ->map(fn (Despachador $d) => [
                                'id_despachador' => $d->id_despachador,
                                'nombre' => "{$d->usuario->nombre} {$d->usuario->apellido_paterno}",
                            ])->values()
                        : [],
                ],
            ];
        }));
    }

    public function store(Request $request, TenantModel $tenant): JsonResponse
    {
        $conductor = $this->enTenant($tenant, function () use ($request, $tenant) {
            // Se valida con la tenencia ya inicializada: los `unique` tienen que mirar la base del tenant.
            $data = $request->validate([
                'nombre' => ['required', 'string', 'max:255'],
                'apellido_paterno' => ['required', 'string', 'max:255'],
                'apellido_materno' => ['nullable', 'string', 'max:255'],
                'telefono' => ['nullable', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('usuarios', 'email')],
                'numero_licencia' => ['required', 'string', 'max:255'],
                'fecha_vencimiento_licencia' => ['nullable', 'date'],
                'id_despachador' => ['nullable', 'integer'],
                'placa' => ['required', 'string', 'max:255', Rule::unique('vehiculos', 'placa')],
                'marca' => ['required', 'string', 'max:255'],
            ]);

            $idDespachador = DespachadorDeConductor::resolver(DespachadorDeConductor::normalizar($data), 'ACTIVO');
            $password = PasswordGenerada::generar();

            $conductor = DB::transaction(function () use ($data, $idDespachador, $password) {
                $usuario = Usuario::create([
                    'nombre' => $data['nombre'],
                    'apellido_paterno' => $data['apellido_paterno'],
                    'apellido_materno' => $data['apellido_materno'] ?? null,
                    'telefono' => $data['telefono'] ?? null,
                    'email' => $data['email'],
                    'password' => $password,
                    'rol' => 'Conductor',
                    'estado' => 'Activo',
                ]);

                $conductor = Conductor::create([
                    'id_usuario' => $usuario->id_usuario,
                    'numero_licencia' => $data['numero_licencia'],
                    'fecha_vencimiento_licencia' => $data['fecha_vencimiento_licencia'] ?? null,
                    'id_despachador' => $idDespachador,
                    'estado' => 'ACTIVO',
                    'disponibilidad' => 'FUERA_DE_SERVICIO',
                ]);

                Vehiculo::create([
                    'id_conductor' => $conductor->id_conductor,
                    'placa' => $data['placa'],
                    'marca' => $data['marca'],
                ]);

                return $conductor;
            });

            Notification::route('mail', $data['email'])->notify(new CredencialesConductor(
                $tenant->nombre_comercial,
                $tenant->slug,
                $data['email'],
                $password,
            ));

            $conductor->load(['usuario', 'vehiculo']);

            return (new ConductorResource($conductor))->resolve();
        });

        // Con la tenencia ya cerrada: `logs_centrales` vive en la base central.
        LogCentral::create([
            'id_tenant' => $tenant->id_tenant,
            'id_admin' => $request->user('admin')->id_admin,
            'tipo' => 'CONDUCTOR',
            'accion' => 'ALTA',
            'descripcion' => "Alta del conductor {$conductor['nombre']} {$conductor['apellido_paterno']} ({$conductor['email']}) en el tenant {$tenant->nombre_comercial}",
        ]);

        return response()->json($conductor, 201);
    }

    public function venderViajes(Request $request, TenantModel $tenant, int $idConductor): JsonResponse
    {
        $data = $request->validate([
            'monto_pagado' => ['required', 'numeric', 'min:0.01'],
        ]);

        $resultado = $this->enTenant($tenant, function () use ($idConductor, $data) {
            $conductor = Conductor::with('usuario')->findOrFail($idConductor);
            $adminCliente = AdminClienteDelTenant::principal();

            if (! $adminCliente) {
                throw ValidationException::withMessages([
                    'monto_pagado' => 'El tenant no tiene un AdminCliente activo a cuyo nombre registrar la venta.',
                ]);
            }

            $venta = $this->ventas->vender($conductor, (float) $data['monto_pagado'], $adminCliente->id_usuario);

            return [
                'cantidad_viajes' => $venta->cantidad_viajes,
                'monto_pagado' => $venta->monto_pagado,
                'saldo_conductor' => SaldoViajes::para($conductor),
                'conductor' => "{$conductor->usuario->nombre} {$conductor->usuario->apellido_paterno}",
            ];
        });

        LogCentral::create([
            'id_tenant' => $tenant->id_tenant,
            'id_admin' => $request->user('admin')->id_admin,
            'tipo' => 'CONDUCTOR',
            'accion' => 'VENTA_VIAJES',
            'descripcion' => "Pago de {$resultado['monto_pagado']} acreditado como {$resultado['cantidad_viajes']} viaje(s) prepagado(s) al conductor {$resultado['conductor']} del tenant {$tenant->nombre_comercial}",
        ]);

        return response()->json($resultado, 201);
    }

    /**
     * Corre `$accion` dentro de la base del tenant y la cierra siempre, también si lanza.
     *
     * @template T
     *
     * @param  callable(): T  $accion
     * @return T
     */
    private function enTenant(TenantModel $tenant, callable $accion): mixed
    {
        tenancy()->initialize($tenant);

        try {
            return $accion();
        } finally {
            tenancy()->end();
        }
    }
}
