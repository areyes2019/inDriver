<?php

use App\Models\AdminCentral;
use App\Models\LogCentral;
use App\Models\Tenant;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\CompraPaquete;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\VentaViajeConductor;
use App\Notifications\CredencialesConductor;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

/**
 * ADMIN_CENTRAL da de alta conductores de un tenant y les vende viajes prepagados desde el detalle
 * del tenant, con las mismas reglas que el Panel y el rastro en la bitácora central.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

function conductorCentralAdmin(): AdminCentral
{
    return AdminCentral::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Pérez', 'email' => 'ana@example.com',
        'password' => bcrypt('Password123!'), 'estado' => 'Activo',
    ]);
}

/**
 * Tenant con su AdminCliente, el costo del viaje prepagado y 50 viajes de saldo.
 */
function conductorCentralTenant(): Tenant
{
    $tenant = Tenant::create([
        'nombre_comercial' => 'Café Luna', 'razon_social' => 'Café Luna SA de CV', 'slug' => 'cafe-luna',
    ]);

    tenancy()->initialize($tenant);
    Usuario::create([
        'nombre' => 'Laura', 'apellido_paterno' => 'Torres', 'email' => 'laura@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'AdminCliente', 'estado' => 'Activo',
    ]);
    ConfiguracionTenant::establecer(ConfiguracionTenant::COSTO_VIAJE_PREPAGO, '100');
    CompraPaquete::create([
        'codigo_paquete' => 'P50', 'cantidad_paquetes' => 1, 'cantidad_viajes' => 50,
        'precio_unitario' => 0, 'importe_total' => 0, 'estado' => 'Activo', 'fecha_compra' => now(),
    ]);
    tenancy()->end();

    return $tenant;
}

/**
 * @return array<string, mixed>
 */
function datosConductorCentral(array $overrides = []): array
{
    return array_merge([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com',
        'telefono' => '4611234567', 'numero_licencia' => 'LIC-001', 'placa' => 'ABC-123', 'marca' => 'Italika',
    ], $overrides);
}

it('rejects the conductor endpoints without an admin session', function () {
    $tenant = conductorCentralTenant();

    $this->getJson("/api/v1/admin/tenants/{$tenant->id_tenant}/conductores")->assertUnauthorized();
    $this->postJson("/api/v1/admin/tenants/{$tenant->id_tenant}/conductores", datosConductorCentral())->assertUnauthorized();
});

it('creates a conductor with its user and vehicle, emails the credentials and logs it centrally', function () {
    Notification::fake();
    $admin = conductorCentralAdmin();
    $tenant = conductorCentralTenant();

    $this->actingAs($admin, 'admin')
        ->postJson("/api/v1/admin/tenants/{$tenant->id_tenant}/conductores", datosConductorCentral())
        ->assertCreated()
        ->assertJsonPath('email', 'pedro@cafeluna.com')
        ->assertJsonPath('vehiculo.placa', 'ABC-123');

    tenancy()->initialize($tenant);
    $usuario = Usuario::where('email', 'pedro@cafeluna.com')->firstOrFail();
    expect($usuario->rol)->toBe('Conductor')
        ->and(Conductor::where('id_usuario', $usuario->id_usuario)->first()?->estado)->toBe('ACTIVO')
        ->and(Auditoria::count())->toBe(0);
    tenancy()->end();

    Notification::assertSentOnDemand(CredencialesConductor::class);
    expect(LogCentral::where('tipo', 'CONDUCTOR')->where('accion', 'ALTA')->where('id_tenant', $tenant->id_tenant)->exists())->toBeTrue();
});

it('rejects a duplicated email or plate inside the tenant', function () {
    Notification::fake();
    $admin = conductorCentralAdmin();
    $tenant = conductorCentralTenant();

    $this->actingAs($admin, 'admin')
        ->postJson("/api/v1/admin/tenants/{$tenant->id_tenant}/conductores", datosConductorCentral())
        ->assertCreated();

    $this->actingAs($admin, 'admin')
        ->postJson("/api/v1/admin/tenants/{$tenant->id_tenant}/conductores", datosConductorCentral())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'placa']);
});

it('sells prepaid trips to a conductor under the tenant AdminCliente and lists the balance', function () {
    Notification::fake();
    $admin = conductorCentralAdmin();
    $tenant = conductorCentralTenant();

    $idConductor = $this->actingAs($admin, 'admin')
        ->postJson("/api/v1/admin/tenants/{$tenant->id_tenant}/conductores", datosConductorCentral())
        ->json('id_conductor');

    $this->actingAs($admin, 'admin')
        ->postJson("/api/v1/admin/tenants/{$tenant->id_tenant}/conductores/{$idConductor}/vender-viajes", [
            'monto_pagado' => 500,
        ])
        ->assertCreated()
        ->assertJsonPath('cantidad_viajes', 5)
        ->assertJsonPath('saldo_conductor', 5);

    tenancy()->initialize($tenant);
    $venta = VentaViajeConductor::firstOrFail();
    expect($venta->id_usuario)->toBe(Usuario::where('rol', 'AdminCliente')->value('id_usuario'));
    tenancy()->end();

    expect(LogCentral::where('accion', 'VENTA_VIAJES')->exists())->toBeTrue();

    $this->actingAs($admin, 'admin')
        ->getJson("/api/v1/admin/tenants/{$tenant->id_tenant}/conductores")
        ->assertOk()
        ->assertJsonPath('data.0.saldo_viajes', 5)
        ->assertJsonPath('meta.costo_viaje_prepago', 100)
        ->assertJsonPath('meta.saldo_viajes_tenant', 50);
});

it('caps the sale at the tenant balance, like the panel does', function () {
    Notification::fake();
    $admin = conductorCentralAdmin();
    $tenant = conductorCentralTenant();

    $idConductor = $this->actingAs($admin, 'admin')
        ->postJson("/api/v1/admin/tenants/{$tenant->id_tenant}/conductores", datosConductorCentral())
        ->json('id_conductor');

    $this->actingAs($admin, 'admin')
        ->postJson("/api/v1/admin/tenants/{$tenant->id_tenant}/conductores/{$idConductor}/vender-viajes", [
            'monto_pagado' => 10000,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['monto_pagado']);

    expect(LogCentral::where('accion', 'VENTA_VIAJES')->exists())->toBeFalse();
});
