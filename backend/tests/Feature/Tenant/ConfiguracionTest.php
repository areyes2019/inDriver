<?php

use App\Models\Tenant;
use App\Models\Tenant\Despachador;
use App\Models\Tenant\Usuario;
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

function configuracionTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function configuracionAdminUsuario(Tenant $tenant, array $overrides = []): Usuario
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

function configuracionDatosValidos(array $overrides = []): array
{
    return array_merge([
        'tarifa_banderazo' => 10,
        'km_incluidos_banderazo' => 5,
        'tarifa_km_adicional' => 5,
        'modalidad_conductores' => 'Prepago',
        'costo_viaje_prepago' => 20,
        'usar_despachadores' => 'No',
    ], $overrides);
}

it('defaults usar_despachadores to No when never configured', function () {
    $tenant = configuracionTenant();
    $admin = configuracionAdminUsuario($tenant);

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/configuracion')
        ->assertOk()
        ->assertJsonPath('usar_despachadores', 'No');
});

it('reports tarifas_configuradas as false and null tarifas when never configured', function () {
    $tenant = configuracionTenant();
    $admin = configuracionAdminUsuario($tenant);

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/configuracion')
        ->assertOk()
        ->assertJsonPath('tarifas_configuradas', false)
        ->assertJsonPath('tarifa_banderazo', null)
        ->assertJsonPath('km_incluidos_banderazo', null)
        ->assertJsonPath('tarifa_km_adicional', null);
});

it('reports tarifas_configuradas as true once the three tarifas are saved', function () {
    $tenant = configuracionTenant();
    $admin = configuracionAdminUsuario($tenant);

    $this->actingAs($admin, 'usuario')
        ->putJson('/api/v1/t/cafe-luna/configuracion', configuracionDatosValidos())
        ->assertOk()
        ->assertJsonPath('tarifas_configuradas', true)
        ->assertJsonPath('tarifa_banderazo', '10')
        ->assertJsonPath('km_incluidos_banderazo', '5')
        ->assertJsonPath('tarifa_km_adicional', '5');
});

it('rejects saving km_incluidos_banderazo as zero', function () {
    $tenant = configuracionTenant();
    $admin = configuracionAdminUsuario($tenant);

    $this->actingAs($admin, 'usuario')
        ->putJson('/api/v1/t/cafe-luna/configuracion', configuracionDatosValidos(['km_incluidos_banderazo' => 0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['km_incluidos_banderazo']);
});

it('rejects saving configuracion without usar_despachadores', function () {
    $tenant = configuracionTenant();
    $admin = configuracionAdminUsuario($tenant);

    $this->actingAs($admin, 'usuario')
        ->putJson('/api/v1/t/cafe-luna/configuracion', configuracionDatosValidos(['usar_despachadores' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['usar_despachadores']);
});

it('sets all despachadores to Inactivo when usar_despachadores changes from Sí to No', function () {
    $tenant = configuracionTenant();
    $admin = configuracionAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioActivo = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Despachador', 'estado' => 'Activo',
    ]);
    $despachadorActivo = Despachador::create(['id_usuario' => $usuarioActivo->id_usuario, 'estado' => 'Activo']);

    $usuarioSuspendido = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Gómez', 'email' => 'ana@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Despachador', 'estado' => 'Activo',
    ]);
    $despachadorSuspendido = Despachador::create(['id_usuario' => $usuarioSuspendido->id_usuario, 'estado' => 'Suspendido']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson('/api/v1/t/cafe-luna/configuracion', configuracionDatosValidos(['usar_despachadores' => 'Sí']))
        ->assertOk()
        ->assertJsonPath('usar_despachadores', 'Sí');

    $this->actingAs($admin, 'usuario')
        ->putJson('/api/v1/t/cafe-luna/configuracion', configuracionDatosValidos(['usar_despachadores' => 'No']))
        ->assertOk()
        ->assertJsonPath('usar_despachadores', 'No');

    tenancy()->initialize($tenant);
    expect($despachadorActivo->fresh()->estado)->toBe('Inactivo');
    expect($despachadorSuspendido->fresh()->estado)->toBe('Inactivo');
    expect(Usuario::find($usuarioActivo->id_usuario)->estado)->toBe('Activo');
    tenancy()->end();
});
