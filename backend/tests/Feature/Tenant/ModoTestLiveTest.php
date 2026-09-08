<?php

use App\Events\Tenant\UbicacionActualizada;
use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConductorPosicion;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\SimulacionEnvio;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\VentaViajeConductor;
use App\Services\PedidoEstadoService;
use App\Services\SimulacionEnvioService;
use App\Support\ContextoAmbiente;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);

    // Ninguna prueba sale a internet (spec tenant/025): Directions se responde con una polilínea
    // guardada. Es la recta de ~1113 m entre (19.40, -99.10) y (19.41, -99.10).
    Http::fake([
        'maps.googleapis.com/*' => Http::response([
            'routes' => [['overview_polyline' => ['points' => '_gc_Bnn_gEqvqB??']]],
        ]),
    ]);

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
    app(ContextoAmbiente::class)->limpiar();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

function ambienteTenant(): Tenant
{
    return Tenant::create([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ]);
}

function ambienteUsuario(Tenant $tenant, string $rol = 'AdminCliente', string $email = 'laura@cafeluna.com'): Usuario
{
    tenancy()->initialize($tenant);
    $usuario = Usuario::create([
        'nombre' => 'Laura', 'apellido_paterno' => 'Torres', 'email' => $email,
        'password' => bcrypt('Password123!'), 'rol' => $rol, 'estado' => 'Activo',
    ]);
    tenancy()->end();

    return $usuario;
}

/**
 * @return array{usuario: Usuario, conductor: Conductor}
 */
function ambienteConductor(Tenant $tenant): array
{
    tenancy()->initialize($tenant);
    $usuario = Usuario::create([
        'nombre' => 'Beto', 'apellido_paterno' => 'Salgado', 'email' => 'beto@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductor = Conductor::create([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'LIC-'.$usuario->id_usuario,
        'estado' => 'ACTIVO',
        'disponibilidad' => 'DISPONIBLE',
    ]);
    tenancy()->end();

    return ['usuario' => $usuario, 'conductor' => $conductor];
}

function ambienteToken(): string
{
    return test()->postJson('/api/v1/t/cafe-luna/conductor/login', [
        'email' => 'beto@cafeluna.com',
        'password' => 'Password123!',
    ])->assertOk()->json('token');
}

/** Crea un pedido dentro del tenant ya inicializado, sellando su ambiente a mano. */
function ambientePedido(array $overrides = [], string $ambiente = Pedido::AMBIENTE_LIVE): Pedido
{
    $pedido = Pedido::create(array_merge([
        'numero_pedido' => 'PED-'.random_int(100000, 999999),
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4000, 'longitud_recogida' => -99.1000,
        'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.4100, 'longitud_entrega' => -99.1000,
        'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true, 'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 80, 'estado' => 'TOMADO',
    ], $overrides));

    $pedido->ambiente = $ambiente;
    $pedido->save();

    return $pedido->fresh();
}

function configurarTarifas(): void
{
    ConfiguracionTenant::establecer(ConfiguracionTenant::BANDERAZO, '35');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_INCLUIDOS, '3');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_ADICIONAL, '8');
}

// ---------------------------------------------------------------------------------------------
// El interruptor y el sellado del ambiente (RN-01 a RN-04)
// ---------------------------------------------------------------------------------------------

it('seals the envío with the tenant switch and keeps live as the default', function () {
    $tenant = ambienteTenant();
    $admin = ambienteUsuario($tenant);

    tenancy()->initialize($tenant);
    configurarTarifas();
    tenancy()->end();

    $datos = [
        'nombre_solicitante' => 'Mario Sánchez', 'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4, 'longitud_recogida' => -99.1,
        'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.41, 'longitud_entrega' => -99.1,
        'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true,
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO', 'importe_envio' => 80,
    ];

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', $datos)
        ->assertCreated();

    $this->actingAs($admin, 'usuario')
        ->putJson('/api/v1/t/cafe-luna/configuracion/ambiente', ['ambiente' => 'test'])
        ->assertOk()
        ->assertJson(['ambiente' => 'test']);

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', $datos)
        ->assertCreated();

    tenancy()->initialize($tenant);
    $ambientes = Pedido::withoutGlobalScopes()->orderBy('id_pedido')->pluck('ambiente')->all();
    expect($ambientes)->toBe(['live', 'test']);
    // El correlativo es del tenant entero, no del ambiente.
    expect(Pedido::withoutGlobalScopes()->orderBy('id_pedido')->pluck('numero_pedido')->all())
        ->toBe(['PED-000001', 'PED-000002']);
    tenancy()->end();
});

