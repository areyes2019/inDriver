<?php

use App\Models\Tenant;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\CompraPaquete;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\VentaViajeConductor;
use App\Services\PedidoEstadoService;
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

function ventaTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function ventaAdminUsuario(Tenant $tenant, array $overrides = []): Usuario
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

function ventaCrearConductor(Tenant $tenant, array $overrides = []): Conductor
{
    tenancy()->initialize($tenant);

    $usuario = Usuario::create(array_merge([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro'.uniqid().'@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ], $overrides));

    $conductor = Conductor::create([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'ABC'.uniqid(),
        'estado' => 'ACTIVO',
        'disponibilidad' => 'FUERA_DE_SERVICIO',
    ]);

    tenancy()->end();

    return $conductor;
}

function ventaConfigurarPrepago(Tenant $tenant, string $costoViaje): void
{
    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, 'Prepago');
    ConfiguracionTenant::establecer(ConfiguracionTenant::COSTO_VIAJE_PREPAGO, $costoViaje);
    tenancy()->end();
}

function ventaAcreditarPaquete(Tenant $tenant, int $cantidadViajes): void
{
    tenancy()->initialize($tenant);
    CompraPaquete::create([
        'codigo_paquete' => 'PAQ'.uniqid(),
        'cantidad_paquetes' => 1,
        'cantidad_viajes' => $cantidadViajes,
        'precio_unitario' => 0,
        'importe_total' => 0,
        'estado' => 'Activo',
        'fecha_compra' => now(),
    ]);
    tenancy()->end();
}

it('converts the amount paid into viajes using costo_viaje_prepago and credits the conductor', function () {
    $tenant = ventaTenant();
    $admin = ventaAdminUsuario($tenant);
    $conductor = ventaCrearConductor($tenant);
    ventaConfigurarPrepago($tenant, '100');
    ventaAcreditarPaquete($tenant, 50);

    $response = $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}/vender-viajes", [
            'monto_pagado' => 500,
        ])
        ->assertCreated()
        ->assertJsonPath('cantidad_viajes', 5)
        // La venta ya no descuenta del saldo del tenant: lo consumen los envíos entregados
        // (spec tenant/015, corrección "el saldo del tenant se consume por envío entregado").
        ->assertJsonPath('saldo_tenant', 50);

    expect((float) $response->json('monto_pagado'))->toBe(500.0);

    tenancy()->initialize($tenant);
    $venta = VentaViajeConductor::first();
    expect($venta->cantidad_viajes)->toBe(5);
    expect((float) $venta->monto_pagado)->toBe(500.0);
    expect(Auditoria::where('tabla_afectada', 'ventas_viajes_conductor')->where('accion', 'ALTA')->exists())->toBeTrue();
    tenancy()->end();
});

it('floors the conversion when the amount does not exactly divide into whole viajes', function () {
    $tenant = ventaTenant();
    $admin = ventaAdminUsuario($tenant);
    $conductor = ventaCrearConductor($tenant);
    ventaConfigurarPrepago($tenant, '100');
    ventaAcreditarPaquete($tenant, 50);

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}/vender-viajes", [
            'monto_pagado' => 250,
        ])
        ->assertCreated()
        ->assertJsonPath('cantidad_viajes', 2);
});

it('rejects crediting by amount when costo_viaje_prepago is not configured', function () {
    $tenant = ventaTenant();
    $admin = ventaAdminUsuario($tenant);
    $conductor = ventaCrearConductor($tenant);
    ventaAcreditarPaquete($tenant, 50);

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}/vender-viajes", [
            'monto_pagado' => 500,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['monto_pagado']);
});

it('rejects crediting by amount when it does not reach at least 1 viaje', function () {
    $tenant = ventaTenant();
    $admin = ventaAdminUsuario($tenant);
    $conductor = ventaCrearConductor($tenant);
    ventaConfigurarPrepago($tenant, '100');
    ventaAcreditarPaquete($tenant, 50);

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}/vender-viajes", [
            'monto_pagado' => 50,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['monto_pagado']);
});

