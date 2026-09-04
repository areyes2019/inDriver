<?php

use App\Models\Tenant;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Despachador;
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

function conductorTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function conductorAdminUsuario(Tenant $tenant, array $overrides = []): Usuario
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

function conductorHabilitarDespachadores(Tenant $tenant): void
{
    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::USAR_DESPACHADORES, 'Sí');
    tenancy()->end();
}

function conductorDatosVehiculo(array $overrides = []): array
{
    return array_merge([
        'placa' => 'ABC-123',
        'marca' => 'Nissan',
    ], $overrides);
}

function conductorCrearDespachador(Tenant $tenant, array $overrides = []): Despachador
{
    tenancy()->initialize($tenant);

    $usuario = Usuario::create(array_merge([
        'nombre' => 'Pedro',
        'apellido_paterno' => 'Ruiz',
        'email' => 'pedro'.uniqid().'@cafeluna.com',
        'password' => bcrypt('Password123!'),
        'rol' => 'Despachador',
        'estado' => 'Activo',
    ], $overrides));

    $despachador = Despachador::create(['id_usuario' => $usuario->id_usuario, 'estado' => 'Activo']);
    tenancy()->end();

    return $despachador;
}

it('rejects listing conductores without a session', function () {
    conductorTenant();

    $this->getJson('/api/v1/t/cafe-luna/conductores')->assertUnauthorized();
});

it('rejects conductores access for a non-AdminCliente role', function () {
    $tenant = conductorTenant();
    $despachador = conductorAdminUsuario($tenant, [
        'email' => 'pedro@cafeluna.com',
        'rol' => 'Despachador',
    ]);

    $this->actingAs($despachador, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores')
        ->assertForbidden();
});

it('only lists Conductor usuarios that are Activo and have no profile yet as available', function () {
    $tenant = conductorTenant();
    $admin = conductorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $conductorSinPerfil = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductorConPerfil = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Gómez', 'email' => 'ana@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    Conductor::create(['id_usuario' => $conductorConPerfil->id_usuario, 'numero_licencia' => 'ABC123', 'estado' => 'ACTIVO', 'disponibilidad' => 'FUERA_DE_SERVICIO']);
    Usuario::create([
        'nombre' => 'Suspendido', 'apellido_paterno' => 'Ruiz', 'email' => 'suspendido@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Suspendido',
    ]);
    tenancy()->end();

    $response = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores/usuarios-disponibles')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id_usuario'))->toBe($conductorSinPerfil->id_usuario);
});

it('creates a conductor profile for an eligible usuario', function () {
    $tenant = conductorTenant();
    $admin = conductorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    tenancy()->end();

    $response = $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductores', [
            'id_usuario' => $usuarioPedro->id_usuario,
            'numero_licencia' => 'ABC123',
            ...conductorDatosVehiculo(),
        ])
        ->assertCreated()
        ->assertJsonPath('estado', 'ACTIVO')
        ->assertJsonPath('disponibilidad', 'FUERA_DE_SERVICIO')
        ->assertJsonPath('numero_licencia', 'ABC123')
        ->assertJsonPath('vehiculo.placa', 'ABC-123');

    tenancy()->initialize($tenant);
    expect(Auditoria::where('tabla_afectada', 'conductores')->where('accion', 'ALTA')->exists())->toBeTrue();
    tenancy()->end();

    expect($response->json('id_conductor'))->not->toBeNull();
});

it('rejects creating a conductor profile without the vehicle fields', function () {
    $tenant = conductorTenant();
    $admin = conductorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductores', [
            'id_usuario' => $usuarioPedro->id_usuario,
            'numero_licencia' => 'ABC123',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['placa', 'marca']);
});

