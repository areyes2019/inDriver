<?php

use App\Events\Tenant\PedidoCanceladoSinConductor;
use App\Events\Tenant\PedidoCreado;
use App\Models\Tenant;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\ClienteSolicitudPedido;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\ZonaServicio;
use App\Services\Cliente\SelloCotizacion;
use App\Services\Cliente\SolicitudPedidoService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
| spec tenant/035, Fase 5: creación con llave anti-duplicados, TEST, horario, ventana, límites y
| cancelación (RN-24, RN-28 a RN-38; criterios 12 a 17).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    config(['services.google_maps.key' => 'llave-de-prueba']);

    gc_collect_cycles();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        if (! File::delete($file)) {
            usleep(50000);
            File::delete($file);
        }
    }

    // 6 km por calles: con 10 de banderazo, 5 km incluidos y 5 por km adicional cuestan 15.
    Http::fake(fn () => Http::response(['status' => 'OK', 'routes' => [['legs' => [[
        'distance' => ['value' => 6000],
        'duration' => ['value' => 900],
    ]]]]]));

    $this->tenant = Tenant::create(['nombre_comercial' => 'Moto Celaya', 'razon_social' => 'Moto Celaya SA', 'slug' => 'moto-celaya']);
    tenancy()->initialize($this->tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::APP_CLIENTES, 'Si');
    ConfiguracionTenant::establecer(ConfiguracionTenant::BANDERAZO, '10');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_INCLUIDOS, '5');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_ADICIONAL, '5');
    ZonaServicio::create(['nombre' => 'Celaya', 'estado' => 'Activo', 'poligono' => [
        ['lat' => 20.60, 'lng' => -100.90],
        ['lat' => 20.60, 'lng' => -100.70],
        ['lat' => 20.45, 'lng' => -100.70],
        ['lat' => 20.45, 'lng' => -100.90],
    ]]);
    $this->cliente = Cliente::create(['nombre' => 'Ana Torres', 'telefono' => '4617778899', 'estado' => 'Activo']);
    $this->token = $this->cliente->createToken('pide', [Cliente::HABILIDAD_APP])->plainTextToken;
    tenancy()->end();
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

/** @param  array<string, mixed>  $cambios */
function solicitudApp(array $cambios = []): array
{
    return array_replace_recursive([
        'tipo' => 'ENVIAR',
        'recogida' => ['lat' => 20.5398, 'lng' => -100.8166, 'direccion' => 'Hidalgo 10, Centro, Celaya'],
        'entrega' => ['lat' => 20.5214, 'lng' => -100.8148, 'direccion' => 'Juárez 200, Celaya'],
        'referencias_recogida' => 'Portón negro',
        'referencias_entrega' => null,
        'contacto_recogida' => ['nombre' => 'Ana Torres', 'telefono' => '461 777 8899'],
        'recibe' => ['nombre' => 'Luis Pérez', 'telefono' => '4611234567'],
        'modalidad' => 'REMITENTE_PAGA_ENVIO',
        'importe_producto' => null,
        'lo_antes_posible' => true,
    ], $cambios);
}

/** Cotiza la solicitud y le pone el sello que devolvió el servidor. */
function selladaApp($test, array $solicitud): array
{
    $sello = $test->withToken($test->token)
        ->postJson('/api/v1/t/moto-celaya/cliente/cotizar', $solicitud)
        ->assertOk()
        ->json('sello');

    return [...$solicitud, 'sello' => $sello];
}

function pedirApp($test, array $cuerpo, ?string $llave = null)
{
    return $test->withToken($test->token)
        ->withHeader('Idempotency-Key', $llave ?? (string) Str::uuid())
        ->postJson('/api/v1/t/moto-celaya/cliente/pedidos', $cuerpo);
}

it('creates the order through the shared service with the app columns (RN-30, criterio 15)', function () {
    Event::fake([PedidoCreado::class]);

    pedirApp($this, selladaApp($this, solicitudApp()))
        ->assertCreated()
        ->assertJsonPath('numero', 'PED-000001')
        ->assertJsonPath('estado', 'PUBLICADO')
        ->assertJsonPath('importe_envio', 15)
        ->assertJsonPath('recibe.nombre', 'Luis Pérez')
        ->assertJsonPath('repartidor', null);

    tenancy()->initialize($this->tenant);
    $pedido = Pedido::withoutGlobalScopes()->sole();

    expect($pedido->origen)->toBe('APP')
        ->and($pedido->tipo_servicio)->toBe('ENVIAR')
        ->and($pedido->id_cliente)->toBe($this->cliente->id_cliente)
        ->and($pedido->contacto_recogida_telefono)->toBe('4617778899')
        ->and($pedido->referencias_recogida)->toBe('Portón negro')
        ->and($pedido->telefono_solicitante)->toBe('4611234567')
        ->and((float) $pedido->importe_envio)->toBe(15.0)
        ->and((float) $pedido->importe_cobro)->toBe(0.0)
        ->and($pedido->ambiente)->toBe(Pedido::AMBIENTE_LIVE);

    Event::assertDispatched(PedidoCreado::class);
});

