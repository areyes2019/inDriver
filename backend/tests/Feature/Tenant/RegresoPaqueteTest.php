<?php

use App\Models\Tenant;
use App\Models\Tenant\ComisionConductor;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\ConsumoViajeTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use App\Services\PedidoEstadoService;
use App\Support\SeguimientoEnvio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    gc_collect_cycles();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }

    tenancy()->initialize(Tenant::create([
        'nombre_comercial' => 'Moto Celaya',
        'razon_social' => 'Moto Celaya SA de CV',
        'slug' => 'moto-celaya',
    ]));

    ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, 'Comision');
    ConfiguracionTenant::establecer(ConfiguracionTenant::COMISION_PORCENTAJE, '10');

    $usuario = Usuario::create([
        'nombre' => 'Beto',
        'apellido_paterno' => 'Salgado',
        'email' => 'beto@motocelaya.com',
        'password' => bcrypt('Password123!'),
        'rol' => 'Conductor',
        'estado' => 'Activo',
    ]);

    $this->conductor = Conductor::create([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'LIC-1',
        'estado' => 'ACTIVO',
        'disponibilidad' => 'DISPONIBLE',
    ]);
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

function pedidoEnDestino(Conductor $conductor, array $overrides = []): Pedido
{
    return Pedido::create(array_merge([
        'numero_pedido' => 'PED-000001',
        'id_conductor' => $conductor->id_conductor,
        'nombre_solicitante' => 'Juan Pérez',
        'telefono_solicitante' => '4611234567',
        'direccion_recogida' => 'Av. Tecnológico 100',
        'latitud_recogida' => 20.52,
        'longitud_recogida' => -100.81,
        'direccion_entrega' => 'Centro',
        'latitud_entrega' => 20.53,
        'longitud_entrega' => -100.82,
        'fecha_servicio' => '2026-11-05',
        'lo_antes_posible' => true,
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO_PRODUCTOS',
        'importe_envio' => 50,
        'importe_cobro' => 300,
        'estado' => 'ARRIBADO_A_ENTREGA',
    ], $overrides));
}

function cambiarA(Pedido $pedido, string $estado): void
{
    app(PedidoEstadoService::class)->transicionar($pedido, $estado);
    $pedido->save();
}

it('fija la tarifa en el 30% del envío al iniciar el regreso', function () {
    $pedido = pedidoEnDestino($this->conductor);

    cambiarA($pedido, 'EN_REGRESO');

    expect((float) $pedido->fresh()->tarifa_regreso)->toBe(15.0)
        ->and($pedido->fresh()->estado)->toBe('EN_REGRESO');
});

it('solo regresa un contra entrega con producto', function (array $datos) {
    $pedido = pedidoEnDestino($this->conductor, $datos);

    expect(fn () => cambiarA($pedido, 'EN_REGRESO'))->toThrow(ValidationException::class);

    expect($pedido->fresh()->estado)->toBe('ARRIBADO_A_ENTREGA')
        ->and((float) $pedido->fresh()->tarifa_regreso)->toBe(0.0);
})->with([
    'quien recibe paga' => [['modalidad_pago' => 'RECEPTOR_PAGA_ENVIO', 'importe_cobro' => 0]],
    'quien envía paga' => [['modalidad_pago' => 'REMITENTE_PAGA_ENVIO', 'importe_cobro' => 0]],
    'producto en cero' => [['importe_cobro' => 0]],
]);

it('no regresa antes de llegar con el comprador', function () {
    $pedido = pedidoEnDestino($this->conductor, ['estado' => 'EN_CAMINO']);

    expect(fn () => cambiarA($pedido, 'EN_REGRESO'))->toThrow(ValidationException::class);
});

it('al cerrar genera comisión sobre el envío, consume un viaje y libera al conductor', function () {
    $pedido = pedidoEnDestino($this->conductor);

    cambiarA($pedido, 'EN_REGRESO');

    expect(ComisionConductor::count())->toBe(0)
        ->and(ConsumoViajeTenant::count())->toBe(0)
        ->and($this->conductor->tienePedidoActivo())->toBeTrue();

    cambiarA($pedido, 'DEVUELTO');

    $comision = ComisionConductor::sole();

    expect((float) $comision->importe_envio)->toBe(50.0)
        ->and((float) $comision->importe_comision)->toBe(5.0)
        ->and(ConsumoViajeTenant::where('id_pedido', $pedido->id_pedido)->count())->toBe(1)
        ->and($pedido->fresh()->fecha_entrega)->not->toBeNull()
        ->and($this->conductor->tienePedidoActivo())->toBeFalse();
});

it('una emergencia en el regreso se cierra por incidencia, sin comisión', function () {
    $pedido = pedidoEnDestino($this->conductor);

    cambiarA($pedido, 'EN_REGRESO');
    $pedido->motivo_cancelacion = 'El vendedor no está';
    cambiarA($pedido, 'INCIDENCIA');

    expect($pedido->fresh()->estado)->toBe('INCIDENCIA')
        ->and(ComisionConductor::count())->toBe(0)
        ->and(ConsumoViajeTenant::count())->toBe(0);
});

it('la línea del mapa va del comprador al vendedor', function () {
    $pedido = pedidoEnDestino($this->conductor);

    cambiarA($pedido, 'EN_REGRESO');
    $seguimiento = SeguimientoEnvio::para($pedido->fresh());

    expect($seguimiento['hito'])->toBe('H2')
        ->and($seguimiento['origen'])->toBe(['lat' => 20.53, 'lng' => -100.82])
        ->and($seguimiento['destino'])->toBe(['lat' => 20.52, 'lng' => -100.81]);

    cambiarA($pedido, 'DEVUELTO');

    expect(SeguimientoEnvio::para($pedido->fresh()))->toBeNull();
});

it('un regreso sigue en turno y un devuelto ya no', function () {
    expect(PedidoEstadoService::ESTADOS_EN_TURNO)->toContain('EN_REGRESO')
        ->and(PedidoEstadoService::ESTADOS_EN_TURNO)->not->toContain('DEVUELTO')
        ->and(PedidoEstadoService::ESTADOS_FINALES)->toContain('DEVUELTO');
});
