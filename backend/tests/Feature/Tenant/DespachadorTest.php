<?php

use App\Models\Tenant;
use App\Models\Tenant\Auditoria;
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

function despachadorTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function despachadorAdminUsuario(Tenant $tenant, array $overrides = []): Usuario
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

it('rejects listing despachadores without a session', function () {
    despachadorTenant();

    $this->getJson('/api/v1/t/cafe-luna/despachadores')->assertUnauthorized();
});

it('rejects despachadores access for a non-AdminCliente role', function () {
    $tenant = despachadorTenant();
    $despachador = despachadorAdminUsuario($tenant, [
        'email' => 'pedro@cafeluna.com',
        'rol' => 'Despachador',
    ]);

    $this->actingAs($despachador, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/despachadores')
        ->assertForbidden();
});

it('lists despachadores with their usuario data and filters by search', function () {
    $tenant = despachadorTenant();
    $admin = despachadorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Despachador', 'estado' => 'Activo',
    ]);
    Despachador::create(['id_usuario' => $usuarioPedro->id_usuario, 'estado' => 'Activo']);

    $usuarioAna = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Gómez', 'email' => 'ana@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Despachador', 'estado' => 'Activo',
    ]);
    Despachador::create(['id_usuario' => $usuarioAna->id_usuario, 'estado' => 'Activo']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/despachadores?search=Pedro')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Pedro')
        ->assertJsonPath('data.0.email', 'pedro@cafeluna.com');
});

it('changes despachador estado to a valid value and logs it', function () {
    $tenant = despachadorTenant();
    $admin = despachadorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Despachador', 'estado' => 'Activo',
    ]);
    $despachador = Despachador::create(['id_usuario' => $usuarioPedro->id_usuario, 'estado' => 'Activo']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/despachadores/{$despachador->id_despachador}/estado", [
            'estado' => 'Suspendido',
        ])
        ->assertOk()
        ->assertJsonPath('estado', 'Suspendido');

    tenancy()->initialize($tenant);
    expect(Auditoria::where('tabla_afectada', 'despachadores')
        ->where('accion', 'CAMBIO_ESTADO')
        ->exists())->toBeTrue();
    tenancy()->end();
});

it('rejects changing despachador estado to a value outside the enum', function () {
    $tenant = despachadorTenant();
    $admin = despachadorAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $usuarioPedro = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Despachador', 'estado' => 'Activo',
    ]);
    $despachador = Despachador::create(['id_usuario' => $usuarioPedro->id_usuario, 'estado' => 'Activo']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/despachadores/{$despachador->id_despachador}/estado", [
            'estado' => 'NoExiste',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['estado']);
});