it('rejects creating a conductor profile with a placa already used by another vehicle', function () {
    $tenant = conductorTenant();
    $admin = conductorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $usuarioAna = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Gómez', 'email' => 'ana@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductorPedro = Conductor::create(['id_usuario' => $usuarioPedro->id_usuario, 'numero_licencia' => 'ABC123', 'estado' => 'ACTIVO', 'disponibilidad' => 'FUERA_DE_SERVICIO']);
    Vehiculo::create([...conductorDatosVehiculo(), 'id_conductor' => $conductorPedro->id_conductor]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductores', [
            'id_usuario' => $usuarioAna->id_usuario,
            'numero_licencia' => 'XYZ789',
            ...conductorDatosVehiculo(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['placa']);
});

it('rejects creating a conductor profile for a usuario without rol Conductor', function () {
    $tenant = conductorTenant();
    $admin = conductorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Despachador', 'estado' => 'Activo',
    ]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductores', [
            'id_usuario' => $usuarioPedro->id_usuario,
            'numero_licencia' => 'ABC123',
            ...conductorDatosVehiculo(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id_usuario']);
});

it('rejects creating a conductor profile for a usuario that already has one', function () {
    $tenant = conductorTenant();
    $admin = conductorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    Conductor::create(['id_usuario' => $usuarioPedro->id_usuario, 'numero_licencia' => 'ABC123', 'estado' => 'ACTIVO', 'disponibilidad' => 'FUERA_DE_SERVICIO']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductores', [
            'id_usuario' => $usuarioPedro->id_usuario,
            'numero_licencia' => 'XYZ789',
            ...conductorDatosVehiculo(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id_usuario']);
});

it('lists conductores with their usuario data and filters by search', function () {
    $tenant = conductorTenant();
    $admin = conductorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductorPedro = Conductor::create(['id_usuario' => $usuarioPedro->id_usuario, 'numero_licencia' => 'ABC123', 'estado' => 'ACTIVO', 'disponibilidad' => 'FUERA_DE_SERVICIO']);
    Vehiculo::create([...conductorDatosVehiculo(), 'id_conductor' => $conductorPedro->id_conductor]);

    $usuarioAna = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Gómez', 'email' => 'ana@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    Conductor::create(['id_usuario' => $usuarioAna->id_usuario, 'numero_licencia' => 'XYZ789', 'estado' => 'ACTIVO', 'disponibilidad' => 'FUERA_DE_SERVICIO']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores?search=ABC123')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Pedro')
        ->assertJsonPath('data.0.vehiculo.placa', 'ABC-123');
});

it('includes saldo_viajes in the listing, computed without an extra query per conductor', function () {
    $tenant = conductorTenant();
    $admin = conductorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductor = Conductor::create(['id_usuario' => $usuarioPedro->id_usuario, 'numero_licencia' => 'ABC123', 'estado' => 'ACTIVO', 'disponibilidad' => 'FUERA_DE_SERVICIO']);

    VentaViajeConductor::create([
        'id_conductor' => $conductor->id_conductor,
        'cantidad_viajes' => 5,
        'monto_pagado' => 500,
        'id_usuario' => $admin->id_usuario,
        'fecha_venta' => now(),
    ]);

    Pedido::create([
        'numero_pedido' => 'PED-000001',
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100',
        'direccion_entrega' => 'Av. Insurgentes 200',
        'fecha_servicio' => now()->addDay()->toDateString(),
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'id_conductor' => $conductor->id_conductor,
        'prepago_descontado' => true,
        'estado' => 'ENTREGADO',
    ]);
    tenancy()->end();

    $response = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores')
        ->assertOk();

    expect($response->json('data.0.saldo_viajes'))->toBe(4);
});

it('shows a single conductor with its usuario data', function () {
    $tenant = conductorTenant();
    $admin = conductorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductor = Conductor::create(['id_usuario' => $usuarioPedro->id_usuario, 'numero_licencia' => 'ABC123', 'estado' => 'ACTIVO', 'disponibilidad' => 'FUERA_DE_SERVICIO']);
    Vehiculo::create([...conductorDatosVehiculo(), 'id_conductor' => $conductor->id_conductor]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->getJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}")
        ->assertOk()
        ->assertJsonPath('numero_licencia', 'ABC123')
        ->assertJsonPath('email', 'pedro@cafeluna.com')
        ->assertJsonPath('vehiculo.placa', 'ABC-123');
});

it('updates a conductor profile including estado and disponibilidad, and logs it', function () {
    $tenant = conductorTenant();
    $admin = conductorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductor = Conductor::create(['id_usuario' => $usuarioPedro->id_usuario, 'numero_licencia' => 'ABC123', 'estado' => 'ACTIVO', 'disponibilidad' => 'FUERA_DE_SERVICIO']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}", [
            'numero_licencia' => 'ABC123',
            'estado' => 'BLOQUEADO',
            'disponibilidad' => 'DISPONIBLE',
            ...conductorDatosVehiculo(),
        ])
        ->assertOk()
        ->assertJsonPath('estado', 'BLOQUEADO')
        ->assertJsonPath('disponibilidad', 'DISPONIBLE')
        ->assertJsonPath('vehiculo.placa', 'ABC-123');

    tenancy()->initialize($tenant);
    expect(Auditoria::where('tabla_afectada', 'conductores')->where('accion', 'EDICION')->exists())->toBeTrue();
    expect(Vehiculo::where('id_conductor', $conductor->id_conductor)->where('placa', 'ABC-123')->exists())->toBeTrue();
    tenancy()->end();
});

it('changes the vehicle of a conductor that did not have one yet', function () {
    $tenant = conductorTenant();
    $admin = conductorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductor = Conductor::create(['id_usuario' => $usuarioPedro->id_usuario, 'numero_licencia' => 'ABC123', 'estado' => 'ACTIVO', 'disponibilidad' => 'FUERA_DE_SERVICIO']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}", [
            'numero_licencia' => 'ABC123',
            'estado' => 'ACTIVO',
            'disponibilidad' => 'FUERA_DE_SERVICIO',
            ...conductorDatosVehiculo(['placa' => 'NEW-001']),
        ])
        ->assertOk()
        ->assertJsonPath('vehiculo.placa', 'NEW-001');

    tenancy()->initialize($tenant);
    expect(Vehiculo::where('id_conductor', $conductor->id_conductor)->count())->toBe(1);
    tenancy()->end();
});

