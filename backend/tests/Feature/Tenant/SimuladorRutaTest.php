<?php

use App\Jobs\SimularSiguientePunto;
use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorPosicion;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\VentaViajeConductor;
use App\Services\TrackingService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

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

function simuladorTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

/**
 * @return array{usuario: Usuario, conductor: Conductor, password: string}
 */
function simuladorConductor(Tenant $tenant, array $overrides = []): array
{
    tenancy()->initialize($tenant);
    $password = 'Password123!';
    $sufijo = $overrides['numero_licencia'] ?? uniqid();
    $email = "conductor-{$sufijo}@cafeluna.com";
    $usuario = Usuario::create([
        'nombre' => 'Beto', 'apellido_paterno' => 'Salgado', 'email' => $email,
        'password' => bcrypt($password), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductor = Conductor::create(array_merge([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'LIC-'.$usuario->id_usuario,
        'estado' => 'ACTIVO',
        'disponibilidad' => 'DISPONIBLE',
    ], $overrides));
    tenancy()->end();

    return ['usuario' => $usuario, 'conductor' => $conductor, 'password' => $password, 'email' => $email];
}

function simuladorToken(string $email, string $password = 'Password123!'): string
{
    return test()->postJson('/api/v1/t/cafe-luna/conductor/login', [
        'email' => $email,
        'password' => $password,
    ])->assertOk()->json('token');
}

function simuladorPedido(array $overrides = []): Pedido
{
    return Pedido::create(array_merge([
        'numero_pedido' => 'PED-'.random_int(100000, 999999),
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4326, 'longitud_recogida' => -99.1332,
        'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.4200, 'longitud_entrega' => -99.1600,
        'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true, 'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 80, 'estado' => 'TOMADO',
    ], $overrides));
}

it('ignores the real GPS the phone reports for an es_prueba pedido', function () {
    $tenant = simuladorTenant();
    $datos = simuladorConductor($tenant);
    tenancy()->initialize($tenant);
    simuladorPedido(['id_conductor' => $datos['conductor']->id_conductor, 'es_prueba' => true]);
    tenancy()->end();
    $token = simuladorToken($datos['email']);

    // El teléfono sigue llamando exactamente igual — panda_express nunca se entera de que su
    // reporte fue descartado (spec "PWA agnóstica a LIVE/TEST"): responde 204 igual que siempre.
    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/ubicacion', ['latitud' => 19.43, 'longitud' => -99.13])
        ->assertNoContent();

    tenancy()->initialize($tenant);
    expect(ConductorPosicion::count())->toBe(0);
    tenancy()->end();
});

it('records the position when it comes from the simulator, even for an es_prueba pedido', function () {
    $tenant = simuladorTenant();
    $datos = simuladorConductor($tenant);
    tenancy()->initialize($tenant);
    simuladorPedido(['id_conductor' => $datos['conductor']->id_conductor, 'es_prueba' => true]);

    app(TrackingService::class)->registrarPosicion(
        $datos['conductor'],
        ['latitud' => 19.44, 'longitud' => -99.14],
        desdeSimulador: true,
    );

    expect(ConductorPosicion::count())->toBe(1);
    tenancy()->end();
});

it('dispatches SimularSiguientePunto when an es_prueba pedido reaches EN_CAMINO', function () {
    Bus::fake([SimularSiguientePunto::class]);
    // Sin key de Directions en el entorno de test: cae al fallback de línea recta, sin llamar red.
    Http::fake(['maps.googleapis.com/*' => Http::response(['routes' => []], 200)]);

    $tenant = simuladorTenant();
    $datos = simuladorConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = simuladorPedido(['id_conductor' => $datos['conductor']->id_conductor, 'es_prueba' => true]);
    tenancy()->end();
    $token = simuladorToken($datos['email']);

    $this->withToken($token)->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'ARRIBADO'])->assertOk();
    $this->withToken($token)->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'EN_CAMINO'])->assertOk();

    Bus::assertDispatchedTimes(SimularSiguientePunto::class, 1);
});

it('does not dispatch SimularSiguientePunto for a real (non es_prueba) pedido', function () {
    Bus::fake([SimularSiguientePunto::class]);

    $tenant = simuladorTenant();
    $datos = simuladorConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = simuladorPedido(['id_conductor' => $datos['conductor']->id_conductor, 'es_prueba' => false]);
    tenancy()->end();
    $token = simuladorToken($datos['email']);

    $this->withToken($token)->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'ARRIBADO'])->assertOk();
    $this->withToken($token)->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'EN_CAMINO'])->assertOk();

    Bus::assertNotDispatched(SimularSiguientePunto::class);
});

it('does not touch the real prepago balance when delivering an es_prueba pedido', function () {
    $tenant = simuladorTenant();
    $datos = simuladorConductor($tenant);

    tenancy()->initialize($tenant);
    VentaViajeConductor::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'cantidad_viajes' => 10,
        'monto_pagado' => 500,
        'id_usuario' => $datos['usuario']->id_usuario,
        'fecha_venta' => now(),
    ]);
    $pedido = simuladorPedido(['id_conductor' => $datos['conductor']->id_conductor, 'es_prueba' => true, 'estado' => 'ARRIBADO_A_ENTREGA']);
    tenancy()->end();
    $token = simuladorToken($datos['email']);

    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'ENTREGADO'])
        ->assertOk();

    tenancy()->initialize($tenant);
    expect(Pedido::find($pedido->id_pedido)->prepago_descontado)->toBeFalse();
    tenancy()->end();
});