it('rejects crediting an amount that converts to more viajes than the tenant has available', function () {
    $tenant = ventaTenant();
    $admin = ventaAdminUsuario($tenant);
    $conductor = ventaCrearConductor($tenant);
    ventaConfigurarPrepago($tenant, '100');
    ventaAcreditarPaquete($tenant, 3);

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}/vender-viajes", [
            'monto_pagado' => 500,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['monto_pagado']);
});

it('caps the sale against the balance left after deliveries, not after previous sales', function () {
    $tenant = ventaTenant();
    $admin = ventaAdminUsuario($tenant);
    $conductor = ventaCrearConductor($tenant);
    ventaConfigurarPrepago($tenant, '100');
    ventaAcreditarPaquete($tenant, 5);

    // Vender 5 no gasta el saldo del tenant (RN-C4): se puede volver a vender 5.
    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}/vender-viajes", ['monto_pagado' => 500])
        ->assertCreated()
        ->assertJsonPath('saldo_tenant', 5);

    // Tres envíos entregados sí lo gastan: quedan 2 y vender 3 ya no cabe.
    tenancy()->initialize($tenant);
    for ($i = 0; $i < 3; $i++) {
        $pedido = Pedido::create([
            'numero_pedido' => 'PED-'.random_int(100000, 999999),
            'nombre_solicitante' => 'Mario Sánchez', 'telefono_solicitante' => '5511223344',
            'direccion_recogida' => 'A', 'latitud_recogida' => 19.4, 'longitud_recogida' => -99.1,
            'direccion_entrega' => 'B', 'latitud_entrega' => 19.41, 'longitud_entrega' => -99.1,
            'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true,
            'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO', 'importe_envio' => 80,
            'estado' => 'ARRIBADO_A_ENTREGA', 'id_conductor' => $conductor->id_conductor,
        ]);
        app(PedidoEstadoService::class)->transicionar($pedido, 'ENTREGADO');
        $pedido->save();
    }
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}/vender-viajes", ['monto_pagado' => 300])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['monto_pagado']);

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}/vender-viajes", ['monto_pagado' => 200])
        ->assertCreated()
        ->assertJsonPath('saldo_tenant', 2);
});

it('lists the payment history of a conductor with the accumulated total', function () {
    $tenant = ventaTenant();
    $admin = ventaAdminUsuario($tenant);
    $conductor = ventaCrearConductor($tenant);
    ventaConfigurarPrepago($tenant, '100');
    ventaAcreditarPaquete($tenant, 50);

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}/vender-viajes", ['monto_pagado' => 500])
        ->assertCreated();
    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}/vender-viajes", ['monto_pagado' => 300])
        ->assertCreated();

    $response = $this->actingAs($admin, 'usuario')
        ->getJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}/historial-pagos")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect((float) $response->json('total_pagado'))->toBe(800.0);
});

it('lists payments from all conductores in the tenant-wide report', function () {
    $tenant = ventaTenant();
    $admin = ventaAdminUsuario($tenant);
    $conductorUno = ventaCrearConductor($tenant);
    $conductorDos = ventaCrearConductor($tenant);
    ventaConfigurarPrepago($tenant, '100');
    ventaAcreditarPaquete($tenant, 50);

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductorUno->id_conductor}/vender-viajes", ['monto_pagado' => 500])
        ->assertCreated();
    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductorDos->id_conductor}/vender-viajes", ['monto_pagado' => 200])
        ->assertCreated();

    $response = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/reportes/pagos-conductores')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect((float) $response->json('total_general'))->toBe(700.0);
});

it('rejects a non-AdminCliente role from accessing the payment report', function () {
    $tenant = ventaTenant();
    $despachador = ventaAdminUsuario($tenant, [
        'email' => 'pedro@cafeluna.com',
        'rol' => 'Despachador',
    ]);

    $this->actingAs($despachador, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/reportes/pagos-conductores')
        ->assertForbidden();
});
