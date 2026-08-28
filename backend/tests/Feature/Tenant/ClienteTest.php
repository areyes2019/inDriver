<?php

use App\Models\Tenant;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\Usuario;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);

    // Defensivo: si un archivo sqlite de un tenant de prueba de OTRO archivo de test quedó sin
    // borrar (Windows a veces tarda en soltar el handle de SQLite tras el purge/GC del test
    // anterior), no debe tumbar el primer test de este archivo con un "la base ya existe".
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

function makeClienteTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function makeClienteAdminUsuario(Tenant $tenant, array $overrides = []): Usuario
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

it('rejects listing clientes without a session', function () {
    makeClienteTenant();

    $this->getJson('/api/v1/t/cafe-luna/clientes')->assertUnauthorized();
});

it('rejects clientes access for a non-AdminCliente role', function () {
    $tenant = makeClienteTenant();
    $despachador = makeClienteAdminUsuario($tenant, [
        'email' => 'pedro@cafeluna.com',
        'rol' => 'Despachador',
    ]);

    tenancy()->initialize($tenant);
    $this->actingAs($despachador, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/clientes')
        ->assertForbidden();
    tenancy()->end();
});

it('creates a cliente Activo by default and logs the action', function () {
    $tenant = makeClienteTenant();
    $admin = makeClienteAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $response = $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/clientes', [
            'nombre' => 'Juan Pérez',
            'telefono' => '5555555555',
            'email' => 'juan@example.com',
        ])
        ->assertCreated()
        ->assertJsonPath('estado', 'Activo');

    expect(Auditoria::where('tabla_afectada', 'clientes')
        ->where('accion', 'ALTA')
        ->exists())->toBeTrue();
    tenancy()->end();

    expect($response->json('id_cliente'))->not->toBeNull();
});

it('rejects creating a cliente without nombre', function () {
    $tenant = makeClienteTenant();
    $admin = makeClienteAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/clientes', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nombre']);
    tenancy()->end();
});

it('lists clientes ordered alphabetically and filters by search', function () {
    $tenant = makeClienteTenant();
    $admin = makeClienteAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    Cliente::create(['nombre' => 'Zoe López', 'estado' => 'Activo']);
    Cliente::create(['nombre' => 'Ana Gómez', 'estado' => 'Activo']);
    tenancy()->end();

    $response = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/clientes')
        ->assertOk();

    expect($response->json('data.0.nombre'))->toBe('Ana Gómez');
    expect($response->json('data.1.nombre'))->toBe('Zoe López');

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/clientes?search=Zoe')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Zoe López');
});

it('updates a cliente and logs the action', function () {
    $tenant = makeClienteTenant();
    $admin = makeClienteAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $cliente = Cliente::create(['nombre' => 'Juan Pérez', 'estado' => 'Activo']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/clientes/{$cliente->id_cliente}", [
            'nombre' => 'Juan Pérez Actualizado',
            'telefono' => '5555555555',
        ])
        ->assertOk()
        ->assertJsonPath('nombre', 'Juan Pérez Actualizado');

    tenancy()->initialize($tenant);
    expect(Auditoria::where('tabla_afectada', 'clientes')
        ->where('accion', 'EDICION')
        ->exists())->toBeTrue();
    tenancy()->end();
});

it('toggles cliente estado between Activo and Inactivo, and logs it', function () {
    $tenant = makeClienteTenant();
    $admin = makeClienteAdminUsuario($tenant);

    tenancy()->initialize($tenant);
    $cliente = Cliente::create(['nombre' => 'Juan Pérez', 'estado' => 'Activo']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/clientes/{$cliente->id_cliente}/estado")
        ->assertOk()
        ->assertJsonPath('estado', 'Inactivo');

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/clientes/{$cliente->id_cliente}/estado")
        ->assertOk()
        ->assertJsonPath('estado', 'Activo');

    tenancy()->initialize($tenant);
    expect(Auditoria::where('tabla_afectada', 'clientes')
        ->where('accion', 'CAMBIO_ESTADO')
        ->count())->toBe(2);
    tenancy()->end();
});

it('throttles clientes listing after 20 attempts per minute', function () {
    $tenant = makeClienteTenant();
    $admin = makeClienteAdminUsuario($tenant);

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($admin, 'usuario')
            ->getJson('/api/v1/t/cafe-luna/clientes')
            ->assertOk();
    }

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/clientes')
        ->assertStatus(429);
});