it('never lets the ambiente change after the envío was created', function () {
    $tenant = ambienteTenant();
    $admin = ambienteUsuario($tenant);

    tenancy()->initialize($tenant);
    configurarTarifas();
    $pedido = ambientePedido(['estado' => 'PENDIENTE'], Pedido::AMBIENTE_TEST);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}", [
            'nombre_solicitante' => 'Otro Nombre', 'telefono_solicitante' => '5599887766',
            'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4, 'longitud_recogida' => -99.1,
            'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.41, 'longitud_entrega' => -99.1,
            'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true,
            'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO', 'importe_envio' => 80, 'ambiente' => 'live',
        ]);

    tenancy()->initialize($tenant);
    expect(Pedido::withoutGlobalScopes()->find($pedido->id_pedido)->ambiente)->toBe('test');
    tenancy()->end();
});

it('shows the panel only the envíos of the active ambiente', function () {
    $tenant = ambienteTenant();
    $admin = ambienteUsuario($tenant);

    tenancy()->initialize($tenant);
    ambientePedido(['estado' => 'PENDIENTE'], Pedido::AMBIENTE_LIVE);
    ambientePedido(['estado' => 'PENDIENTE'], Pedido::AMBIENTE_TEST);
    tenancy()->end();

    $enLive = $this->actingAs($admin, 'usuario')->getJson('/api/v1/t/cafe-luna/pedidos')->assertOk();
    expect($enLive->json('data'))->toHaveCount(1);
    expect($enLive->json('data.0.ambiente'))->toBe('live');

    $this->actingAs($admin, 'usuario')
        ->putJson('/api/v1/t/cafe-luna/configuracion/ambiente', ['ambiente' => 'test'])
        ->assertOk();

    $enTest = $this->actingAs($admin, 'usuario')->getJson('/api/v1/t/cafe-luna/pedidos')->assertOk();
    expect($enTest->json('data'))->toHaveCount(1);
    expect($enTest->json('data.0.ambiente'))->toBe('test');
});

it('lets a despachador see the ambiente but not change it', function () {
    $tenant = ambienteTenant();
    $despachador = ambienteUsuario($tenant, 'Despachador', 'dani@cafeluna.com');

    $this->actingAs($despachador, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/configuracion')
        ->assertOk()
        ->assertJson(['ambiente' => 'live']);

    $this->actingAs($despachador, 'usuario')
        ->putJson('/api/v1/t/cafe-luna/configuracion/ambiente', ['ambiente' => 'test'])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------------------------
// La simulación (RN-07 a RN-15)
// ---------------------------------------------------------------------------------------------

it('opens the approach leg when a test envío is taken, and not for a live one', function () {
    $tenant = ambienteTenant();
    $datos = ambienteConductor($tenant);

    tenancy()->initialize($tenant);
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'ultima_latitud' => 19.4000, 'ultima_longitud' => -99.1000, 'ultima_actualizacion' => now(),
    ]);

    $test = ambientePedido([
        'id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'PUBLICADO',
    ], Pedido::AMBIENTE_TEST);
    app(PedidoEstadoService::class)->transicionar($test, 'TOMADO');
    $test->save();

    expect(SimulacionEnvio::where('id_pedido', $test->id_pedido)->where('tramo', 'ACERCAMIENTO')->exists())->toBeTrue();

    $live = ambientePedido(['estado' => 'PUBLICADO'], Pedido::AMBIENTE_LIVE);
    app(PedidoEstadoService::class)->transicionar($live, 'TOMADO');
    $live->save();

    expect(SimulacionEnvio::where('id_pedido', $live->id_pedido)->exists())->toBeFalse();
    tenancy()->end();
});

it('writes simulated positions through the same tracking door and broadcasts them', function () {
    Event::fake([UbicacionActualizada::class]);

    $tenant = ambienteTenant();
    $datos = ambienteConductor($tenant);

    tenancy()->initialize($tenant);
    // Sin esto el tramo de acercamiento mediría cero y ARRIBADO caería de inmediato (RN-08).
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'ultima_latitud' => 19.3800, 'ultima_longitud' => -99.1000, 'ultima_actualizacion' => now(),
    ]);
    $pedido = ambientePedido([
        'id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'PUBLICADO',
    ], Pedido::AMBIENTE_TEST);

    app(PedidoEstadoService::class)->transicionar($pedido, 'TOMADO');
    $pedido->save();

    // 60 km/h = 1000 m/min: a los 30 segundos el móvil lleva unos 500 m recorridos.
    $this->travel(30)->seconds();
    app(SimulacionEnvioService::class)->avanzarPendientes();

    $simulacion = SimulacionEnvio::where('id_pedido', $pedido->id_pedido)->first();
    expect($simulacion->avanzada_hasta_m)->toBeGreaterThan(450.0)->toBeLessThan(550.0);
    expect(ConductorPosicion::where('id_pedido', $pedido->id_pedido)->count())->toBeGreaterThan(10);
    expect(ConductorEstado::where('id_conductor', $datos['conductor']->id_conductor)->first()->ultima_latitud)
        ->not->toBeNull();
    tenancy()->end();

    Event::assertDispatched(UbicacionActualizada::class);
});

