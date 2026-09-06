<?php

use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Usuario;
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

function pruebaTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function pruebaAdmin(Tenant $tenant): Usuario
{
    tenancy()->initialize($tenant);
    $admin = Usuario::create([
        'nombre' => 'Laura', 'apellido_paterno' => 'Torres', 'email' => 'laura@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'AdminCliente', 'estado' => 'Activo',
    ]);
    tenancy()->end();

    return $admin;
}

it('toggles modo_prueba and reflects it on /me', function () {
    $tenant = pruebaTenant();
    $admin = pruebaAdmin($tenant);

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/me')
        ->assertOk()
        ->assertJsonPath('modo_prueba', false);

    $this->actingAs($admin, 'usuario')
        ->patchJson('/api/v1/t/cafe-luna/configuracion/modo-prueba', ['modo_prueba' => 'Sí'])
        ->assertOk()
        ->assertJsonPath('modo_prueba', true);

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/me')
        ->assertOk()
        ->assertJsonPath('modo_prueba', true);
});

it('creates a test conductor under Prepago with unlimited viajes and a usable conductor token', function () {
    $tenant = pruebaTenant();
    $admin = pruebaAdmin($tenant);

    $response = $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductores-prueba', ['nombre' => 'Bot de pruebas'])
        ->assertCreated();

    expect($response->json('conductor.nombre'))->toContain('Bot de pruebas');
    expect($response->json('token'))->not->toBeEmpty();

    $token = $response->json('token');

    tenancy()->initialize($tenant);
    $conductor = Conductor::where('es_prueba', true)->first();
    expect($conductor)->not->toBeNull();
    expect(VentaViajeConductor::where('id_conductor', $conductor->id_conductor)->sum('cantidad_viajes'))->toBe(999999);
    tenancy()->end();

    // El token debe poder conectarse de inmediato (spec tenant/019, RN-02: nunca lo bloquea el saldo).
    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/estado', ['estado' => 'ONLINE'])
        ->assertOk()
        ->assertJsonPath('estado', 'ONLINE');
});

it('credits money balance instead of viajes under Comision modalidad', function () {
    $tenant = pruebaTenant();
    $admin = pruebaAdmin($tenant);

    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, 'Comision');
    tenancy()->end();

    $response = $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductores-prueba', [])
        ->assertCreated();

    $token = $response->json('token');

    $this->withToken($token)
        ->getJson('/api/v1/t/cafe-luna/conductor/saldo')
        ->assertOk()
        ->assertJsonPath('saldo', 999999);
});

it('excludes test conductores from the real fleet directory', function () {
    $tenant = pruebaTenant();
    $admin = pruebaAdmin($tenant);

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductores-prueba', [])
        ->assertCreated();

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('includes an online test conductor in the live map listing', function () {
    $tenant = pruebaTenant();
    $admin = pruebaAdmin($tenant);

    $token = $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductores-prueba', [])
        ->assertCreated()
        ->json('token');

    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/estado', ['estado' => 'ONLINE'])
        ->assertOk();

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores/activos')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.es_prueba', true);
});

it('deletes a test conductor but refuses to delete a real one through the same endpoint', function () {
    $tenant = pruebaTenant();
    $admin = pruebaAdmin($tenant);

    $idPrueba = $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/conductores-prueba', [])
        ->assertCreated()
        ->json('conductor.id_conductor');

    $this->actingAs($admin, 'usuario')
        ->deleteJson("/api/v1/t/cafe-luna/conductores-prueba/{$idPrueba}")
        ->assertNoContent();

    tenancy()->initialize($tenant);
    $usuarioReal = Usuario::create([
        'nombre' => 'Beto', 'apellido_paterno' => 'Salgado', 'email' => 'beto@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductorReal = Conductor::create([
        'id_usuario' => $usuarioReal->id_usuario, 'numero_licencia' => 'LIC-REAL',
        'estado' => 'ACTIVO', 'disponibilidad' => 'FUERA_DE_SERVICIO',
    ]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->deleteJson("/api/v1/t/cafe-luna/conductores-prueba/{$conductorReal->id_conductor}")
        ->assertForbidden();
});
