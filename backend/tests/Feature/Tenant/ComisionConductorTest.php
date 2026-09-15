<?php

use App\Models\Tenant;
use App\Models\Tenant\ComisionConductor;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\ConsumoViajeTenant;
use App\Models\Tenant\MovimientoSaldo;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\VentaViajeConductor;
use App\Services\PedidoEstadoService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * spec tenant/030 — generación de la comisión del conductor al entregar.
 *
 * Todo pasa por `PedidoEstadoService::transicionar()` + `save()`, que es el único camino real
 * (Panel y app del conductor lo comparten). Lo que se afirma es la fila de `comisiones_conductor`:
 * cuándo nace, con qué base, y que nace una sola vez.
 */
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

function comisionTenant(): Tenant
{
    return Tenant::create([
        'nombre_comercial' => 'Comisión Express',
        'razon_social' => 'Comisión Express SA de CV',
        'slug' => 'comision-express',
    ]);
}

/**
 * Deja al tenant en la modalidad pedida, con un AdminCliente creado. La tenencia queda iniciada.
 */
function comisionAdmin(Tenant $tenant, string $modalidad = 'Comision', string $porcentaje = '25'): Usuario
{
    tenancy()->initialize($tenant);
    $admin = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Ruiz', 'email' => 'ana@comisionexpress.com',
        'password' => bcrypt('Password123!'), 'rol' => 'AdminCliente', 'estado' => 'Activo',
    ]);
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, $modalidad);
    ConfiguracionTenant::establecer(ConfiguracionTenant::COMISION_PORCENTAJE, $porcentaje);
    ConfiguracionTenant::establecer(ConfiguracionTenant::COSTO_VIAJE_PREPAGO, '20');

    return $admin;
}

