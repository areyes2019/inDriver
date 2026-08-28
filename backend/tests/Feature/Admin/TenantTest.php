<?php

use App\Models\AdminCentral;
use App\Models\LogCentral;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);
});

afterEach(function () {
    // RefreshDatabase solo controla la base central (sqlite en memoria); el archivo sqlite físico
    // que provisiona stancl/tenancy para cada tenant de prueba sí queda en disco y hay que borrarlo
    // a mano, o el siguiente test que cree el tenant #1 choca con un archivo "ya existente".
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

function makeTenantAdmin(): AdminCentral
{
    return AdminCentral::create([
        'nombre' => 'Ana',
        'apellido_paterno' => 'Pérez',
        'email' => 'ana@example.com',
        'password' => bcrypt('Password123!'),
        'estado' => 'Activo',
    ]);
}

it('rejects tenant creation without an admin session', function () {
    $this->postJson('/api/v1/admin/tenants', [
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
    ])->assertUnauthorized();
});

it('rejects tenant creation when nombre_comercial or razon_social is missing', function () {
    $admin = makeTenantAdmin();

    $this->actingAs($admin, 'admin')
        ->postJson('/api/v1/admin/tenants', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nombre_comercial', 'razon_social']);
});

it('rejects a duplicate rfc or email without creating the tenant', function () {
    $admin = makeTenantAdmin();
    Tenant::create([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'rfc' => 'CLU010101AAA',
        'email' => 'contacto@cafeluna.com',
    ]);

    $this->actingAs($admin, 'admin')
        ->postJson('/api/v1/admin/tenants', [
            'nombre_comercial' => 'Otro negocio',
            'razon_social' => 'Otro negocio SA de CV',
            'rfc' => 'CLU010101AAA',
            'email' => 'otro@example.com',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rfc']);

    expect(Tenant::count())->toBe(1);
});

it('creates a tenant, provisions its database, and logs the action', function () {
    $admin = makeTenantAdmin();

    $response = $this->actingAs($admin, 'admin')
        ->postJson('/api/v1/admin/tenants', [
            'nombre_comercial' => 'Café Luna',
            'razon_social' => 'Café Luna SA de CV',
            'rfc' => 'CLU010101AAA',
            'telefono' => '5555555555',
            'email' => 'contacto@cafeluna.com',
        ])
        ->assertCreated()
        ->assertJsonMissingPath('database_password');

    $tenant = Tenant::find($response->json('id_tenant'));

    expect($tenant)->not->toBeNull();
    expect($tenant->estado)->toBe('Activo');
    expect($tenant->modo_estado)->toBe('AUTOMATICO');

    expect(LogCentral::where('id_tenant', $tenant->id_tenant)
        ->where('id_admin', $admin->id_admin)
        ->where('tipo', 'TENANT')
        ->where('accion', 'ALTA')
        ->exists())->toBeTrue();
});

it('throttles tenant creation after 20 attempts per minute', function () {
    $admin = makeTenantAdmin();

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($admin, 'admin')
            ->postJson('/api/v1/admin/tenants', [])
            ->assertUnprocessable();
    }

    $this->actingAs($admin, 'admin')
        ->postJson('/api/v1/admin/tenants', [])
        ->assertStatus(429);
});

it('rejects listing tenants without an admin session', function () {
    $this->getJson('/api/v1/admin/tenants')->assertUnauthorized();
});

it('lists tenants ordered by newest first and filters by nombre_comercial', function () {
    $admin = makeTenantAdmin();
    $viejo = Tenant::create(['nombre_comercial' => 'Café Luna', 'razon_social' => 'Café Luna SA de CV']);
    $nuevo = Tenant::create(['nombre_comercial' => 'Tacos El Sol', 'razon_social' => 'Tacos El Sol SA de CV']);

    $response = $this->actingAs($admin, 'admin')
        ->getJson('/api/v1/admin/tenants')
        ->assertOk()
        ->assertJsonMissingPath('data.0.database_password');

    expect($response->json('data.0.id_tenant'))->toBe($nuevo->id_tenant);
    expect($response->json('data.1.id_tenant'))->toBe($viejo->id_tenant);

    $this->actingAs($admin, 'admin')
        ->getJson('/api/v1/admin/tenants?search=Luna')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id_tenant', $viejo->id_tenant);
});

it('shows a tenant detail and 404s for a missing one', function () {
    $admin = makeTenantAdmin();
    $tenant = Tenant::create(['nombre_comercial' => 'Café Luna', 'razon_social' => 'Café Luna SA de CV']);

    $this->actingAs($admin, 'admin')
        ->getJson("/api/v1/admin/tenants/{$tenant->id_tenant}")
        ->assertOk()
        ->assertJsonPath('nombre_comercial', 'Café Luna');

    $this->actingAs($admin, 'admin')
        ->getJson('/api/v1/admin/tenants/999999')
        ->assertNotFound();
});

it('updates a tenant, allows keeping its own rfc/email, and logs the action', function () {
    $admin = makeTenantAdmin();
    $tenant = Tenant::create([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'rfc' => 'CLU010101AAA',
        'email' => 'contacto@cafeluna.com',
    ]);

    $this->actingAs($admin, 'admin')
        ->putJson("/api/v1/admin/tenants/{$tenant->id_tenant}", [
            'nombre_comercial' => 'Café Luna Nuevo',
            'razon_social' => 'Café Luna SA de CV',
            'rfc' => 'CLU010101AAA',
            'email' => 'contacto@cafeluna.com',
        ])
        ->assertOk()
        ->assertJsonPath('nombre_comercial', 'Café Luna Nuevo');

    expect(LogCentral::where('id_tenant', $tenant->id_tenant)
        ->where('accion', 'EDICION')
        ->exists())->toBeTrue();
});

it('rejects updating a tenant with a rfc or email already used by another tenant', function () {
    $admin = makeTenantAdmin();
    Tenant::create([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'rfc' => 'CLU010101AAA',
    ]);
    $tenant = Tenant::create(['nombre_comercial' => 'Tacos El Sol', 'razon_social' => 'Tacos El Sol SA de CV']);

    $this->actingAs($admin, 'admin')
        ->putJson("/api/v1/admin/tenants/{$tenant->id_tenant}", [
            'nombre_comercial' => 'Tacos El Sol',
            'razon_social' => 'Tacos El Sol SA de CV',
            'rfc' => 'CLU010101AAA',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rfc']);
});

it('toggles a tenant estado between Activo and Suspendido, sets modo_estado to MANUAL, and logs it', function () {
    $admin = makeTenantAdmin();
    $tenant = Tenant::create(['nombre_comercial' => 'Café Luna', 'razon_social' => 'Café Luna SA de CV']);

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/v1/admin/tenants/{$tenant->id_tenant}/estado")
        ->assertOk()
        ->assertJsonPath('estado', 'Suspendido')
        ->assertJsonPath('modo_estado', 'MANUAL');

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/v1/admin/tenants/{$tenant->id_tenant}/estado")
        ->assertOk()
        ->assertJsonPath('estado', 'Activo');

    expect(LogCentral::where('id_tenant', $tenant->id_tenant)
        ->where('accion', 'CAMBIO_ESTADO')
        ->count())->toBe(2);
});

it('rejects toggling estado for an Inactivo tenant', function () {
    $admin = makeTenantAdmin();
    $tenant = Tenant::create([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'estado' => 'Inactivo',
    ]);

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/v1/admin/tenants/{$tenant->id_tenant}/estado")
        ->assertUnprocessable();
});
