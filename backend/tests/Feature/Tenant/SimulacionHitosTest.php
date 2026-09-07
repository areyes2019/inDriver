<?php

use App\Events\Tenant\PedidoEstadoCambiado;
use App\Jobs\AvanzarEstadoSimulado;
use App\Jobs\SimularSiguientePunto;
use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\PedidoOferta;
use App\Models\Tenant\Usuario;
use App\Services\PedidoEstadoService;
use App\Services\SimuladorRutaService;
use App\Services\TrackingService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);

    // Sin key de Directions en el entorno de test: cae al respaldo de línea recta, sin tocar la red.
    Http::fake(['maps.googleapis.com/*' => Http::response(['routes' => []], 200)]);

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

function hitosTenant(): Tenant
{
    return Tenant::create([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ]);
}

/**
 * @return array{conductor: Conductor, email: string}
 */
function hitosConductor(Tenant $tenant): array
{
    tenancy()->initialize($tenant);
    $usuario = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Ruiz', 'email' => 'ana@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductor = Conductor::create([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'LIC-'.$usuario->id_usuario,
        'estado' => 'ACTIVO',
        'disponibilidad' => 'DISPONIBLE',
    ]);
    tenancy()->end();

    return ['conductor' => $conductor, 'email' => 'ana@cafeluna.com'];
}

function hitosToken(string $email): string
{
    return test()->postJson('/api/v1/t/cafe-luna/conductor/login', [
        'email' => $email,
        'password' => 'Password123!',
    ])->assertOk()->json('token');
}

function hitosPedido(array $overrides = []): Pedido
{
    return Pedido::create(array_merge([
        'numero_pedido' => 'PED-'.random_int(100000, 999999),
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4326, 'longitud_recogida' => -99.1332,
        'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.4200, 'longitud_entrega' => -99.1600,
        'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true,
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO', 'importe_envio' => 80,
        'estado' => 'TOMADO', 'es_prueba' => true,
    ], $overrides));
}

it('starts the approach leg when an es_prueba pedido is accepted', function () {
    Bus::fake([SimularSiguientePunto::class]);

    $tenant = hitosTenant();
    $datos = hitosConductor($tenant);

    tenancy()->initialize($tenant);
    $pedido = hitosPedido(['estado' => 'PUBLICADO', 'id_conductor' => null]);
    PedidoOferta::create([
        'id_pedido' => $pedido->id_pedido,
        'id_conductor' => $datos['conductor']->id_conductor,
        'estado' => 'PENDIENTE',
        'ofrecida_en' => now(),
        'expira_en' => now()->addSeconds(45),
    ]);
    tenancy()->end();

    $this->withToken(hitosToken($datos['email']))
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/aceptar")
        ->assertOk();

    // Antes de la spec tenant/025 esto no ocurría: el simulador solo nacía en EN_CAMINO, un estado
    // al que el viaje no podía llegar sin geocerca, y el pedido se quedaba clavado en TOMADO.
    Bus::assertDispatched(SimularSiguientePunto::class);
});

it('schedules the two motionless steps of the chain', function () {
    Bus::fake([AvanzarEstadoSimulado::class]);

    $tenant = hitosTenant();
    $datos = hitosConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = hitosPedido(['id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();
    $token = hitosToken($datos['email']);

    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'ARRIBADO'])
        ->assertOk();
    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'EN_CAMINO'])
        ->assertOk();
    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'ARRIBADO_A_ENTREGA'])
        ->assertOk();

    // ARRIBADO -> EN_CAMINO y ARRIBADO_A_ENTREGA -> ENTREGADO.
    Bus::assertDispatchedTimes(AvanzarEstadoSimulado::class, 2);
});

it('does not simulate anything for a real pedido', function () {
    Bus::fake([SimularSiguientePunto::class, AvanzarEstadoSimulado::class]);

    $tenant = hitosTenant();
    $datos = hitosConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = hitosPedido(['id_conductor' => $datos['conductor']->id_conductor, 'es_prueba' => false]);
    tenancy()->end();

    $this->withToken(hitosToken($datos['email']))
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'ARRIBADO'])
        ->assertOk();

    Bus::assertNotDispatched(SimularSiguientePunto::class);
    Bus::assertNotDispatched(AvanzarEstadoSimulado::class);
});