it('returns the same order for a repeated key and rejects the key with other data (RN-31, criterio 12)', function () {
    $cuerpo = selladaApp($this, solicitudApp());
    $llave = (string) Str::uuid();

    $primero = pedirApp($this, $cuerpo, $llave)->assertCreated()->json('id');
    pedirApp($this, $cuerpo, $llave)->assertOk()->assertJsonPath('id', $primero);

    pedirApp($this, [...$cuerpo, 'referencias_recogida' => 'Otra puerta'], $llave)
        ->assertUnprocessable()
        ->assertJsonPath('motivo', 'OTROS_DATOS');

    tenancy()->initialize($this->tenant);
    expect(Pedido::withoutGlobalScopes()->count())->toBe(1);
});

it('lets only one of two simultaneous confirmations create the order (RN-31, criterio 12)', function () {
    $cuerpo = selladaApp($this, solicitudApp());
    $llave = (string) Str::uuid();

    // La otra petición ya apartó la llave y sigue creando el pedido.
    pedirApp($this, $cuerpo, $llave)->assertCreated();
    tenancy()->initialize($this->tenant);
    $solicitud = ClienteSolicitudPedido::sole();
    $solicitud->update(['id_pedido' => null]);

    $resultado = app(SolicitudPedidoService::class)->crear($this->cliente, $llave, $solicitud->huella, []);
    tenancy()->end();

    expect($resultado)->toBe([SolicitudPedidoService::PROCESANDO, null]);

    pedirApp($this, $cuerpo, $llave)
        ->assertConflict()
        ->assertJsonPath('motivo', 'PROCESANDO');

    tenancy()->initialize($this->tenant);
    expect(Pedido::withoutGlobalScopes()->count())->toBe(1);
});

it('never finds another client key', function () {
    $cuerpo = selladaApp($this, solicitudApp());
    $llave = (string) Str::uuid();
    pedirApp($this, $cuerpo, $llave)->assertCreated();

    tenancy()->initialize($this->tenant);
    $otro = Cliente::create(['nombre' => 'Otro', 'telefono' => '4610000000', 'estado' => 'Activo']);
    $this->token = $otro->createToken('pide', [Cliente::HABILIDAD_APP])->plainTextToken;
    tenancy()->end();
    app('auth')->forgetGuards();

    // Con la misma llave, el sello de Ana no le sirve a otro cliente: no recibe su pedido.
    pedirApp($this, $cuerpo, $llave)
        ->assertUnprocessable()
        ->assertJsonPath('motivo', 'SELLO_INVALIDO');
});

it('rejects a seal that does not match the order data (RN-24)', function () {
    $cuerpo = selladaApp($this, solicitudApp());

    pedirApp($this, [...$cuerpo, 'modalidad' => 'RECEPTOR_PAGA_ENVIO'])
        ->assertUnprocessable()
        ->assertJsonPath('motivo', 'DATOS_DISTINTOS');

    pedirApp($this, [...$cuerpo, 'sello' => 'alterado'])
        ->assertUnprocessable()
        ->assertJsonPath('motivo', 'SELLO_INVALIDO');
});

it('answers an expired seal with a fresh quote (RN-24)', function () {
    $cuerpo = selladaApp($this, solicitudApp());

    $this->travel(SelloCotizacion::VIGENCIA_MINUTOS + 1)->minutes();

    pedirApp($this, $cuerpo)
        ->assertConflict()
        ->assertJsonPath('motivo', 'SELLO_VENCIDO')
        ->assertJsonPath('cotizacion.total', 15)
        ->assertJsonStructure(['cotizacion' => ['sello', 'vence_en']]);
});

it('schedules with the fleet window and caps it at midnight (RN-28, criterio 16)', function () {
    tenancy()->initialize($this->tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::VENTANA_PROGRAMADO_MIN, '90');
    tenancy()->end();

    $manana = now()->setTimezone(config('app.timezone_operacion'))->addDay()->toDateString();

    pedirApp($this, selladaApp($this, solicitudApp(['lo_antes_posible' => false, 'fecha' => $manana, 'hora' => '10:00'])))
        ->assertCreated()
        ->assertJsonPath('estado', 'PENDIENTE');
    pedirApp($this, selladaApp($this, solicitudApp(['lo_antes_posible' => false, 'fecha' => $manana, 'hora' => '23:00'])))
        ->assertCreated();

    tenancy()->initialize($this->tenant);
    $pedidos = Pedido::withoutGlobalScopes()->orderBy('id_pedido')->get();

    expect($pedidos[0]->hora_desde)->toStartWith('10:00')
        ->and($pedidos[0]->hora_hasta)->toStartWith('11:30')
        ->and($pedidos[0]->lo_antes_posible)->toBeFalse()
        ->and($pedidos[1]->hora_hasta)->toStartWith('23:59');
});

