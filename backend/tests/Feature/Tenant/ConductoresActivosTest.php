<?php

use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\Vehiculo;
use App\Models\Tenant\VentaViajeConductor;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

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

function conductoresActivosTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function conductoresActivosUsuario(Tenant $tenant, array $overrides = []): Usuario
{
    tenancy()->initialize($tenant);

    $usuario = Usuario::create(array_merge([
        'nombre' => 'Laura',
        'apellido_paterno' => 'Torres',
        'email' => 'laura@cafeluna.com',
        'password' => bcrypt('Password123!'),
        'rol' => 'AdminCliente',
        'estado' => 'Activo',
    ], $overrides));

    tenancy()->end();

    return $usuario;
}

function conductoresActivosCrearConductor(Tenant $tenant, array $overrides = []): Conductor
{
    tenancy()->initialize($tenant);

    $usuario = Usuario::create(array_merge([
        'nombre' => 'Pedro',
        'apellido_paterno' => 'Ruiz',
        'email' => 'pedro'.uniqid().'@cafeluna.com',
        'password' => bcrypt('Password123!'),
        'rol' => 'Conductor',
        'estado' => 'Activo',
    ], $overrides['usuario'] ?? []));

    $conductor = Conductor::create([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'ABC'.uniqid(),
        'estado' => 'ACTIVO',
        'disponibilidad' => $overrides['disponibilidad'] ?? 'DISPONIBLE',
    ]);

    if (array_key_exists('placa', $overrides)) {
        Vehiculo::create([
            'id_conductor' => $conductor->id_conductor,
            'placa' => $overrides['placa'],
            'marca' => 'Nissan',
        ]);
    }

    if (array_key_exists('estado_conexion', $overrides)) {
        ConductorEstado::create([
            'id_conductor' => $conductor->id_conductor,
            'estado' => $overrides['estado_conexion'],
        ]);
    }

    tenancy()->end();

    return $conductor;
}

it('rejects listing conductores activos without a session', function () {
    conductoresActivosTenant();

    $this->getJson('/api/v1/t/cafe-luna/conductores/activos')->assertUnauthorized();
});

it('allows AdminCliente and Despachador to list conductores activos', function () {
    $tenant = conductoresActivosTenant();
    $admin = conductoresActivosUsuario($tenant);
    $despachador = conductoresActivosUsuario($tenant, ['email' => 'pedro@cafeluna.com', 'rol' => 'Despachador']);

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores/activos')
        ->assertOk();

    $this->actingAs($despachador, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores/activos')
        ->assertOk();
});

it('only lists conductores whose conductor_estado is ONLINE', function () {
    $tenant = conductoresActivosTenant();
    $admin = conductoresActivosUsuario($tenant);

    conductoresActivosCrearConductor($tenant, [
        'usuario' => ['nombre' => 'Ana', 'email' => 'ana@cafeluna.com'],
        'placa' => 'MTY-001',
        'estado_conexion' => 'ONLINE',
    ]);
    conductoresActivosCrearConductor($tenant, [
        'usuario' => ['nombre' => 'Bruno', 'email' => 'bruno@cafeluna.com'],
        'placa' => 'MTY-002',
        'estado_conexion' => 'OFFLINE',
    ]);
    conductoresActivosCrearConductor($tenant, [
        'usuario' => ['nombre' => 'Carlos', 'email' => 'carlos@cafeluna.com'],
        'placa' => 'MTY-003',
    ]);

    $response = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores/activos')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.nombre'))->toBe('Ana Ruiz');
});

it('does not filter ONLINE conductores by disponibilidad', function () {
    $tenant = conductoresActivosTenant();
    $admin = conductoresActivosUsuario($tenant);

    conductoresActivosCrearConductor($tenant, [
        'usuario' => ['nombre' => 'Diego', 'email' => 'diego@cafeluna.com'],
        'placa' => 'MTY-004',
        'disponibilidad' => 'DESCANSO',
        'estado_conexion' => 'ONLINE',
    ]);

    $response = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores/activos')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.disponibilidad'))->toBe('DESCANSO');
});

it('returns nombre, disponibilidad and placa ordered by nombre', function () {
    $tenant = conductoresActivosTenant();
    $admin = conductoresActivosUsuario($tenant);

    conductoresActivosCrearConductor($tenant, [
        'usuario' => ['nombre' => 'Zoe', 'email' => 'zoe@cafeluna.com'],
        'placa' => 'MTY-005',
        'disponibilidad' => 'OCUPADO',
        'estado_conexion' => 'ONLINE',
    ]);
    conductoresActivosCrearConductor($tenant, [
        'usuario' => ['nombre' => 'Alan', 'email' => 'alan@cafeluna.com'],
        'placa' => 'MTY-006',
        'disponibilidad' => 'DISPONIBLE',
        'estado_conexion' => 'ONLINE',
    ]);

    $response = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores/activos')
        ->assertOk();

    expect($response->json('data.0.nombre'))->toBe('Alan Ruiz');
    expect($response->json('data.0.disponibilidad'))->toBe('DISPONIBLE');
    expect($response->json('data.0.placa'))->toBe('MTY-006');
    expect($response->json('data.1.nombre'))->toBe('Zoe Ruiz');
});

