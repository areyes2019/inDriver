<?php

use App\Events\Tenant\PedidoCreado;
use App\Models\Tenant;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Services\CreacionPedidoService;
use App\Services\RutaService;
use App\Services\TarifaService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    gc_collect_cycles();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }

    $tenant = Tenant::create([
        'nombre_comercial' => 'Moto Celaya',
        'razon_social' => 'Moto Celaya SA de CV',
        'slug' => 'moto-celaya',
    ]);

    tenancy()->initialize($tenant);
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

function datosDePedido(array $overrides = []): array
{
    return array_merge([
        'nombre_solicitante' => 'Juan Pérez',
        'telefono_solicitante' => '4611234567',
        'direccion_recogida' => 'Av. Tecnológico 100, Celaya',
        'latitud_recogida' => 20.52,
        'longitud_recogida' => -100.81,
        'direccion_entrega' => 'Centro, Celaya',
        'latitud_entrega' => 20.53,
        'longitud_entrega' => -100.82,
        'fecha_servicio' => '2026-11-05',
        'lo_antes_posible' => true,
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO_PRODUCTOS',
        'importe_envio' => 50,
        'importe_cobro' => 300,
    ], $overrides);
}

it('crea sin usuario, publica el pedido inmediato, audita y anuncia el alta', function () {
    Event::fake([PedidoCreado::class]);

    $pedido = app(CreacionPedidoService::class)->crear(datosDePedido(), null);

    expect($pedido->numero_pedido)->toBe('PED-000001')
        ->and($pedido->fresh()->estado)->toBe('PUBLICADO')
        ->and((float) $pedido->importe_cobro)->toBe(300.0);

    $auditoria = Auditoria::query()->where('accion', 'ALTA')->sole();

    expect($auditoria->id_usuario)->toBeNull()
        ->and($auditoria->descripcion)->toBe('Alta del pedido PED-000001');

    Event::assertDispatched(PedidoCreado::class, 1);
});

it('deja pendiente el pedido agendado', function () {
    Event::fake([PedidoCreado::class]);

    $pedido = app(CreacionPedidoService::class)->crear(datosDePedido([
        'lo_antes_posible' => false,
        'hora_desde' => '17:00',
        'hora_hasta' => '18:00',
    ]), null);

    expect($pedido->fresh()->estado)->toBe('PENDIENTE');
    Event::assertDispatched(PedidoCreado::class, 1);
});

it('sella el ambiente del interruptor', function () {
    Event::fake([PedidoCreado::class]);
    ConfiguracionTenant::establecer(ConfiguracionTenant::AMBIENTE, Pedido::AMBIENTE_TEST);

    $pedido = app(CreacionPedidoService::class)->crear(datosDePedido(), null);

    expect(Pedido::withoutGlobalScopes()->find($pedido->id_pedido)->ambiente)->toBe(Pedido::AMBIENTE_TEST);
});

it('recalcula el número si otro pedido simultáneo ganó el mismo', function () {
    Event::fake([PedidoCreado::class]);
    $yaChoco = false;

    Pedido::creating(function (Pedido $pedido) use (&$yaChoco) {
        if (! $yaChoco) {
            $yaChoco = true;
            Pedido::create(datosDePedido(['numero_pedido' => $pedido->numero_pedido, 'estado' => 'PENDIENTE']));
        }
    });

    $pedido = app(CreacionPedidoService::class)->crear(datosDePedido(), null);

    expect($yaChoco)->toBeTrue()
        ->and($pedido->numero_pedido)->toBe('PED-000001')
        ->and(Pedido::withoutGlobalScopes()->count())->toBe(1)
        ->and(Auditoria::query()->where('accion', 'ALTA')->count())->toBe(1);
});

it('deja de reintentar si el número sigue chocando', function () {
    Event::fake([PedidoCreado::class]);
    $anidado = false;
    $choques = 0;

    Pedido::creating(function (Pedido $pedido) use (&$anidado, &$choques) {
        if (! $anidado) {
            $anidado = true;
            $choques++;
            Pedido::create(datosDePedido(['numero_pedido' => $pedido->numero_pedido, 'estado' => 'PENDIENTE']));
            $anidado = false;
        }
    });

    expect(fn () => app(CreacionPedidoService::class)->crear(datosDePedido(), null))
        ->toThrow(UniqueConstraintViolationException::class);

    expect($choques)->toBe(3)
        ->and(Auditoria::query()->where('accion', 'ALTA')->count())->toBe(0);
});

it('calcula la tarifa con la misma fórmula del Panel', function (float $km, float $esperado) {
    $tarifas = ['banderazo' => 40.0, 'km_incluidos' => 3.0, 'km_adicional' => 7.0];

    expect(app(TarifaService::class)->importe($km, $tarifas))->toBe($esperado);
})->with([
    'sin distancia' => [0.0, 40.0],
    'dentro de lo incluido' => [2.5, 40.0],
    'justo en el límite' => [3.0, 40.0],
    'con km adicionales' => [8.3, 77.1],
    'redondea a centavos' => [3.333, 42.33],
]);

it('no tiene tarifas vigentes si falta alguna', function () {
    $servicio = app(TarifaService::class);

    ConfiguracionTenant::establecer(ConfiguracionTenant::BANDERAZO, '40');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_INCLUIDOS, '3');
    expect($servicio->vigentes())->toBeNull();

    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_ADICIONAL, '7.5');
    expect($servicio->vigentes())->toBe(['banderazo' => 40.0, 'km_incluidos' => 3.0, 'km_adicional' => 7.5]);
});

it('lee la distancia por calle de Google', function () {
    config(['services.google_maps.key' => 'llave']);
    Http::fake(['maps.googleapis.com/*' => Http::response([
        'status' => 'OK',
        'routes' => [['legs' => [['distance' => ['value' => 8300]]]]],
    ])]);

    $km = app(RutaService::class)->distanciaPorCalleKm(['lat' => 20.52, 'lng' => -100.81], ['lat' => 20.53, 'lng' => -100.82]);

    expect($km)->toBe(8.3);
});

it('no inventa la distancia si Google no da una ruta real', function (string $status) {
    config(['services.google_maps.key' => 'llave']);
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => $status, 'routes' => []])]);

    $km = app(RutaService::class)->distanciaPorCalleKm(['lat' => 20.52, 'lng' => -100.81], ['lat' => 20.53, 'lng' => -100.82]);

    expect($km)->toBeNull();
})->with(['ZERO_RESULTS', 'REQUEST_DENIED', 'OVER_QUERY_LIMIT']);

it('no inventa la distancia si Google no responde', function () {
    config(['services.google_maps.key' => 'llave']);
    Http::fake(function () {
        throw new RuntimeException('sin conexión');
    });

    $km = app(RutaService::class)->distanciaPorCalleKm(['lat' => 20.52, 'lng' => -100.81], ['lat' => 20.53, 'lng' => -100.82]);

    expect($km)->toBeNull();
});

it('no mide la distancia sin llave de Google', function () {
    config(['services.google_maps.key' => '']);
    Http::fake();

    expect(app(RutaService::class)->distanciaPorCalleKm(['lat' => 20.52, 'lng' => -100.81], ['lat' => 20.53, 'lng' => -100.82]))->toBeNull();

    Http::assertNothingSent();
});