it('walks the whole chain from TOMADO to ENTREGADO, one link at a time', function () {
    $tenant = hitosTenant();
    $datos = hitosConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = hitosPedido(['id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();

    // Se corren los eslabones a mano en el orden en que los correría el worker del schedule (spec
    // tenant/024, RN-06). No se hace por HTTP porque con QUEUE_CONNECTION=sync el job arrancaría
    // dentro de `transicionar()`, antes de que el llamador persista, y leería el estado anterior.
    $punto = [['lat' => 19.4200, 'lng' => -99.1600]];

    (new SimularSiguientePunto($tenant, $pedido->id_pedido, $punto, 0, 2, 'TOMADO', 'ARRIBADO'))
        ->handle(app(TrackingService::class), app(PedidoEstadoService::class));
    tenancy()->initialize($tenant);
    expect(Pedido::find($pedido->id_pedido)->estado)->toBe('ARRIBADO');
    tenancy()->end();

    (new AvanzarEstadoSimulado($tenant, $pedido->id_pedido, 'ARRIBADO', 'EN_CAMINO'))
        ->handle(app(PedidoEstadoService::class));
    tenancy()->initialize($tenant);
    expect(Pedido::find($pedido->id_pedido)->estado)->toBe('EN_CAMINO');
    tenancy()->end();

    (new SimularSiguientePunto($tenant, $pedido->id_pedido, $punto, 0, 2, 'EN_CAMINO', 'ARRIBADO_A_ENTREGA'))
        ->handle(app(TrackingService::class), app(PedidoEstadoService::class));
    tenancy()->initialize($tenant);
    expect(Pedido::find($pedido->id_pedido)->estado)->toBe('ARRIBADO_A_ENTREGA');
    tenancy()->end();

    (new AvanzarEstadoSimulado($tenant, $pedido->id_pedido, 'ARRIBADO_A_ENTREGA', 'ENTREGADO'))
        ->handle(app(PedidoEstadoService::class));
    tenancy()->initialize($tenant);
    expect(Pedido::find($pedido->id_pedido)->estado)->toBe('ENTREGADO');
    tenancy()->end();
});

it('stops the running leg when the conductor advanced the hito by hand', function () {
    $tenant = hitosTenant();
    $datos = hitosConductor($tenant);
    tenancy()->initialize($tenant);
    // Ya va EN_CAMINO: el conductor adelantó la recogida mientras el tramo de acercamiento seguía
    // vivo (spec tenant/025, RN-04).
    $pedido = hitosPedido(['id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'EN_CAMINO']);
    tenancy()->end();

    (new SimularSiguientePunto($tenant, $pedido->id_pedido, [['lat' => 19.43, 'lng' => -99.13]], 0, 2, 'TOMADO', 'ARRIBADO'))
        ->handle(app(TrackingService::class), app(PedidoEstadoService::class));

    tenancy()->initialize($tenant);
    expect(Pedido::find($pedido->id_pedido)->estado)->toBe('EN_CAMINO');
    tenancy()->end();
});

it('starts the approach leg from the last known position of the conductor', function () {
    Bus::fake([SimularSiguientePunto::class]);

    $tenant = hitosTenant();
    $datos = hitosConductor($tenant);
    tenancy()->initialize($tenant);
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'estado' => 'ONLINE',
        'ultima_latitud' => 19.5000,
        'ultima_longitud' => -99.2000,
    ]);
    $pedido = hitosPedido(['id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'PUBLICADO']);
    tenancy()->end();

    // Un conductor sin posición previa no puede bloquear el hito: el tramo arranca en la recogida y
    // ARRIBADO cae de inmediato. Con posición previa, el tramo arranca donde está.
    tenancy()->initialize($tenant);
    $pedidoModelo = Pedido::find($pedido->id_pedido);
    app(SimuladorRutaService::class)->iniciarAcercamiento($pedidoModelo);
    tenancy()->end();

    Bus::assertDispatched(SimularSiguientePunto::class);
});

it('announces every state change of a pedido with conductor', function () {
    Event::fake([PedidoEstadoCambiado::class]);

    $tenant = hitosTenant();
    $datos = hitosConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = hitosPedido(['id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();

    $this->withToken(hitosToken($datos['email']))
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'ARRIBADO'])
        ->assertOk();

    Event::assertDispatched(
        PedidoEstadoCambiado::class,
        fn (PedidoEstadoCambiado $evento) => $evento->estado === 'ARRIBADO'
            && $evento->idConductor === $datos['conductor']->id_conductor,
    );
});

it('tells the app whether the pedido is a test one', function () {
    $tenant = hitosTenant();
    $datos = hitosConductor($tenant);
    tenancy()->initialize($tenant);
    hitosPedido(['id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();

    $this->withToken(hitosToken($datos['email']))
        ->getJson('/api/v1/t/cafe-luna/conductor/pedidos/activo')
        ->assertOk()
        ->assertJsonPath('es_prueba', true);
});
