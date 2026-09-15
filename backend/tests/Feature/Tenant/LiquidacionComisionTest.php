<?php

use App\Models\Tenant;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\ComisionConductor;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\ConsumoViajeTenant;
use App\Models\Tenant\LiquidacionComision;
use App\Models\Tenant\MovimientoSaldo;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use App\Services\LiquidacionComisionService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/**
 * spec tenant/030 — resumen de comisiones, detalle por conductor y liquidaciones.
 *
 * Las comisiones se siembran directo en `comisiones_conductor` (cómo nacen ya lo cubre
 * `ComisionConductorTest`); aquí lo que importa es lo que el Panel lee y lo que pasa al pagar.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);

    gc_collect_cycles();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        if (! File::delete($file)) {
            usleep(50000);
            File::delete($file);
        }
    }
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

function liquidacionTenant(): Tenant
{
    return Tenant::create([
        'nombre_comercial' => 'Liquidación Express',
        'razon_social' => 'Liquidación Express SA de CV',
        'slug' => 'liquidacion-express',
    ]);
}

/** Deja la tenencia iniciada, en modalidad Comisión al 25 %. */
function liquidacionAdmin(Tenant $tenant, string $rol = 'AdminCliente'): Usuario
{
    tenancy()->initialize($tenant);
    $usuario = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Ruiz', 'email' => strtolower($rol).'@liquidacionexpress.com',
        'password' => bcrypt('Password123!'), 'rol' => $rol, 'estado' => 'Activo',
    ]);
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, 'Comision');
    ConfiguracionTenant::establecer(ConfiguracionTenant::COMISION_PORCENTAJE, '25');

    return $usuario;
}