function conductoresActivosCrearPedido(Tenant $tenant, Conductor $conductor, array $overrides = []): Pedido
{
    tenancy()->initialize($tenant);

    $pedido = Pedido::create(array_merge([
        'numero_pedido' => 'PED-'.random_int(100000, 999999),
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100',
        'latitud_recogida' => 19.4326,
        'longitud_recogida' => -99.1332,
        'direccion_entrega' => 'Av. Insurgentes 200',
        'latitud_entrega' => 19.4200,
        'longitud_entrega' => -99.1600,
        'fecha_servicio' => now()->toDateString(),
        'lo_antes_posible' => true,
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 80,
        'id_conductor' => $conductor->id_conductor,
        'estado' => 'TOMADO',
    ], $overrides));

    tenancy()->end();

    return $pedido;
}

it('returns the marca of the vehicle alongside the placa', function () {
    $tenant = conductoresActivosTenant();
    $admin = conductoresActivosUsuario($tenant);

    conductoresActivosCrearConductor($tenant, [
        'usuario' => ['nombre' => 'Fabio', 'email' => 'fabio@cafeluna.com'],
        'placa' => 'MTY-007',
        'estado_conexion' => 'ONLINE',
    ]);

    $response = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores/activos')
        ->assertOk();

    expect($response->json('data.0.placa'))->toBe('MTY-007');
    expect($response->json('data.0.marca'))->toBe('Nissan');
});

it('returns saldo_viajes as viajes vendidos minus viajes consumidos', function () {
    $tenant = conductoresActivosTenant();
    $admin = conductoresActivosUsuario($tenant);

    $conductor = conductoresActivosCrearConductor($tenant, [
        'usuario' => ['nombre' => 'Gina', 'email' => 'gina@cafeluna.com'],
        'placa' => 'MTY-008',
        'estado_conexion' => 'ONLINE',
    ]);

    tenancy()->initialize($tenant);
    VentaViajeConductor::create([
        'id_conductor' => $conductor->id_conductor,
        'cantidad_viajes' => 10,
        'monto_pagado' => 500,
        'id_usuario' => $admin->id_usuario,
        'fecha_venta' => now(),
    ]);
    tenancy()->end();

    // Dos entregados que sí descontaron prepago y uno que no: el saldo baja solo por los primeros.
    conductoresActivosCrearPedido($tenant, $conductor, ['estado' => 'ENTREGADO', 'prepago_descontado' => true]);
    conductoresActivosCrearPedido($tenant, $conductor, ['estado' => 'ENTREGADO', 'prepago_descontado' => true]);
    conductoresActivosCrearPedido($tenant, $conductor, ['estado' => 'ENTREGADO', 'prepago_descontado' => false]);

    $response = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores/activos')
        ->assertOk();

    expect($response->json('data.0.saldo_viajes'))->toBe(8);
});

it('returns saldo_viajes 0 for a conductor who was never sold viajes', function () {
    $tenant = conductoresActivosTenant();
    $admin = conductoresActivosUsuario($tenant);

    conductoresActivosCrearConductor($tenant, [
        'usuario' => ['nombre' => 'Hugo', 'email' => 'hugo@cafeluna.com'],
        'estado_conexion' => 'ONLINE',
    ]);

    $response = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores/activos')
        ->assertOk();

    expect($response->json('data.0.saldo_viajes'))->toBe(0);
});

it('reports the pedido asignado only while it is not in a final estado', function () {
    $tenant = conductoresActivosTenant();
    $admin = conductoresActivosUsuario($tenant);

    $ocupado = conductoresActivosCrearConductor($tenant, [
        'usuario' => ['nombre' => 'Ivan', 'email' => 'ivan@cafeluna.com'],
        'estado_conexion' => 'ONLINE',
    ]);
    $libre = conductoresActivosCrearConductor($tenant, [
        'usuario' => ['nombre' => 'Julia', 'email' => 'julia@cafeluna.com'],
        'estado_conexion' => 'ONLINE',
    ]);

    conductoresActivosCrearPedido($tenant, $ocupado, ['estado' => 'EN_CAMINO']);
    conductoresActivosCrearPedido($tenant, $libre, ['estado' => 'ENTREGADO']);

    $response = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores/activos')
        ->assertOk();

    expect($response->json('data.0.nombre'))->toBe('Ivan Ruiz');
    expect($response->json('data.0.pedido_asignado'))->not->toBeNull();
    expect($response->json('data.1.nombre'))->toBe('Julia Ruiz');
    expect($response->json('data.1.pedido_asignado'))->toBeNull();
});

it('shows a null placa when the conductor has no vehicle', function () {
    $tenant = conductoresActivosTenant();
    $admin = conductoresActivosUsuario($tenant);

    conductoresActivosCrearConductor($tenant, [
        'usuario' => ['nombre' => 'Erika', 'email' => 'erika@cafeluna.com'],
        'estado_conexion' => 'ONLINE',
    ]);

    $response = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores/activos')
        ->assertOk();

    expect($response->json('data.0.placa'))->toBeNull();
});
