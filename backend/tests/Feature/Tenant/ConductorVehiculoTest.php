<?php

use App\Models\Tenant;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorVehiculo;
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

function cvTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function cvAdminUsuario(Tenant $tenant, array $overrides = []): Usuario
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

function cvConductor(Tenant $tenant, array $overrides = []): Conductor
{
    tenancy()->initialize($tenant);

    $usuario = Usuario::create(array_merge([
        'nombre' => 'Pedro',
        'apellido_paterno' => 'Ruiz',
        'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'),
        'rol' => 'Conductor',
        'estado' => 'Activo',
    ], $overrides));

    $conductor = Conductor::create([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'ABC123',
        'estado' => 'ACTIVO',
        'disponibilidad' => 'FUERA_DE_SERVICIO',
    ]);

    tenancy()->end();

    return $conductor;
}

function cvVehiculo(Tenant $tenant, array $overrides = []): Vehiculo
{
    tenancy()->initialize($tenant);

    $vehiculo = Vehiculo::create(array_merge([
        'placa' => 'ABC-123',
        'estado' => 'ACTIVO',
    ], $overrides));

    tenancy()->end();

    return $vehiculo;
}

it('rejects listing conductor-vehiculo without a session', function () {
    cvTenant();

    $this->getJson('/api/v1/t/cafe-luna/conductor-vehiculo')->assertUnauthorized();
});

it('rejects conductor-vehiculo access for a non-AdminCliente role', function () {
    $tenant = cvTenant();
    $despachador = cvAdminUsuario($tenant, ['email' => 'x@cafeluna.com', 'rol' => 'Despachador']);

    $this->actingAs($despachador, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductor-vehiculo')
        ->assertForbidden();
});

it('only lists Activo conductores and vehiculos as disponibles', function () {
    $tenant = cvTenant();
    $admin = cvAdminUsuario($tenant);
    $conductorActivo = cvConductor($tenant);

    tenancy()->initialize($tenant);
    $usuarioBloqueado = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Gómez', 'email' => 'ana@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    Conductor::create(['id_usuario' => $usuarioBloqueado->id_usuario, 'numero_licencia' => 'XYZ789', 'estado' => 'BLOQUEADO', 'disponibilidad' => 'FUERA_DE_SERVICIO']);
    tenancy()->end();

    $vehiculoActivo = cvVehiculo($tenant, ['placa' => 'AAA-111']);
    cvVehiculo($tenant, ['placa' => 'BBB-222', 'estado' => 'MANTENIMIENTO']);

    $response = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductor-vehiculo/disponibles')
        ->assertOk();

    expect($response->json('conductores'))->toHaveCount(1);
    expect($response->json('conductores.0.id_conductor'))->toBe($conductorActivo->id_conductor);
    expect($response->json('vehiculos'))->toHaveCount(1);
    expect($response->json('vehiculos.0.id_vehiculo'))->toBe($vehiculoActivo->id_vehiculo);
});

it('assigns a vehiculo to a conductor and logs it', function () {
    $tenant = cvTenant();
    $admin = cvAdminUsuario($tenant);
    $conductor = cvConductor($tenant);
    $vehiculo = cvVehiculo($tenant);

    $response = $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductor-vehiculo', [
            'id_conductor' => $conductor->id_conductor,
            'id_vehiculo' => $vehiculo->id_vehiculo,
            'fecha_inicio' => '2026-01-15',
        ])
        ->assertCreated()
        ->assertJsonPath('activo', true)
        ->assertJsonPath('vehiculo_placa', 'ABC-123');

    tenancy()->initialize($tenant);
    expect(Auditoria::where('tabla_afectada', 'conductor_vehiculo')->where('accion', 'ASIGNACION')->exists())->toBeTrue();
    tenancy()->end();

    expect($response->json('id'))->not->toBeNull();
});

