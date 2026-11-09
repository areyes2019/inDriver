<?php

use Anthropic\Client;
use App\Models\Tenant;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\WhatsappConversacion;
use App\Models\Tenant\WhatsappMensaje;
use App\Models\Tenant\ZonaServicio;
use App\Services\Whatsapp\AsistentePedidoWhatsapp;
use App\Services\Whatsapp\ExtraccionFallida;
use App\Services\Whatsapp\ExtraccionPedido;
use App\Services\Whatsapp\ExtractorPedidoIA;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// spec tenant/031, etapa 5: la IA extrae, Laravel valida y cotiza, nada se crea.

beforeEach(function () {
    gc_collect_cycles();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }

    config(['services.google_maps.key' => 'llave-de-maps']);

    $tenant = Tenant::create([
        'nombre_comercial' => 'Moto Uno',
        'razon_social' => 'Moto Uno SA de CV',
        'slug' => 'moto-uno',
        'estado' => 'Activo',
    ]);
    tenancy()->initialize($tenant);

    // 10 de banderazo con 5 km incluidos y 5 por km adicional: 6 km cuestan 15.
    ConfiguracionTenant::establecer(ConfiguracionTenant::BANDERAZO, '10');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_INCLUIDOS, '5');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_ADICIONAL, '5');

    $this->conversacion = WhatsappConversacion::create(['usuario_whatsapp' => 'MX.BSUID1']);
    WhatsappMensaje::create([
        'id_conversacion' => $this->conversacion->id_conversacion,
        'wamid' => 'wamid.UNO',
        'direccion' => WhatsappMensaje::ENTRANTE,
        'tipo' => 'text',
        'texto' => 'Quiero un envío',
        'enviado_en' => now(),
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

function iaEntiende(array $datos): void
{
    $extraccion = ExtraccionPedido::desdeArreglo(array_merge([
        'intencion' => 'pedido_envio',
        'recogida_direccion' => 'Hidalgo 10, Centro, Celaya',
        'entrega_direccion' => 'Morelos 20, Alameda, Celaya',
        'destinatario_nombre' => 'Juan',
        'destinatario_telefono' => '461 123 4567',
        'quien_paga_envio' => 'destinatario',
        'hay_cobro_producto' => 'si',
        'importe_producto' => 300,
    ], $datos));

    test()->mock(ExtractorPedidoIA::class)->shouldReceive('extraer')->andReturn($extraccion);
}

/**
 * Google responde según la dirección pedida. Una dirección sin entrada en `$lugares` no existe.
 *
 * @param  array<string, array{0: float, 1: float, 2?: string}>  $lugares  dirección => [lat, lng, location_type]
 */
function googleResponde(array $lugares, ?int $metros = 6000): void
{
    Http::fake(function (PeticionHttp $peticion) use ($lugares, $metros) {
        if (str_contains($peticion->url(), '/geocode/json')) {
            $lugar = $lugares[$peticion['address']] ?? null;

            if ($lugar === null) {
                return Http::response(['status' => 'ZERO_RESULTS', 'results' => []]);
            }

            return Http::response(['status' => 'OK', 'results' => [[
                'formatted_address' => "{$peticion['address']}, Gto., México",
                'geometry' => [
                    'location' => ['lat' => $lugar[0], 'lng' => $lugar[1]],
                    'location_type' => $lugar[2] ?? 'ROOFTOP',
                ],
            ]]]);
        }

        if (str_contains($peticion->url(), '/directions/json')) {
            return $metros === null
                ? Http::response(['status' => 'ZERO_RESULTS', 'routes' => []])
                : Http::response(['status' => 'OK', 'routes' => [['legs' => [['distance' => ['value' => $metros]]]]]]);
        }

        return Http::response([], 404);
    });
}

function lugaresDeCelaya(): array
{
    return [
        'Hidalgo 10, Centro, Celaya' => [20.52, -100.81],
        'Morelos 20, Alameda, Celaya' => [20.53, -100.82],
    ];
}

it('dice lo que entendió y el precio de un contra entrega, sin crear nada', function () {
    iaEntiende([]);
    googleResponde(lugaresDeCelaya());

    $respuesta = app(AsistentePedidoWhatsapp::class)->responder($this->conversacion, '4613581090');

    expect($respuesta)
        ->toContain('Recoger en: Hidalgo 10, Centro, Celaya, Gto., México')
        ->toContain('Entregar en: Morelos 20, Alameda, Celaya, Gto., México')
        ->toContain('Recibe: Juan, 4611234567')
        ->toContain('Paga el envío: quien recibe')
        ->toContain('Envío: $15.00 (6 km)')
        ->toContain('Producto a cobrar: $300.00')
        ->toContain('Quien recibe paga en total: $315.00')
        ->toContain('el repartidor te paga $300.00')
        // 300 de adelanto + 15 de envío + 30% de 15 por el regreso (RN-09).
        ->toContain('tú le pagas $319.50')
        ->toContain('todavía no se crea el pedido')
        ->not->toContain('me falta');

    expect(DB::connection('tenant')->table('pedidos')->count())->toBe(0);
});

it('pregunta todo lo que falta en un solo mensaje y no consulta a Google sin direcciones', function () {
    iaEntiende([
        'recogida_direccion' => '',
        'entrega_direccion' => '',
        'destinatario_nombre' => '',
        'destinatario_telefono' => '',
        'quien_paga_envio' => 'no_dicho',
        'hay_cobro_producto' => 'no_dicho',
        'importe_producto' => 0,
    ]);
    Http::fake();

    $respuesta = app(AsistentePedidoWhatsapp::class)->responder($this->conversacion, null);

    expect($respuesta)
        ->toContain('Para cotizar me falta:')
        ->toContain('Dónde recogemos')
        ->toContain('Dónde entregamos')
        ->toContain('El nombre de quien recibe')
        ->toContain('El teléfono de quien recibe')
        ->toContain('¿Quién paga el envío')
        ->toContain('¿Hay que cobrarle algo');

    Http::assertNothingSent();
});

it('no cotiza con una dirección imprecisa y la pregunta de nuevo', function () {
    iaEntiende(['recogida_direccion' => 'Centro de Celaya']);
    googleResponde([
        'Centro de Celaya' => [20.52, -100.81, 'APPROXIMATE'],
        'Morelos 20, Alameda, Celaya' => [20.53, -100.82],
    ]);

    $respuesta = app(AsistentePedidoWhatsapp::class)->responder($this->conversacion, null);

    expect($respuesta)
        ->toContain('No encontré con precisión «Centro de Celaya»')
        ->not->toContain('Envío:');

    Http::assertNotSent(fn (PeticionHttp $p) => str_contains($p->url(), '/directions/json'));
});

it('avisa cuando una dirección cae fuera de la cobertura', function () {
    ZonaServicio::create([
        'nombre' => 'Centro',
        'estado' => 'Activo',
        'poligono' => [
            ['lat' => 20.50, 'lng' => -100.83],
            ['lat' => 20.50, 'lng' => -100.80],
            ['lat' => 20.54, 'lng' => -100.80],
            ['lat' => 20.54, 'lng' => -100.83],
        ],
    ]);
    iaEntiende(['entrega_direccion' => 'Rancho Lejano 1, Celaya']);
    googleResponde(lugaresDeCelaya() + ['Rancho Lejano 1, Celaya' => [20.70, -100.60]]);

    $respuesta = app(AsistentePedidoWhatsapp::class)->responder($this->conversacion, null);

    expect($respuesta)
        ->toContain('está fuera de la zona donde damos servicio')
        ->not->toContain('Envío:');
});

it('sin una ruta real por calles no da precio', function () {
    iaEntiende([]);
    googleResponde(lugaresDeCelaya(), metros: null);

    $respuesta = app(AsistentePedidoWhatsapp::class)->responder($this->conversacion, '4613581090');

    expect($respuesta)
        ->toContain('no puedo calcular el precio')
        ->toContain('4613581090')
        ->not->toContain('$');
});

it('pasa a la flotilla el envío que paga quien envía con cobro de producto', function () {
    iaEntiende(['quien_paga_envio' => 'remitente']);
    Http::fake();

    $respuesta = app(AsistentePedidoWhatsapp::class)->responder($this->conversacion, '4613581090');

    expect($respuesta)->toContain('lo atiende la flotilla directamente')->toContain('4613581090');
    Http::assertNothingSent();
});

it('ofrece hablar con una persona', function () {
    iaEntiende(['intencion' => 'hablar_con_persona']);

    expect(app(AsistentePedidoWhatsapp::class)->responder($this->conversacion, '4613581090'))
        ->toContain('Puedes hablar con una persona de la flotilla al 4613581090');
});

it('si la IA falla contesta con una pregunta, nunca en silencio', function () {
    $this->mock(ExtractorPedidoIA::class)->shouldReceive('extraer')->andThrow(new ExtraccionFallida('caída'));

    expect(app(AsistentePedidoWhatsapp::class)->responder($this->conversacion, null))
        ->toContain('¿Me dices dónde recogemos el paquete y a dónde lo llevamos?');
});

it('le pasa a la IA la conversación reciente en orden, con lo que contestó el bot', function () {
    $id = $this->conversacion->id_conversacion;
    WhatsappMensaje::create(['id_conversacion' => $id, 'wamid' => 'wamid.VIEJO', 'direccion' => WhatsappMensaje::ENTRANTE,
        'tipo' => 'text', 'texto' => 'Un pedido de ayer', 'enviado_en' => now()->subHours(3)]);
    WhatsappMensaje::create(['id_conversacion' => $id, 'wamid' => 'wamid.BOT', 'direccion' => WhatsappMensaje::SALIENTE,
        'tipo' => 'text', 'texto' => '¿Quién recibe?', 'enviado_en' => now()->addSecond()]);
    WhatsappMensaje::create(['id_conversacion' => $id, 'wamid' => 'wamid.DOS', 'direccion' => WhatsappMensaje::ENTRANTE,
        'tipo' => 'text', 'texto' => 'Juan', 'enviado_en' => now()->addSeconds(2)]);

    $this->mock(ExtractorPedidoIA::class)
        ->shouldReceive('extraer')
        ->once()
        ->with([
            ['rol' => 'cliente', 'texto' => 'Quiero un envío'],
            ['rol' => 'negocio', 'texto' => '¿Quién recibe?'],
            ['rol' => 'cliente', 'texto' => 'Juan'],
        ])
        ->andThrow(new ExtraccionFallida('no importa aquí'));

    app(AsistentePedidoWhatsapp::class)->responder($this->conversacion, null);
});

it('la salida de la IA se vuelve a validar', function () {
    $extractor = new ExtractorPedidoIA(app(Client::class));

    $pedido = $extractor->interpretar(json_encode([
        'intencion' => 'crear_pedido_gratis',
        'recogida_direccion' => '  Hidalgo 10 ',
        'destinatario_telefono' => '+52 (461) 123-4567',
        'quien_paga_envio' => 'nadie',
        'hay_cobro_producto' => 'si',
        'importe_producto' => -50,
    ]));

    expect($pedido->intencion)->toBe(ExtraccionPedido::INTENCION_OTRA)
        ->and($pedido->recogidaDireccion)->toBe('Hidalgo 10')
        ->and($pedido->entregaDireccion)->toBe('')
        ->and($pedido->destinatarioTelefono)->toBe('524611234567')
        ->and($pedido->quienPagaEnvio)->toBe(ExtraccionPedido::NO_DICHO)
        ->and($pedido->importeProducto)->toBe(0.0);

    expect(fn () => $extractor->interpretar('esto no es JSON'))->toThrow(ExtraccionFallida::class);
});
