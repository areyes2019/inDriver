<?php

use App\Models\AdminCentral;
use App\Models\LogCentral;
use App\Models\Tenant;
use App\Models\Tenant\Usuario;
use App\Notifications\CredencialesAdminCliente;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

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

it('rejects tenant creation when email, nombre or apellido_paterno is missing', function () {
    $admin = makeTenantAdmin();

    $this->actingAs($admin, 'admin')
        ->postJson('/api/v1/admin/tenants', [
            'nombre_comercial' => 'Café Luna',
            'razon_social' => 'Café Luna SA de CV',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'nombre', 'apellido_paterno']);
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
            'nombre' => 'Luis',
            'apellido_paterno' => 'Gómez',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rfc']);

    expect(Tenant::count())->toBe(1);
});

it('creates a tenant, provisions its database, logs the action, and creates the AdminCliente inicial', function () {
    Notification::fake();

    $admin = makeTenantAdmin();

    $response = $this->actingAs($admin, 'admin')
        ->postJson('/api/v1/admin/tenants', [
            'nombre_comercial' => 'Café Luna',
            'razon_social' => 'Café Luna SA de CV',
            'rfc' => 'CLU010101AAA',
            'telefono' => '5555555555',
            'email' => 'contacto@cafeluna.com',
            'nombre' => 'Laura',
            'apellido_paterno' => 'Torres',
            'apellido_materno' => 'Díaz',
        ])
        ->assertCreated()
        ->assertJsonMissingPath('database_password');

    $tenant = Tenant::find($response->json('id_tenant'));

    expect($tenant)->not->toBeNull();
    expect($tenant->estado)->toBe('Activo');
    expect($tenant->modo_estado)->toBe('AUTOMATICO');

    // nombre/apellido del AdminCliente no se persisten en el tenant (base central).
    $rawData = DB::table('tenants')
        ->where('id_tenant', $tenant->id_tenant)
        ->value('data');
    expect(json_decode($rawData ?? '[]', true) ?? [])->not->toHaveKey('nombre');

    expect(LogCentral::where('id_tenant', $tenant->id_tenant)
        ->where('id_admin', $admin->id_admin)
        ->where('tipo', 'TENANT')
        ->where('accion', 'ALTA')
        ->exists())->toBeTrue();

    tenancy()->initialize($tenant);
    $usuario = Usuario::where('email', 'contacto@cafeluna.com')->first();
    tenancy()->end();

    expect($usuario)->not->toBeNull();
    expect($usuario->rol)->toBe('AdminCliente');
    expect($usuario->estado)->toBe('Activo');
    expect($usuario->nombre)->toBe('Laura');
    expect($usuario->apellido_paterno)->toBe('Torres');
    expect($usuario->password)->toStartWith('$2y$');

    Notification::assertSentOnDemand(
        CredencialesAdminCliente::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'contacto@cafeluna.com',
    );
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

it('rejects tenant deletion without an admin session', function () {
    $tenant = Tenant::create(['nombre_comercial' => 'Café Luna', 'razon_social' => 'Café Luna SA de CV']);

    $this->deleteJson("/api/v1/admin/tenants/{$tenant->id_tenant}", ['password' => 'Password123!'])
        ->assertUnauthorized();
});

it('rejects tenant deletion with a missing or incorrect password, without deleting the tenant', function () {
    $admin = makeTenantAdmin();
    $tenant = Tenant::create(['nombre_comercial' => 'Café Luna', 'razon_social' => 'Café Luna SA de CV']);

    $this->actingAs($admin, 'admin')
        ->deleteJson("/api/v1/admin/tenants/{$tenant->id_tenant}", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    $this->actingAs($admin, 'admin')
        ->deleteJson("/api/v1/admin/tenants/{$tenant->id_tenant}", ['password' => 'wrong-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    expect(Tenant::find($tenant->id_tenant))->not->toBeNull();
});

it('deletes a tenant, its database, and logs the action when the password is correct', function () {
    $admin = makeTenantAdmin();
    $tenant = Tenant::create(['nombre_comercial' => 'Café Luna', 'razon_social' => 'Café Luna SA de CV']);
    $tenantId = $tenant->id_tenant;

    $this->actingAs($admin, 'admin')
        ->deleteJson("/api/v1/admin/tenants/{$tenantId}", ['password' => 'Password123!'])
        ->assertOk();

    expect(Tenant::find($tenantId))->toBeNull();
    expect(LogCentral::where('id_admin', $admin->id_admin)
        ->where('tipo', 'TENANT')
        ->where('accion', 'BAJA')
        ->exists())->toBeTrue();
});

it('throttles tenant deletion after 20 attempts per minute', function () {
    $admin = makeTenantAdmin();
    $tenant = Tenant::create(['nombre_comercial' => 'Café Luna', 'razon_social' => 'Café Luna SA de CV']);

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($admin, 'admin')
            ->deleteJson("/api/v1/admin/tenants/{$tenant->id_tenant}", [])
            ->assertUnprocessable();
    }

    $this->actingAs($admin, 'admin')
        ->deleteJson("/api/v1/admin/tenants/{$tenant->id_tenant}", [])
        ->assertStatus(429);
});