function liquidacionConductor(string $nombre = 'Juan', string $email = 'juan@liquidacionexpress.com'): Conductor
{
    $usuario = Usuario::create([
        'nombre' => $nombre, 'apellido_paterno' => 'Pérez', 'email' => $email,
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);

    return Conductor::create([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'LIC-'.$usuario->id_usuario,
        'estado' => 'ACTIVO',
        'disponibilidad' => 'DISPONIBLE',
    ]);
}

/**
 * Siembra `$cantidad` viajes entregados de `$importeEnvio` con su comisión al 25 %, en orden
 * cronológico (un minuto entre cada uno, para que el FIFO sea determinista).
 *
 * @return array<int, ComisionConductor>
 */
function liquidacionSembrarViajes(Conductor $conductor, int $cantidad, float $importeEnvio = 100, string $ambiente = Pedido::AMBIENTE_LIVE): array
{
    $comisiones = [];

    for ($n = 1; $n <= $cantidad; $n++) {
        $momento = now()->subMinutes($cantidad - $n);

        $pedido = Pedido::create([
            'numero_pedido' => sprintf('PED-%03d-%s-c%d', $n, $ambiente, $conductor->id_conductor),
            'nombre_solicitante' => 'Mario Sánchez',
            'telefono_solicitante' => '5511223344',
            'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4326, 'longitud_recogida' => -99.1332,
            'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.4200, 'longitud_entrega' => -99.1600,
            'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true, 'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
            'importe_envio' => $importeEnvio, 'estado' => 'ENTREGADO', 'fecha_entrega' => $momento,
            'id_conductor' => $conductor->id_conductor,
        ]);
        $pedido->ambiente = $ambiente;
        $pedido->save();

        $comisiones[] = ComisionConductor::create([
            'id_conductor' => $conductor->id_conductor,
            'id_pedido' => $pedido->id_pedido,
            'importe_envio' => $importeEnvio,
            'porcentaje_aplicado' => 25,
            'importe_comision' => round($importeEnvio * 0.25, 2),
            'created_at' => $momento,
        ]);
    }

    return $comisiones;
}

function liquidacionEstados(Conductor $conductor): array
{
    return ComisionConductor::where('id_conductor', $conductor->id_conductor)
        ->orderBy('created_at')->orderBy('id_comision')
        ->pluck('estado')->all();
}

it('reports generado, pagado and pendiente per conductor and for the tenant, all from sums', function () {
    $tenant = liquidacionTenant();
    $admin = liquidacionAdmin($tenant);
    $juan = liquidacionConductor();
    $luis = liquidacionConductor('Luis', 'luis@liquidacionexpress.com');
    liquidacionSembrarViajes($juan, 10);
    liquidacionSembrarViajes($luis, 2, 40);
    tenancy()->end();

    $detalle = $this->actingAs($admin, 'usuario')
        ->getJson("/api/v1/t/liquidacion-express/conductores/{$juan->id_conductor}/comisiones")
        ->assertOk();

    expect($detalle->json('conductor.nombre'))->toBe('Juan Pérez')
        ->and($detalle->json('resumen'))->toMatchArray([
            'viajes' => 10, 'importe_envios' => 1000, 'generado' => 250, 'pagado' => 0, 'pendiente' => 250, 'porcentaje_actual' => 25,
        ])
        ->and($detalle->json('viajes'))->toHaveCount(10)
        ->and($detalle->json('viajes.0.numero_pedido'))->toBe("PED-010-live-c{$juan->id_conductor}")
        ->and($detalle->json('viajes.0.estado'))->toBe('PENDIENTE')
        ->and($detalle->json('liquidaciones'))->toBeEmpty();

    $resumen = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/liquidacion-express/comisiones/resumen')
        ->assertOk();

    expect($resumen->json())->toMatchArray([
        'viajes' => 12, 'importe_envios' => 1080, 'generado' => 270, 'pagado' => 0, 'pendiente' => 270,
        'modalidad_conductores' => 'Comision', 'comision_porcentaje' => 25,
    ]);
});

it('registers a liquidacion, updates the totals and marks the oldest viajes as PAGADA (FIFO)', function () {
    $tenant = liquidacionTenant();
    $admin = liquidacionAdmin($tenant);
    $juan = liquidacionConductor();
    liquidacionSembrarViajes($juan, 10);
    tenancy()->end();

    $respuesta = $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/liquidacion-express/conductores/{$juan->id_conductor}/liquidaciones", [
            'monto' => 100, 'referencia' => 'Transferencia 4471',
        ])
        ->assertCreated();

    expect($respuesta->json('liquidacion.monto'))->toBe(100)
        ->and($respuesta->json('liquidacion.referencia'))->toBe('Transferencia 4471')
        ->and($respuesta->json('liquidacion.registrado_por'))->toBe('Ana Ruiz')
        ->and($respuesta->json('liquidacion.viajes_cubiertos'))->toBe(4)
        ->and($respuesta->json('resumen'))->toMatchArray(['generado' => 250, 'pagado' => 100, 'pendiente' => 150]);

    tenancy()->initialize($tenant);
    $idLiquidacion = $respuesta->json('liquidacion.id_liquidacion');

    expect(liquidacionEstados($juan))->toBe(array_merge(array_fill(0, 4, 'PAGADA'), array_fill(0, 6, 'PENDIENTE')))
        ->and(ComisionConductor::where('estado', 'PAGADA')->pluck('id_liquidacion')->unique()->all())->toBe([$idLiquidacion])
        ->and(ComisionConductor::where('estado', 'PAGADA')->whereNull('pagada_en')->count())->toBe(0)
        ->and(LiquidacionComision::find($idLiquidacion)->id_usuario)->toBe($admin->id_usuario)
        ->and(Auditoria::where('tabla_afectada', 'liquidaciones_comision')->count())->toBe(1);
    tenancy()->end();
});

it('carries the leftover of a partial payment into the next liquidacion', function () {
    $tenant = liquidacionTenant();
    $admin = liquidacionAdmin($tenant);
    $juan = liquidacionConductor();
    liquidacionSembrarViajes($juan, 10);
    $servicio = app(LiquidacionComisionService::class);

    $servicio->registrar($juan, 100, $admin);
    expect(liquidacionEstados($juan))->toBe(array_merge(array_fill(0, 4, 'PAGADA'), array_fill(0, 6, 'PENDIENTE')));

    // 30 cubre una más (25) y sobran 5.
    $servicio->registrar($juan, 30, $admin);
    expect(liquidacionEstados($juan))->toBe(array_merge(array_fill(0, 5, 'PAGADA'), array_fill(0, 5, 'PENDIENTE')));

    // 20 + 5 de sobrante = 25: cubre otra.
    $servicio->registrar($juan, 20, $admin);
    expect(liquidacionEstados($juan))->toBe(array_merge(array_fill(0, 6, 'PAGADA'), array_fill(0, 4, 'PENDIENTE')));

    // Los totales nunca dependieron de cuántas están PAGADA.
    $detalle = $this->actingAs($admin, 'usuario')
        ->getJson("/api/v1/t/liquidacion-express/conductores/{$juan->id_conductor}/comisiones")
        ->assertOk();

    expect($detalle->json('resumen'))->toMatchArray(['generado' => 250, 'pagado' => 150, 'pendiente' => 100])
        ->and($detalle->json('liquidaciones'))->toHaveCount(3);
});