it('advances the envío to ARRIBADO by itself when the approach leg finishes', function () {
    $tenant = ambienteTenant();
    $datos = ambienteConductor($tenant);

    tenancy()->initialize($tenant);
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'ultima_latitud' => 19.3800, 'ultima_longitud' => -99.1000, 'ultima_actualizacion' => now(),
    ]);
    $pedido = ambientePedido([
        'id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'PUBLICADO',
    ], Pedido::AMBIENTE_TEST);

    app(PedidoEstadoService::class)->transicionar($pedido, 'TOMADO');
    $pedido->save();

    // La ruta simulada mide ~1113 m: a 60 km/h son poco más de un minuto.
    $this->travel(5)->minutes();
    app(SimulacionEnvioService::class)->avanzarPendientes();

    expect($pedido->fresh()->estado)->toBe('ARRIBADO');
    expect(SimulacionEnvio::where('id_pedido', $pedido->id_pedido)->first()->terminada_en)->not->toBeNull();
    tenancy()->end();
});

it('opens the delivery leg on EN_CAMINO and lands on ARRIBADO_A_ENTREGA', function () {
    $tenant = ambienteTenant();
    $datos = ambienteConductor($tenant);

    tenancy()->initialize($tenant);
    $pedido = ambientePedido([
        'id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'ARRIBADO',
    ], Pedido::AMBIENTE_TEST);

    app(PedidoEstadoService::class)->transicionar($pedido, 'EN_CAMINO');
    $pedido->save();

    expect(SimulacionEnvio::where('id_pedido', $pedido->id_pedido)->where('tramo', 'ENTREGA')->exists())->toBeTrue();

    $this->travel(5)->minutes();
    app(SimulacionEnvioService::class)->avanzarPendientes();

    expect($pedido->fresh()->estado)->toBe('ARRIBADO_A_ENTREGA');
    tenancy()->end();
});

it('recovers on its own after a missed run, because the position comes from the clock', function () {
    $tenant = ambienteTenant();
    $datos = ambienteConductor($tenant);

    tenancy()->initialize($tenant);
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'ultima_latitud' => 19.3800, 'ultima_longitud' => -99.1000, 'ultima_actualizacion' => now(),
    ]);
    $pedido = ambientePedido([
        'id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'PUBLICADO',
    ], Pedido::AMBIENTE_TEST);

    app(PedidoEstadoService::class)->transicionar($pedido, 'TOMADO');
    $pedido->save();

    // Nadie corrió el comando en todo ese rato: el servidor estuvo caído o el cron apagado.
    $this->travel(20)->minutes();
    app(SimulacionEnvioService::class)->avanzarPendientes();

    expect($pedido->fresh()->estado)->toBe('ARRIBADO');
    tenancy()->end();
});

