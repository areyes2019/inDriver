<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ConductorPruebaResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\Vehiculo;
use App\Models\Tenant\VentaViajeConductor;
use App\Services\SaldoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conductores virtuales del "modo prueba" (Panel): permiten ejercitar todo el sistema —
 * disponibilidad, oferta/aceptación, tracking, entrega — sin un teléfono ni un vehículo real. Son
 * `Conductor` reales, solo marcados con `es_prueba`, para no duplicar ninguna regla de negocio.
 */
class ConductorPruebaController extends Controller
{
    public function __construct(private readonly SaldoService $saldos) {}

    public function index(): AnonymousResourceCollection
    {
        $conductores = Conductor::where('es_prueba', true)
            ->with(['usuario', 'vehiculo', 'estadoActual'])
            ->orderByDesc('id_conductor')
            ->get();

        return ConductorPruebaResource::collection($conductores);
    }

    /**
     * Crea el conductor virtual y le acredita saldo "ilimitado" (según la modalidad del tenant)
     * para que nunca lo bloquee la regla de saldo al conectarse (spec tenant/019, RN-02). Devuelve
     * un token de conductor real: el Panel lo usa para actuar en su nombre, exactamente como lo
     * haría panda_express.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['nullable', 'string', 'max:255'],
        ]);

        $conductor = DB::transaction(function () use ($request, $data) {
            $sufijo = Str::upper(Str::random(6));

            $usuario = Usuario::create([
                'nombre' => $data['nombre'] ?? "Conductor prueba {$sufijo}",
                'apellido_paterno' => 'Prueba',
                'email' => 'prueba.'.Str::lower($sufijo).'@prueba.test',
                'password' => bcrypt(Str::random(32)),
                'rol' => 'Conductor',
                'estado' => 'Activo',
            ]);

            $conductor = Conductor::create([
                'id_usuario' => $usuario->id_usuario,
                'numero_licencia' => "PRUEBA-{$sufijo}",
                'estado' => 'ACTIVO',
                'disponibilidad' => 'FUERA_DE_SERVICIO',
                'es_prueba' => true,
            ]);

            Vehiculo::create([
                'id_conductor' => $conductor->id_conductor,
                'placa' => "PRUEBA-{$sufijo}",
                'marca' => 'Simulado',
            ]);

            $this->acreditarSaldoIlimitado($conductor, $request->user('usuario'));

            return $conductor;
        });

        $conductor->load(['usuario', 'vehiculo', 'estadoActual']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'conductores',
            'accion' => 'ALTA',
            'descripcion' => "Alta de conductor de prueba {$conductor->usuario->nombre}",
        ]);

        return response()->json([
            'conductor' => (new ConductorPruebaResource($conductor))->resolve(),
            'token' => $conductor->usuario->createToken('panel-prueba')->plainTextToken,
        ], 201);
    }

    /**
     * Reemite el token de un conductor de prueba ya existente (spec "modo prueba"): el token
     * solo vive en memoria del navegador, así que se pierde al recargar la página. Evita tener
     * que borrar y crear uno nuevo solo para recuperar la sesión.
     */
    public function reconectar(Request $request, Conductor $conductor): JsonResponse
    {
        if (! $conductor->es_prueba) {
            abort(403, 'Solo se pueden reconectar conductores de prueba.');
        }

        return response()->json([
            'token' => $conductor->usuario->createToken('panel-prueba')->plainTextToken,
        ]);
    }

    public function destroy(Request $request, Conductor $conductor): JsonResponse
    {
        if (! $conductor->es_prueba) {
            abort(403, 'Solo se pueden eliminar conductores de prueba.');
        }

        $nombre = $conductor->usuario->nombre;

        DB::transaction(function () use ($conductor) {
            $usuario = $conductor->usuario;
            $conductor->delete();
            $usuario?->delete();
        });

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'conductores',
            'accion' => 'BAJA',
            'descripcion' => "Baja de conductor de prueba {$nombre}",
        ]);

        return response()->json(status: 204);
    }

    private function acreditarSaldoIlimitado(Conductor $conductor, Usuario $admin): void
    {
        $modalidad = ConfiguracionTenant::obtener(ConfiguracionTenant::MODALIDAD, 'Prepago');

        if ($modalidad === 'Comision') {
            $this->saldos->registrar(
                conductor: $conductor,
                tipo: 'CREDITO',
                monto: 999999.0,
                creadoPor: $admin,
                referencia: 'Saldo del modo prueba',
            );

            return;
        }

        VentaViajeConductor::create([
            'id_conductor' => $conductor->id_conductor,
            'cantidad_viajes' => 999999,
            'monto_pagado' => 0,
            'id_usuario' => $admin->id_usuario,
            'fecha_venta' => now(),
        ]);
    }
}
