<?php

use App\Models\Tenant;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\ZonaServicio;
use App\Services\Cliente\SelloCotizacion;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| spec tenant/035, Fase 4: zona de servicio, cotización con sello y dirección de un punto
| (RN-20 a RN-28).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    config(['services.google_maps.key' => 'llave-de-prueba']);
    $GLOBALS['cotizar_metros'] = 6000;

    gc_collect_cycles();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        if (! File::delete($file)) {
            usleep(50000);
            File::delete($file);
        }
    }

    Http::fake(function (PeticionHttp $peticion) {
        if (str_contains($peticion->url(), '/geocode/json')) {
            return Http::response(['status' => 'OK', 'results' => [['formatted_address' => 'Hidalgo 10, Centro, Celaya, Gto.']]]);
        }
        $metros = $GLOBALS['cotizar_metros'];

        return $metros === null
            ? Http::response(['status' => 'ZERO_RESULTS', 'routes' => []])
            : Http::response(['status' => 'OK', 'routes' => [['legs' => [[
                'distance' => ['value' => $metros],
                'duration' => ['value' => 900],
            ]]]]]);
    });

    $this->tenant = Tenant::create(['nombre_comercial' => 'Moto Celaya', 'razon_social' => 'Moto Celaya SA', 'slug' => 'moto-celaya']);
    tenancy()->initialize($this->tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::APP_CLIENTES, 'Si');
    // 10 de banderazo con 5 km incluidos y 5 por km adicional: 6 km cuestan 15.
    ConfiguracionTenant::establecer(ConfiguracionTenant::BANDERAZO, '10');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_INCLUIDOS, '5');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_ADICIONAL, '5');
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
function cotizacionValida(array $cambios = []): array
{
    return array_replace_recursive([
        'tipo' => 'ENVIAR',
        'recogida' => ['lat' => 20.5398, 'lng' => -100.8166],
        'entrega' => ['lat' => 20.5214, 'lng' => -100.8148],
        'modalidad' => 'REMITENTE_PAGA_ENVIO',
        'importe_producto' => null,
        'lo_antes_posible' => true,
    ], $cambios);
}

function cotizar($test, array $datos)
{
    return $test->withToken($test->token)->postJson('/api/v1/t/moto-celaya/cliente/cotizar', $datos);
}

/** Una zona alrededor del centro de Celaya: deja fuera a Querétaro. */
function zonaCelaya(): void
{
    ZonaServicio::create(['nombre' => 'Celaya', 'estado' => 'Activo', 'poligono' => [
        ['lat' => 20.60, 'lng' => -100.90],
        ['lat' => 20.60, 'lng' => -100.70],
        ['lat' => 20.45, 'lng' => -100.70],
        ['lat' => 20.45, 'lng' => -100.90],
    ]]);
}

it('quotes with the fleet rate, Google minutes and a seal (RN-21, RN-23)', function () {
    cotizar($this, cotizacionValida())
        ->assertOk()
        ->assertJsonPath('km', 6)
        ->assertJsonPath('minutos', 15)
        ->assertJsonPath('importe_envio', 15)
        ->assertJsonPath('importe_producto', 0)
        ->assertJsonPath('total', 15)
        ->assertJsonStructure(['sello', 'vence_en']);
});