it('rejects updating a conductor with an estado outside the enum', function () {
    $tenant = conductorTenant();
    $admin = conductorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductor = Conductor::create(['id_usuario' => $usuarioPedro->id_usuario, 'numero_licencia' => 'ABC123', 'estado' => 'ACTIVO', 'disponibilidad' => 'FUERA_DE_SERVICIO']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}", [
            'numero_licencia' => 'ABC123',
            'estado' => 'NoExiste',
            'disponibilidad' => 'DISPONIBLE',
            ...conductorDatosVehiculo(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['estado']);
});

it('does not require id_despachador when the tenant does not use despachadores', function () {
    $tenant = conductorTenant();
    $admin = conductorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    tenancy()->end();

    $response = $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductores', [
            'id_usuario' => $usuarioPedro->id_usuario,
            'numero_licencia' => 'ABC123',
            ...conductorDatosVehiculo(),
        ])
        ->assertCreated();

    expect($response->json('id_despachador'))->toBeNull();
});

it('auto-assigns a new conductor to the sole despachador Activo when the tenant uses despachadores', function () {
    $tenant = conductorTenant();
    conductorHabilitarDespachadores($tenant);
    $admin = conductorAdminUsuario($tenant);
    $despachador = conductorCrearDespachador($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Gómez', 'email' => 'ana@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    tenancy()->end();

    $response = $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductores', [
            'id_usuario' => $usuarioPedro->id_usuario,
            'numero_licencia' => 'ABC123',
            ...conductorDatosVehiculo(),
        ])
        ->assertCreated();

    expect($response->json('id_despachador'))->toBe($despachador->id_despachador);
});

it('requires id_despachador to create a conductor when there are 2+ despachadores Activo', function () {
    $tenant = conductorTenant();
    conductorHabilitarDespachadores($tenant);
    $admin = conductorAdminUsuario($tenant);
    conductorCrearDespachador($tenant, ['email' => 'd1@cafeluna.com']);
    conductorCrearDespachador($tenant, ['email' => 'd2@cafeluna.com']);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Gómez', 'email' => 'ana@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductores', [
            'id_usuario' => $usuarioPedro->id_usuario,
            'numero_licencia' => 'ABC123',
            ...conductorDatosVehiculo(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id_despachador']);
});

it('creates a conductor with the chosen id_despachador when there are 2+ despachadores Activo', function () {
    $tenant = conductorTenant();
    conductorHabilitarDespachadores($tenant);
    $admin = conductorAdminUsuario($tenant);
    conductorCrearDespachador($tenant, ['email' => 'd1@cafeluna.com']);
    $despachadorDos = conductorCrearDespachador($tenant, ['email' => 'd2@cafeluna.com']);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Gómez', 'email' => 'ana@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    tenancy()->end();

    $response = $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductores', [
            'id_usuario' => $usuarioPedro->id_usuario,
            'numero_licencia' => 'ABC123',
            'id_despachador' => $despachadorDos->id_despachador,
            ...conductorDatosVehiculo(),
        ])
        ->assertCreated();

    expect($response->json('id_despachador'))->toBe($despachadorDos->id_despachador);
});

it('rejects a conductor without despachador when updated to ACTIVO with 2+ despachadores Activo', function () {
    $tenant = conductorTenant();
    conductorHabilitarDespachadores($tenant);
    $admin = conductorAdminUsuario($tenant);
    conductorCrearDespachador($tenant, ['email' => 'd1@cafeluna.com']);
    conductorCrearDespachador($tenant, ['email' => 'd2@cafeluna.com']);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductor = Conductor::create(['id_usuario' => $usuarioPedro->id_usuario, 'numero_licencia' => 'ABC123', 'estado' => 'INACTIVO', 'disponibilidad' => 'FUERA_DE_SERVICIO']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}", [
            'numero_licencia' => 'ABC123',
            'estado' => 'ACTIVO',
            'disponibilidad' => 'DISPONIBLE',
            ...conductorDatosVehiculo(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id_despachador']);
});
