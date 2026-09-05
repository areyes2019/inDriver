<?php

use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\Vehiculo;
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
