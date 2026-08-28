<?php

use App\Models\Tenant;
use App\Models\Tenant\Auditoria;
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

function vehiculoTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function vehiculoAdminUsuario(Tenant $tenant, array $overrides = []): Usuario
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

it('rejects listing vehiculos without a session', function () {
    vehiculoTenant();

    $this->getJson('/api/v1/t/cafe-luna/vehiculos')->assertUnauthorized();
});

it('rejects vehiculos access for a non-AdminCliente role', function () {
    $tenant = vehiculoTenant();
    $despachador = vehiculoAdminUsuario($tenant, [
        'email' => 'pedro@cafeluna.com',
        'rol' => 'Despachador',
    ]);

    $this->actingAs($despachador, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/vehiculos')
        ->assertForbidden();
});

it('creates a vehiculo Activo by default and logs it', function () {
    $tenant = vehiculoTenant();
    $admin = vehiculoAdminUsuario($tenant);

    $response = $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/vehiculos', [
            'placa' => 'ABC-123',
            'marca' => 'Honda',
            'modelo' => 'CG150',
        ])
        ->assertCreated()
        ->assertJsonPath('estado', 'ACTIVO');

    tenancy()->initialize($tenant);
    expect(Auditoria::where('tabla_afectada', 'vehiculos')->where('accion', 'ALTA')->exists())->toBeTrue();
    tenancy()->end();

    expect($response->json('id_vehiculo'))->not->toBeNull();
});

it('rejects creating a vehiculo with a duplicate placa', function () {
    $tenant = vehiculoTenant();
    $admin = vehiculoAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    Vehiculo::create(['placa' => 'ABC-123', 'estado' => 'ACTIVO']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/vehiculos', ['placa' => 'ABC-123'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['placa']);
});

it('lists vehiculos ordered by placa and filters by search', function () {
    $tenant = vehiculoTenant();
    $admin = vehiculoAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    Vehiculo::create(['placa' => 'ZZZ-999', 'estado' => 'ACTIVO']);
    Vehiculo::create(['placa' => 'AAA-111', 'estado' => 'ACTIVO']);
    tenancy()->end();

    $response = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/vehiculos')
        ->assertOk();

    expect($response->json('data.0.placa'))->toBe('AAA-111');
    expect($response->json('data.1.placa'))->toBe('ZZZ-999');

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/vehiculos?search=ZZZ')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.placa', 'ZZZ-999');
});

it('updates a vehiculo including its estado, without deleting it', function () {
    $tenant = vehiculoTenant();
    $admin = vehiculoAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $vehiculo = Vehiculo::create(['placa' => 'ABC-123', 'estado' => 'ACTIVO']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/vehiculos/{$vehiculo->id_vehiculo}", [
            'placa' => 'ABC-123',
            'estado' => 'INACTIVO',
        ])
        ->assertOk()
        ->assertJsonPath('estado', 'INACTIVO');

    tenancy()->initialize($tenant);
    expect(Vehiculo::find($vehiculo->id_vehiculo))->not->toBeNull();
    expect(Auditoria::where('tabla_afectada', 'vehiculos')->where('accion', 'EDICION')->exists())->toBeTrue();
    tenancy()->end();
});