it('keeps a constant 60 km/h regardless of how long the route is', function () {
    $tenant = ambienteTenant();
    $datos = ambienteConductor($tenant);

    // Ruta diez veces más larga que la de las otras pruebas (~11 km en línea recta).
    Http::fake(['maps.googleapis.com/*' => Http::response(['routes' => []])]);

    tenancy()->initialize($tenant);
    $pedido = ambientePedido([
        'id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'PUBLICADO',
        'latitud_recogida' => 19.4000, 'longitud_recogida' => -99.1000,
        'latitud_entrega' => 19.5000, 'longitud_entrega' => -99.1000,
    ], Pedido::AMBIENTE_TEST);
    $pedido->estado = 'ARRIBADO';
    $pedido->save();

    app(PedidoEstadoService::class)->transicionar($pedido, 'EN_CAMINO');
    $pedido->save();

    $simulacion = SimulacionEnvio::where('id_pedido', $pedido->id_pedido)->where('tramo', 'ENTREGA')->first();
    expect($simulacion->distancia_m)->toBeGreaterThan(10000.0);

    // Un minuto de reloj son 1000 metros, mida lo que mida la ruta. El simulador anterior repartía
    // 40 puntos fijos y por eso la velocidad dependía del largo del viaje.
    $this->travel(60)->seconds();
    app(SimulacionEnvioService::class)->avanzarPendientes();

    expect($simulacion->fresh()->avanzada_hasta_m)->toBeGreaterThan(950.0)->toBeLessThan(1050.0);
    expect($pedido->fresh()->estado)->toBe('EN_CAMINO');
    tenancy()->end();
});

it('falls back to a straight line when Directions is down', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(null, 500)]);

    $tenant = ambienteTenant();
    $datos = ambienteConductor($tenant);

    tenancy()->initialize($tenant);
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'ultima_latitud' => 19.3800, 'ultima_longitud' => -99.1000, 'ultima_actualizacion' => now(),
    ]);
    $pedido = ambientePedido([
        'id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'PUBLICADO',
    ], Pedido::AMBIENTE_TEST);

    app(PedidoEstadoService::class)->transicionar($pedido, 'TOMADO');
    $pedido->save();

    $simulacion = SimulacionEnvio::where('id_pedido', $pedido->id_pedido)->first();
    expect($simulacion)->not->toBeNull();
    expect($simulacion->ruta)->toHaveCount(2);

    $this->travel(5)->minutes();
    app(SimulacionEnvioService::class)->avanzarPendientes();

    expect($pedido->fresh()->estado)->toBe('ARRIBADO');
    tenancy()->end();
});

it('stops the simulation when the envío is cancelled', function () {
    $tenant = ambienteTenant();
    $datos = ambienteConductor($tenant);

    tenancy()->initialize($tenant);
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'ultima_latitud' => 19.3800, 'ultima_longitud' => -99.1000, 'ultima_actualizacion' => now(),
    ]);
    $pedido = ambientePedido([
        'id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'PUBLICADO',
    ], Pedido::AMBIENTE_TEST);

    app(PedidoEstadoService::class)->transicionar($pedido, 'TOMADO');
    $pedido->save();

    $pedido->estado = 'CANCELADO';
    $pedido->save();

    $this->travel(5)->minutes();
    app(SimulacionEnvioService::class)->avanzarPendientes();

    expect(ConductorPosicion::where('id_pedido', $pedido->id_pedido)->count())->toBe(0);
    expect(SimulacionEnvio::where('id_pedido', $pedido->id_pedido)->first()->terminada_en)->not->toBeNull();
    tenancy()->end();
});

// ---------------------------------------------------------------------------------------------
// El GPS real y la app del conductor (RN-11, RN-16)
// ---------------------------------------------------------------------------------------------

it('discards the real GPS while a test envío is running, but keeps it on a live one', function () {
    $tenant = ambienteTenant();
    $datos = ambienteConductor($tenant);

    tenancy()->initialize($tenant);
    $test = ambientePedido([
        'id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'TOMADO',
    ], Pedido::AMBIENTE_TEST);
    tenancy()->end();

    $token = ambienteToken();

    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/ubicacion', ['latitud' => 19.99, 'longitud' => -99.99])
        ->assertNoContent();

    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$test->id_pedido}/ubicaciones/lote", [
            'puntos' => [['latitud' => 19.98, 'longitud' => -99.98, 'fecha_posicion' => now()->toIso8601String()]],
        ])
        ->assertNoContent();

    tenancy()->initialize($tenant);
    expect(ConductorPosicion::count())->toBe(0);

    // El mismo conductor, con un envío LIVE, sigue escribiendo exactamente como hoy.
    $test->estado = 'ENTREGADO';
    $test->save();
    ambientePedido(['id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'TOMADO'], Pedido::AMBIENTE_LIVE);
    tenancy()->end();

    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/ubicacion', ['latitud' => 19.43, 'longitud' => -99.13])
        ->assertNoContent();

    tenancy()->initialize($tenant);
    expect(ConductorPosicion::count())->toBe(1);
    tenancy()->end();
});