it('rejects a liquidacion above the pending amount and writes nothing', function () {
    $tenant = liquidacionTenant();
    $admin = liquidacionAdmin($tenant);
    $juan = liquidacionConductor();
    liquidacionSembrarViajes($juan, 10);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/liquidacion-express/conductores/{$juan->id_conductor}/liquidaciones", ['monto' => 100])
        ->assertCreated();

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/liquidacion-express/conductores/{$juan->id_conductor}/liquidaciones", ['monto' => 151])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['monto']);

    tenancy()->initialize($tenant);
    expect(LiquidacionComision::count())->toBe(1)
        ->and((float) LiquidacionComision::sum('monto'))->toBe(100.0);
    tenancy()->end();
});

it('rejects a zero or negative liquidacion', function () {
    $tenant = liquidacionTenant();
    $admin = liquidacionAdmin($tenant);
    $juan = liquidacionConductor();
    liquidacionSembrarViajes($juan, 2);
    tenancy()->end();

    foreach ([0, -10] as $monto) {
        $this->actingAs($admin, 'usuario')
            ->postJson("/api/v1/t/liquidacion-express/conductores/{$juan->id_conductor}/liquidaciones", ['monto' => $monto])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['monto']);
    }

    tenancy()->initialize($tenant);
    expect(LiquidacionComision::count())->toBe(0);
    tenancy()->end();
});

it('does not let two liquidaciones of the full pending amount both go through', function () {
    $tenant = liquidacionTenant();
    $admin = liquidacionAdmin($tenant);
    $juan = liquidacionConductor();
    liquidacionSembrarViajes($juan, 2);
    $servicio = app(LiquidacionComisionService::class);

    // El pendiente se lee dentro del bloqueo: la segunda ve el pendiente ya en cero.
    $servicio->registrar($juan, 50, $admin);

    expect(fn () => $servicio->registrar($juan, 50, $admin))->toThrow(ValidationException::class)
        ->and(LiquidacionComision::count())->toBe(1)
        ->and((float) LiquidacionComision::sum('monto'))->toBe(50.0);
});

it('touches neither the tenant viaje balance nor the old money ledger when liquidating', function () {
    $tenant = liquidacionTenant();
    $admin = liquidacionAdmin($tenant);
    $juan = liquidacionConductor();
    liquidacionSembrarViajes($juan, 4);

    app(LiquidacionComisionService::class)->registrar($juan, 100, $admin);

    expect(ConsumoViajeTenant::count())->toBe(0)
        ->and(MovimientoSaldo::count())->toBe(0)
        ->and((float) $juan->fresh()->saldo)->toBe(0.0);
});

it('rejects the three endpoints for a Despachador', function () {
    $tenant = liquidacionTenant();
    $despachador = liquidacionAdmin($tenant, 'Despachador');
    $juan = liquidacionConductor();
    tenancy()->end();

    $this->actingAs($despachador, 'usuario')
        ->getJson('/api/v1/t/liquidacion-express/comisiones/resumen')
        ->assertForbidden();

    $this->actingAs($despachador, 'usuario')
        ->getJson("/api/v1/t/liquidacion-express/conductores/{$juan->id_conductor}/comisiones")
        ->assertForbidden();

    $this->actingAs($despachador, 'usuario')
        ->postJson("/api/v1/t/liquidacion-express/conductores/{$juan->id_conductor}/liquidaciones", ['monto' => 10])
        ->assertForbidden();
});

it('lists TEST and LIVE viajes with their numero_pedido even when the Panel is in LIVE', function () {
    $tenant = liquidacionTenant();
    $admin = liquidacionAdmin($tenant);
    $juan = liquidacionConductor();
    liquidacionSembrarViajes($juan, 1, 100, Pedido::AMBIENTE_LIVE);
    liquidacionSembrarViajes($juan, 1, 60, Pedido::AMBIENTE_TEST);
    ConfiguracionTenant::establecer(ConfiguracionTenant::AMBIENTE, Pedido::AMBIENTE_LIVE);
    tenancy()->end();

    $detalle = $this->actingAs($admin, 'usuario')
        ->getJson("/api/v1/t/liquidacion-express/conductores/{$juan->id_conductor}/comisiones")
        ->assertOk();

    expect($detalle->json('viajes'))->toHaveCount(2)
        ->and(collect($detalle->json('viajes'))->pluck('numero_pedido')->sort()->values()->all())
        ->toBe(["PED-001-live-c{$juan->id_conductor}", "PED-001-test-c{$juan->id_conductor}"])
        ->and($detalle->json('resumen.generado'))->toBe(40);
});