it('only allows "as soon as possible" inside the fleet hours (RN-29)', function () {
    $cuerpo = selladaApp($this, solicitudApp());

    tenancy()->initialize($this->tenant);
    // Ningún día abierto.
    ConfiguracionTenant::establecer(ConfiguracionTenant::HORARIO_ATENCION, json_encode(array_fill_keys(range(1, 7), null)));
    tenancy()->end();

    pedirApp($this, $cuerpo)->assertUnprocessable()->assertJsonPath('motivo', 'FUERA_DE_HORARIO');

    $this->getJson('/api/v1/t/moto-celaya/cliente/marca')->assertJsonPath('abierto_ahora', false);
});

it('limits a client to 3 active orders (RN-34)', function () {
    foreach (range(1, 3) as $i) {
        pedirApp($this, selladaApp($this, solicitudApp(['referencias_recogida' => "Puerta {$i}"])))->assertCreated();
    }

    pedirApp($this, selladaApp($this, solicitudApp(['referencias_recogida' => 'Puerta 4'])))
        ->assertTooManyRequests()
        ->assertJsonPath('motivo', 'LIMITE');
});

it('lets the client cancel while searching, but not once a driver took it (RN-36, criterio 17)', function () {
    $id = pedirApp($this, selladaApp($this, solicitudApp()))->assertCreated()->json('id');

    $this->withToken($this->token)
        ->postJson("/api/v1/t/moto-celaya/cliente/pedidos/{$id}/cancelar")
        ->assertOk()
        ->assertJsonPath('estado', 'CANCELADO');

    tenancy()->initialize($this->tenant);
    expect(Pedido::withoutGlobalScopes()->find($id)->cancelado_por)->toBe('CLIENTE');

    $tomado = Pedido::withoutGlobalScopes()->find($id)->replicate();
    $tomado->numero_pedido = 'PED-000099';
    $tomado->estado = 'TOMADO';
    $tomado->ambiente = Pedido::AMBIENTE_LIVE;
    $tomado->save();
    tenancy()->end();

    $this->withToken($this->token)
        ->postJson("/api/v1/t/moto-celaya/cliente/pedidos/{$tomado->id_pedido}/cancelar")
        ->assertConflict()
        ->assertJsonPath('motivo', 'YA_ASIGNADO')
        ->assertJsonPath('pedido.estado', 'TOMADO');
});

it('lists and shows only the client own orders', function () {
    $id = pedirApp($this, selladaApp($this, solicitudApp()))->assertCreated()->json('id');

    $this->withToken($this->token)->getJson('/api/v1/t/moto-celaya/cliente/pedidos?grupo=activos')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id);
    $this->withToken($this->token)->getJson('/api/v1/t/moto-celaya/cliente/pedidos?grupo=cancelados')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    tenancy()->initialize($this->tenant);
    $otro = Cliente::create(['nombre' => 'Otro', 'telefono' => '4610000000', 'estado' => 'Activo']);
    $this->token = $otro->createToken('pide', [Cliente::HABILIDAD_APP])->plainTextToken;
    tenancy()->end();
    app('auth')->forgetGuards();

    $this->withToken($this->token)->getJson("/api/v1/t/moto-celaya/cliente/pedidos/{$id}")->assertNotFound();
    $this->withToken($this->token)->postJson("/api/v1/t/moto-celaya/cliente/pedidos/{$id}/cancelar")->assertNotFound();
});

it('purges request keys older than 48 hours without touching their orders', function () {
    pedirApp($this, selladaApp($this, solicitudApp()))->assertCreated();

    $this->travel(47)->hours();
    $this->artisan('clientes:purgar-codigos')->assertSuccessful();
    tenancy()->initialize($this->tenant);
    expect(ClienteSolicitudPedido::count())->toBe(1);
    tenancy()->end();

    $this->travel(2)->hours();
    $this->artisan('clientes:purgar-codigos')->assertSuccessful();
    tenancy()->initialize($this->tenant);
    expect(ClienteSolicitudPedido::count())->toBe(0)
        ->and(Pedido::withoutGlobalScopes()->count())->toBe(1);
});

it('tells the Panel the order was cancelled so its card disappears', function () {
    $id = pedirApp($this, selladaApp($this, solicitudApp()))->assertCreated()->json('id');

    Event::fake([PedidoCanceladoSinConductor::class]);

    $this->withToken($this->token)
        ->postJson("/api/v1/t/moto-celaya/cliente/pedidos/{$id}/cancelar")
        ->assertOk();

    Event::assertDispatched(PedidoCanceladoSinConductor::class, function (PedidoCanceladoSinConductor $evento) use ($id) {
        $carga = $evento->broadcastWith();

        return $carga['id_pedido'] === $id && $carga['estado'] === 'CANCELADO';
    });
});