it('lets the driver app see a test envío despite the panel scope', function () {
    $tenant = ambienteTenant();
    $datos = ambienteConductor($tenant);

    tenancy()->initialize($tenant);
    $pedido = ambientePedido([
        'id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'TOMADO',
    ], Pedido::AMBIENTE_TEST);
    tenancy()->end();

    $this->withToken(ambienteToken())
        ->getJson('/api/v1/t/cafe-luna/conductor/pedidos/activo')
        ->assertOk()
        ->assertJsonPath('id_pedido', $pedido->id_pedido);
});

// ---------------------------------------------------------------------------------------------
// El dinero (RN-06)
// ---------------------------------------------------------------------------------------------

it('settles a delivered test envío exactly like a live one', function () {
    $tenant = ambienteTenant();
    $datos = ambienteConductor($tenant);

    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, 'Prepago');
    VentaViajeConductor::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'id_usuario' => $datos['usuario']->id_usuario,
        'cantidad_viajes' => 5,
        'fecha_venta' => now(),
    ]);

    $pedido = ambientePedido([
        'id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'ARRIBADO_A_ENTREGA',
    ], Pedido::AMBIENTE_TEST);

    app(PedidoEstadoService::class)->transicionar($pedido, 'ENTREGADO');
    $pedido->save();

    // El envío de prueba consume un viaje real: es la decisión explícita de la spec (RN-06).
    expect($pedido->fresh()->prepago_descontado)->toBeTrue();
    tenancy()->end();
});

// ---------------------------------------------------------------------------------------------
// Señal de vida: el conductor no puede quedar apagado por estar en un envío simulado
// ---------------------------------------------------------------------------------------------

it('keeps the driver alive while its real GPS is being discarded in TEST', function () {
    $tenant = ambienteTenant();
    $datos = ambienteConductor($tenant);

    tenancy()->initialize($tenant);
    // Señal de vida rancia, como la de un conductor que vuelve al día siguiente.
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'estado' => 'ONLINE',
        'ultima_conexion' => now(),
        'ultima_actualizacion' => now()->subHours(9),
    ]);
    ambientePedido([
        'id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'TOMADO',
    ], Pedido::AMBIENTE_TEST);
    tenancy()->end();

    $this->withToken(ambienteToken())
        ->postJson('/api/v1/t/cafe-luna/conductor/ubicacion', ['latitud' => 19.99, 'longitud' => -99.99])
        ->assertNoContent();

    tenancy()->initialize($tenant);
    $estado = ConductorEstado::where('id_conductor', $datos['conductor']->id_conductor)->first();
    // La posición se descarta, la señal de vida no.
    expect(ConductorPosicion::count())->toBe(0);
    expect($estado->ultima_actualizacion->greaterThan(now()->subMinutes(1)))->toBeTrue();

    // Y por tanto el barrido de inactivos no lo apaga.
    tenancy()->end();
    $this->artisan('conductor:apagar-inactivos')->assertSuccessful();

    tenancy()->initialize($tenant);
    expect(ConductorEstado::where('id_conductor', $datos['conductor']->id_conductor)->first()->estado)->toBe('ONLINE');
    tenancy()->end();
});

it('treats going online as a sign of life, so the sweeper does not turn it off right away', function () {
    $tenant = ambienteTenant();
    $datos = ambienteConductor($tenant);

    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, 'Prepago');
    VentaViajeConductor::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'id_usuario' => $datos['usuario']->id_usuario,
        'cantidad_viajes' => 5,
        'fecha_venta' => now(),
    ]);
    // Su última posición conocida es de ayer: el estado que deja una sesión anterior.
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'estado' => 'OFFLINE',
        'ultima_actualizacion' => now()->subHours(9),
    ]);
    tenancy()->end();

    $this->withToken(ambienteToken())
        ->postJson('/api/v1/t/cafe-luna/conductor/estado', ['estado' => 'ONLINE'])
        ->assertSuccessful();

    $this->artisan('conductor:apagar-inactivos')->assertSuccessful();

    tenancy()->initialize($tenant);
    expect(ConductorEstado::where('id_conductor', $datos['conductor']->id_conductor)->first()->estado)->toBe('ONLINE');
    tenancy()->end();
});