it('closes the previous active assignment of the conductor and of the vehiculo when reassigning', function () {
    $tenant = cvTenant();
    $admin = cvAdminUsuario($tenant);
    $conductor = cvConductor($tenant);
    $vehiculoViejo = cvVehiculo($tenant, ['placa' => 'AAA-111']);
    $vehiculoNuevo = cvVehiculo($tenant, ['placa' => 'BBB-222']);

    tenancy()->initialize($tenant);
    $usuarioOtro = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Gómez', 'email' => 'ana@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $otroConductor = Conductor::create(['id_usuario' => $usuarioOtro->id_usuario, 'numero_licencia' => 'XYZ789', 'estado' => 'ACTIVO', 'disponibilidad' => 'FUERA_DE_SERVICIO']);

    $asignacionConductor = ConductorVehiculo::create(['id_conductor' => $conductor->id_conductor, 'id_vehiculo' => $vehiculoViejo->id_vehiculo, 'fecha_inicio' => '2026-01-01', 'activo' => true]);
    $asignacionVehiculoNuevo = ConductorVehiculo::create(['id_conductor' => $otroConductor->id_conductor, 'id_vehiculo' => $vehiculoNuevo->id_vehiculo, 'fecha_inicio' => '2026-01-01', 'activo' => true]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductor-vehiculo', [
            'id_conductor' => $conductor->id_conductor,
            'id_vehiculo' => $vehiculoNuevo->id_vehiculo,
            'fecha_inicio' => '2026-02-01',
        ])
        ->assertCreated();

    tenancy()->initialize($tenant);
    $asignacionConductor->refresh();
    expect($asignacionConductor->activo)->toBeFalse();
    expect($asignacionConductor->fecha_fin->toDateString())->toBe('2026-02-01');

    $asignacionVehiculoNuevo->refresh();
    expect($asignacionVehiculoNuevo->activo)->toBeFalse();
    expect($asignacionVehiculoNuevo->fecha_fin->toDateString())->toBe('2026-02-01');

    expect(ConductorVehiculo::where('activo', true)->count())->toBe(1);
    tenancy()->end();
});

it('finalizes an active assignment without creating a new row', function () {
    $tenant = cvTenant();
    $admin = cvAdminUsuario($tenant);
    $conductor = cvConductor($tenant);
    $vehiculo = cvVehiculo($tenant);

    tenancy()->initialize($tenant);
    $asignacion = ConductorVehiculo::create(['id_conductor' => $conductor->id_conductor, 'id_vehiculo' => $vehiculo->id_vehiculo, 'fecha_inicio' => '2026-01-01', 'activo' => true]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/conductor-vehiculo/{$asignacion->id}/finalizar")
        ->assertOk()
        ->assertJsonPath('activo', false);

    tenancy()->initialize($tenant);
    expect(ConductorVehiculo::count())->toBe(1);
    expect(Auditoria::where('tabla_afectada', 'conductor_vehiculo')->where('accion', 'FINALIZACION')->exists())->toBeTrue();
    tenancy()->end();
});

it('rejects finalizing an already finalized assignment', function () {
    $tenant = cvTenant();
    $admin = cvAdminUsuario($tenant);
    $conductor = cvConductor($tenant);
    $vehiculo = cvVehiculo($tenant);

    tenancy()->initialize($tenant);
    $asignacion = ConductorVehiculo::create(['id_conductor' => $conductor->id_conductor, 'id_vehiculo' => $vehiculo->id_vehiculo, 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-01-10', 'activo' => false]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/conductor-vehiculo/{$asignacion->id}/finalizar")
        ->assertUnprocessable();
});

it('lists conductor-vehiculo history filtered by search', function () {
    $tenant = cvTenant();
    $admin = cvAdminUsuario($tenant);
    $conductor = cvConductor($tenant);
    $vehiculo = cvVehiculo($tenant);

    tenancy()->initialize($tenant);
    ConductorVehiculo::create(['id_conductor' => $conductor->id_conductor, 'id_vehiculo' => $vehiculo->id_vehiculo, 'fecha_inicio' => '2026-01-01', 'activo' => true]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductor-vehiculo?search=ABC-123')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
