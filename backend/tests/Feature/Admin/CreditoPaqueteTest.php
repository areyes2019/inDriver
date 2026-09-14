<?php

use App\Events\Tenant\SaldoTenantCambiado;
use App\Models\AdminCentral;
use App\Models\PaqueteViaje;
use App\Models\Tenant;
use App\Models\Tenant\CompraPaquete;
use App\Support\SaldoViajesTenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

/**
 * spec tenant/015 (acreditación de paquetes) + tenant/027 §12: acreditar desde ADMIN_CENTRAL sube
 * el saldo del tenant y se lo avisa a su Panel sin que nadie recargue.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);
});

afterEach(function () {
    tenancy()->end();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

it('credits the tenant and tells its panel the resulting balance', function () {
    $admin = AdminCentral::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Pérez', 'email' => 'ana@example.com',
        'password' => bcrypt('Password123!'), 'estado' => 'Activo',
    ]);
    $tenant = Tenant::create([
        'nombre_comercial' => 'Café Luna', 'razon_social' => 'Café Luna SA de CV', 'slug' => 'cafe-luna',
    ]);
    $paquete = PaqueteViaje::create([
        'codigo_paquete' => 'P50', 'nombre' => 'Cincuenta', 'cantidad_viajes' => 50, 'precio' => 500, 'estado' => 'Activo',
    ]);

    $cargas = [];
    Event::listen(SaldoTenantCambiado::class, function (SaldoTenantCambiado $evento) use (&$cargas) {
        $cargas[] = $evento->broadcastWith() + ['slug' => $evento->tenantSlug];
    });

    $this->actingAs($admin, 'admin')
        ->postJson("/api/v1/admin/tenants/{$tenant->id_tenant}/creditos-paquetes", [
            'id_paquete' => $paquete->id_paquete, 'cantidad_paquetes' => 2,
        ])
        ->assertCreated()
        ->assertJsonPath('cantidad_viajes_acreditados', 100);

    tenancy()->initialize($tenant);
    expect(CompraPaquete::sum('cantidad_viajes'))->toBe(100)
        ->and(SaldoViajesTenant::actual())->toBe(100);
    tenancy()->end();

    // El saldo resultante, no el delta (RN-39), y sin `ambiente`: el Panel nunca lo descarta por
    // RN-28 aunque esté parado en TEST.
    expect($cargas)->toHaveCount(1)
        ->and($cargas[0]['saldo_viajes_tenant'])->toBe(100)
        ->and($cargas[0]['motivo'])->toBe('acreditacion')
        ->and($cargas[0]['id_pedido'])->toBeNull()
        ->and($cargas[0]['slug'])->toBe('cafe-luna')
        ->and($cargas[0])->not->toHaveKey('ambiente');
});