function comisionConductor(string $email = 'beto@comisionexpress.com', int $viajesPrepagados = 0): Conductor
{
    $usuario = Usuario::create([
        'nombre' => 'Beto', 'apellido_paterno' => 'Lara', 'email' => $email,
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);

    $conductor = Conductor::create([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'LIC-'.$usuario->id_usuario,
        'estado' => 'ACTIVO',
        'disponibilidad' => 'DISPONIBLE',
    ]);

    ConductorEstado::create([
        'id_conductor' => $conductor->id_conductor,
        'estado' => 'ONLINE',
        'ultima_conexion' => now(),
        'ultima_actualizacion' => now(),
    ]);

    if ($viajesPrepagados > 0) {
        VentaViajeConductor::create([
            'id_conductor' => $conductor->id_conductor,
            'cantidad_viajes' => $viajesPrepagados,
            'monto_pagado' => 100,
            'id_usuario' => Usuario::where('rol', 'AdminCliente')->value('id_usuario'),
            'fecha_venta' => now(),
        ]);
    }

    return $conductor;
}

function comisionPedido(array $overrides = []): Pedido
{
    return Pedido::create(array_merge([
        'numero_pedido' => 'PED-'.random_int(100000, 999999),
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4326, 'longitud_recogida' => -99.1332,
        'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.4200, 'longitud_entrega' => -99.1600,
        'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true, 'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 100, 'estado' => 'ARRIBADO_A_ENTREGA',
    ], $overrides));
}

/**
 * Entrega el pedido por el camino real: transición + `save()`.
 */
function comisionEntregar(Pedido $pedido): Pedido
{
    app(PedidoEstadoService::class)->transicionar($pedido, 'ENTREGADO');
    $pedido->save();

    return $pedido;
}

it('generates one PENDIENTE comision over importe_envio with the current percentage when delivered', function () {
    $tenant = comisionTenant();
    comisionAdmin($tenant);
    $conductor = comisionConductor();

    $pedido = comisionEntregar(comisionPedido(['id_conductor' => $conductor->id_conductor]));

    $comision = ComisionConductor::where('id_pedido', $pedido->id_pedido)->first();

    expect($comision)->not->toBeNull()
        ->and($comision->id_conductor)->toBe($conductor->id_conductor)
        ->and((float) $comision->importe_envio)->toBe(100.0)
        ->and((float) $comision->porcentaje_aplicado)->toBe(25.0)
        ->and((float) $comision->importe_comision)->toBe(25.0)
        ->and($comision->estado)->toBe('PENDIENTE')
        ->and($comision->id_liquidacion)->toBeNull()
        ->and($comision->created_at)->not->toBeNull()
        // Adición 7: la columna vieja queda sin uso.
        ->and($pedido->fresh()->comision_calculada)->toBeNull();
});

it('uses only importe_envio as base, never importe_cobro nor pago_extra', function () {
    $tenant = comisionTenant();
    comisionAdmin($tenant);
    $conductor = comisionConductor();

    $pedido = comisionEntregar(comisionPedido([
        'id_conductor' => $conductor->id_conductor,
        'importe_envio' => 100,
        'importe_cobro' => 500,
        'pago_extra' => 40,
        'cargo_extra' => 60,
    ]));

    expect((float) ComisionConductor::where('id_pedido', $pedido->id_pedido)->value('importe_comision'))->toBe(25.0);
});

it('writes nothing before save() and does not duplicate on a second save()', function () {
    $tenant = comisionTenant();
    comisionAdmin($tenant);
    $conductor = comisionConductor();

    $pedido = comisionPedido(['id_conductor' => $conductor->id_conductor]);
    app(PedidoEstadoService::class)->transicionar($pedido, 'ENTREGADO');

    expect(ComisionConductor::count())->toBe(0);

    $pedido->save();
    $pedido->distancia_recorrida_km = 3.2;
    $pedido->save();

    expect(ComisionConductor::where('id_pedido', $pedido->id_pedido)->count())->toBe(1);
});

it('keeps the historical percentage when the tenant changes it afterwards', function () {
    $tenant = comisionTenant();
    comisionAdmin($tenant);
    $conductor = comisionConductor();

    $viejo = comisionEntregar(comisionPedido(['id_conductor' => $conductor->id_conductor]));

    ConfiguracionTenant::establecer(ConfiguracionTenant::COMISION_PORCENTAJE, '30');

    $nuevo = comisionEntregar(comisionPedido(['id_conductor' => $conductor->id_conductor]));

    $comisionVieja = ComisionConductor::where('id_pedido', $viejo->id_pedido)->first();
    $comisionNueva = ComisionConductor::where('id_pedido', $nuevo->id_pedido)->first();

    expect((float) $comisionVieja->porcentaje_aplicado)->toBe(25.0)
        ->and((float) $comisionVieja->importe_comision)->toBe(25.0)
        ->and((float) $comisionNueva->porcentaje_aplicado)->toBe(30.0)
        ->and((float) $comisionNueva->importe_comision)->toBe(30.0);
});

it('does not generate a comision under Prepago even with a percentage configured, and still discounts the prepaid viaje', function () {
    $tenant = comisionTenant();
    comisionAdmin($tenant, 'Prepago', '25');
    $conductor = comisionConductor(viajesPrepagados: 3);

    $pedido = comisionEntregar(comisionPedido(['id_conductor' => $conductor->id_conductor]));

    expect(ComisionConductor::count())->toBe(0)
        ->and($pedido->fresh()->prepago_descontado)->toBeTrue();
});

it('generates the comision for TEST pedidos as well', function () {
    $tenant = comisionTenant();
    comisionAdmin($tenant);
    $conductor = comisionConductor();

    $pedido = comisionPedido(['id_conductor' => $conductor->id_conductor]);
    $pedido->ambiente = Pedido::AMBIENTE_TEST;
    $pedido->save();

    comisionEntregar($pedido);

    expect(ComisionConductor::where('id_pedido', $pedido->id_pedido)->count())->toBe(1)
        // El consumo del tenant sí respeta su excepción de TEST (spec tenant/025): no se toca.
        ->and(ConsumoViajeTenant::count())->toBe(0);
});

it('does not generate a comision when the pedido is cancelled', function () {
    $tenant = comisionTenant();
    comisionAdmin($tenant);
    $conductor = comisionConductor();

    $pedido = comisionPedido(['id_conductor' => $conductor->id_conductor, 'estado' => 'EN_CAMINO']);
    $pedido->cancelado_por = 'ADMIN';
    $pedido->motivo_cancelacion = 'Cliente ausente';
    app(PedidoEstadoService::class)->transicionar($pedido, 'CANCELADO');
    $pedido->save();

    expect(ComisionConductor::count())->toBe(0);
});

it('creates a zero comision already PAGADA when the percentage is zero', function () {
    $tenant = comisionTenant();
    comisionAdmin($tenant, 'Comision', '0');
    $conductor = comisionConductor();

    $pedido = comisionEntregar(comisionPedido(['id_conductor' => $conductor->id_conductor]));

    $comision = ComisionConductor::where('id_pedido', $pedido->id_pedido)->first();

    expect((float) $comision->importe_comision)->toBe(0.0)
        ->and($comision->estado)->toBe('PAGADA')
        ->and($comision->pagada_en)->not->toBeNull();
});

it('still consumes exactly one tenant viaje under Comision, and touches no money ledger', function () {
    $tenant = comisionTenant();
    comisionAdmin($tenant);
    $conductor = comisionConductor();

    $pedido = comisionEntregar(comisionPedido(['id_conductor' => $conductor->id_conductor]));

    expect(ConsumoViajeTenant::where('id_pedido', $pedido->id_pedido)->count())->toBe(1)
        ->and(MovimientoSaldo::count())->toBe(0)
        ->and((float) $conductor->fresh()->saldo)->toBe(0.0)
        ->and($pedido->fresh()->prepago_descontado)->toBeFalse();
});

it('does not generate a comision on any state before ENTREGADO', function () {
    $tenant = comisionTenant();
    comisionAdmin($tenant);
    $conductor = comisionConductor();

    $pedido = comisionPedido(['estado' => 'PENDIENTE']);
    $servicio = app(PedidoEstadoService::class);

    $servicio->transicionar($pedido, 'PUBLICADO');
    $pedido->save();

    $pedido->id_conductor = $conductor->id_conductor;
    foreach (['TOMADO', 'ARRIBADO', 'EN_CAMINO', 'ARRIBADO_A_ENTREGA'] as $estado) {
        $servicio->transicionar($pedido, $estado);
        $pedido->save();
    }

    expect(ComisionConductor::count())->toBe(0);

    $servicio->transicionar($pedido, 'ENTREGADO');
    $pedido->save();

    expect(ComisionConductor::count())->toBe(1);
});