it('adds the product only when the receiver pays shipping plus product (RN-27)', function () {
    cotizar($this, cotizacionValida(['modalidad' => 'RECEPTOR_PAGA_ENVIO_PRODUCTOS', 'importe_producto' => 380]))
        ->assertOk()
        ->assertJsonPath('importe_producto', 380)
        ->assertJsonPath('total', 395);

    cotizar($this, cotizacionValida(['modalidad' => 'RECEPTOR_PAGA_ENVIO', 'importe_producto' => 380]))
        ->assertOk()
        ->assertJsonPath('importe_producto', 0)
        ->assertJsonPath('total', 15);

    cotizar($this, cotizacionValida(['modalidad' => 'RECEPTOR_PAGA_ENVIO_PRODUCTOS', 'importe_producto' => 0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['importe_producto']);
});

it('rejects a point outside the service area and says which one (RN-20)', function () {
    tenancy()->initialize($this->tenant);
    zonaCelaya();
    tenancy()->end();

    cotizar($this, cotizacionValida(['entrega' => ['lat' => 20.5888, 'lng' => -100.3899]]))
        ->assertUnprocessable()
        ->assertJsonPath('motivo', 'FUERA_DE_ZONA')
        ->assertJsonPath('punto', 'entrega');

    $this->withToken($this->token)
        ->postJson('/api/v1/t/moto-celaya/cliente/zona', ['lat' => 20.5888, 'lng' => -100.3899])
        ->assertOk()
        ->assertJsonPath('cubre', false);
});

it('does not quote without a real street route or without rates (RN-21, RN-26)', function () {
    $GLOBALS['cotizar_metros'] = null;
    cotizar($this, cotizacionValida())->assertUnprocessable()->assertJsonPath('motivo', 'SIN_RUTA');

    $GLOBALS['cotizar_metros'] = 6000;
    tenancy()->initialize($this->tenant);
    ConfiguracionTenant::where('clave', ConfiguracionTenant::BANDERAZO)->delete();
    tenancy()->end();
    cotizar($this, cotizacionValida())->assertUnprocessable()->assertJsonPath('motivo', 'SIN_TARIFAS');
});

it('only quotes a scheduled order at least 30 minutes ahead (RN-28)', function () {
    $pronto = now()->addMinutes(10)->setTimezone(config('app.timezone_operacion'));
    cotizar($this, cotizacionValida([
        'lo_antes_posible' => false,
        'fecha' => $pronto->format('Y-m-d'),
        'hora' => $pronto->format('H:i'),
    ]))->assertUnprocessable()->assertJsonValidationErrors(['hora']);

    $luego = now()->addHours(3)->setTimezone(config('app.timezone_operacion'));
    cotizar($this, cotizacionValida([
        'lo_antes_posible' => false,
        'fecha' => $luego->format('Y-m-d'),
        'hora' => $luego->format('H:i'),
    ]))->assertOk();
});

it('seals the price for this client and fleet only, for ten minutes (RN-23, RN-24)', function () {
    $sello = cotizar($this, cotizacionValida())->json('sello');
    $sellos = app(SelloCotizacion::class);
    $idCliente = $this->cliente->id_cliente;

    $abierto = $sellos->abrir($sello, $idCliente, 'moto-celaya');
    expect($abierto['importe_envio'])->toBe(15.0)
        ->and($abierto['condiciones'])->toBe($sellos->condiciones(cotizacionValida()));

    expect($sellos->abrir($sello, $idCliente + 1, 'moto-celaya', $motivo))->toBeNull()
        ->and($motivo)->toBe('AJENO')
        ->and($sellos->abrir($sello, $idCliente, 'otra-flotilla', $motivo))->toBeNull()
        ->and($motivo)->toBe('AJENO')
        ->and($sellos->abrir(substr($sello, 0, -4).'abcd', $idCliente, 'moto-celaya', $motivo))->toBeNull()
        ->and($motivo)->toBe('ALTERADO');

    $this->travel(11)->minutes();
    expect($sellos->abrir($sello, $idCliente, 'moto-celaya', $motivo))->toBeNull()
        ->and($motivo)->toBe('VENCIDO');
});

it('gives the readable address of a point picked on the map', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/t/moto-celaya/cliente/direccion', ['lat' => 20.52, 'lng' => -100.81])
        ->assertOk()
        ->assertJsonPath('direccion', 'Hidalgo 10, Centro, Celaya, Gto.');
});

it('requires a client session to quote', function () {
    $this->postJson('/api/v1/t/moto-celaya/cliente/cotizar', cotizacionValida())->assertUnauthorized();
});

it('sends the active zones rectangle so the address search stays inside it', function () {
    $this->getJson('/api/v1/t/moto-celaya/cliente/marca')->assertJsonPath('limites', null);

    tenancy()->initialize($this->tenant);
    zonaCelaya();
    tenancy()->end();

    $this->getJson('/api/v1/t/moto-celaya/cliente/marca')
        ->assertJsonPath('limites', ['north' => 20.6, 'south' => 20.45, 'east' => -100.7, 'west' => -100.9]);
});
