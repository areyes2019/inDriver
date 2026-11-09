<?php

use Anthropic\Client;
use App\Models\Tenant;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\WhatsappConversacion;
use App\Models\Tenant\WhatsappMensaje;
use App\Models\Tenant\WhatsappSolicitud;
use App\Models\Tenant\ZonaServicio;
use App\Services\Whatsapp\ConversacionWhatsappService;
use App\Services\Whatsapp\ExtraccionFallida;
use App\Services\Whatsapp\ExtraccionPedido;
use App\Services\Whatsapp\ExtractorPedidoIA;
use App\Services\Whatsapp\RespuestaBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// spec tenant/031, etapa 6: la conversación que arma, confirma y crea el envío. La IA se simula:
// aquí se prueba lo que Laravel hace con lo que ella diga.

beforeEach(function () {
    gc_collect_cycles();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }

    config([
        'services.google_maps.key' => 'llave-de-maps',
        // D17: en las pruebas siempre sale la primera versión de cada frase.
        'services.whatsapp.frases_al_azar' => false,
    ]);

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

    $this->conversacion = WhatsappConversacion::create(['usuario_whatsapp' => 'MX.BSUID1', 'telefono' => '5214613581090']);
    googleResponde(lugaresDeCelaya());
    fingirGoogle();
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

/**
 * La IA devuelve, en orden, lo que trae cada mensaje. Lo que no se indica, el mensaje no lo menciona.
 */
function iaDevuelve(array ...$mensajes): void
{
    $extracciones = array_map(fn (array $datos) => ExtraccionPedido::desdeArreglo(array_merge([
        'intencion' => 'pedido_envio',
        'quien_paga_envio' => 'no_dicho',
        'hay_cobro_producto' => 'no_dicho',
        'acepta_direccion_ofrecida' => 'no_dicho',
        'importe_producto' => 0,
    ], $datos)), $mensajes);

    test()->mock(ExtractorPedidoIA::class)->shouldReceive('extraer')->andReturn(...$extracciones);
}

/** Como lo hace el job: guarda lo que llega, lo atiende y anota la respuesta del bot. */
function clienteEscribe(WhatsappConversacion $conversacion, array $mensaje = []): RespuestaBot
{
    static $numero = 0;
    $numero++;

    $entrante = WhatsappMensaje::create(array_merge([
        'id_conversacion' => $conversacion->id_conversacion,
        'wamid' => "wamid.{$numero}",
        'direccion' => WhatsappMensaje::ENTRANTE,
        'tipo' => 'text',
        'texto' => 'mensaje del cliente',
        'enviado_en' => now(),
    ], $mensaje));

    $respuesta = app(ConversacionWhatsappService::class)->atender($conversacion->fresh(), collect([$entrante]), '4613581090');

    WhatsappMensaje::create([
        'id_conversacion' => $conversacion->id_conversacion,
        'wamid' => "wamid.bot.{$numero}",
        'direccion' => WhatsappMensaje::SALIENTE,
        'tipo' => $respuesta->botones === [] ? 'text' : 'interactive',
        'texto' => $respuesta->texto,
        'enviado_en' => now(),
    ]);

    return $respuesta;
}

function pulsa(WhatsappConversacion $conversacion, string $boton): RespuestaBot
{
    return clienteEscribe($conversacion, ['tipo' => 'interactive', 'texto' => ucfirst($boton), 'boton' => $boton]);
}

/**
 * Qué responde Google en esta prueba. Un solo `Http::fake` lee estos valores, porque un segundo
 * `Http::fake` no reemplaza al primero.
 *
 * @param  array<string, array{0: float, 1: float, 2?: string}>  $lugares  dirección => [lat, lng, location_type]
 */
function googleResponde(array $lugares, ?int $metros = 6000): void
{
    $GLOBALS['google_lugares'] = $lugares;
    $GLOBALS['google_metros'] = $metros;
}

function fingirGoogle(): void
{
    Http::fake(function (PeticionHttp $peticion) {
        $lugares = $GLOBALS['google_lugares'];
        $metros = $GLOBALS['google_metros'];

        if (str_contains($peticion->url(), '/geocode/json') && isset($peticion['latlng'])) {
            return Http::response(['status' => 'OK', 'results' => [['formatted_address' => 'Av. Tecnológico 50, Celaya, Gto.']]]);
        }

        if (str_contains($peticion->url(), '/geocode/json')) {
            $lugar = $lugares[$peticion['address']] ?? null;

            if ($lugar === null) {
                return Http::response(['status' => 'ZERO_RESULTS', 'results' => []]);
            }

            return Http::response(['status' => 'OK', 'results' => [[
                'formatted_address' => "{$peticion['address']}, Gto.",
                'geometry' => ['location' => ['lat' => $lugar[0], 'lng' => $lugar[1]], 'location_type' => $lugar[2] ?? 'ROOFTOP'],
            ]]]);
        }

        return $metros === null
            ? Http::response(['status' => 'ZERO_RESULTS', 'routes' => []])
            : Http::response(['status' => 'OK', 'routes' => [['legs' => [['distance' => ['value' => $metros]]]]]]);
    });
}

function lugaresDeCelaya(): array
{
    return [
        'Hidalgo 10, Centro' => [20.52, -100.81],
        'Morelos 20, Alameda' => [20.53, -100.82],
    ];
}

/** Todo lo del envío en un mensaje, contra entrega de $300 que paga quien recibe. */
function pedidoCompleto(array $cambios = []): array
{
    return array_merge([
        'destinatario_nombre' => 'Alan',
        'entrega_direccion' => 'Morelos 20, Alameda',
        'destinatario_telefono' => '461 123 4567',
        'recogida_direccion' => 'Hidalgo 10, Centro',
        'quien_paga_envio' => 'destinatario',
        'hay_cobro_producto' => 'si',
        'importe_producto' => 300,
    ], $cambios);
}

function solicitud(): WhatsappSolicitud
{
    return WhatsappSolicitud::latest('id_solicitud')->firstOrFail();
}

it('saluda y pide los datos en una lista corta si el primer mensaje no trae ninguno', function () {
    iaDevuelve(['intencion' => 'otra']);

    $respuesta = clienteEscribe($this->conversacion, ['texto' => 'Hola, buenas']);

    expect($respuesta->texto)->toStartWith('¡Hola!')
        ->toContain('Pásame estos datos')
        ->toContain('¿Quién recibe?')
        ->and($respuesta->botones)->toBe([]);
});

it('pregunta lo que falta de uno en uno y en orden, con el nombre de quien recibe', function () {
    iaDevuelve(['destinatario_nombre' => 'Alan'], ['entrega_direccion' => 'Morelos 20, Alameda']);

    $primera = clienteEscribe($this->conversacion, ['texto' => 'Le mando algo a Alan']);
    $segunda = clienteEscribe($this->conversacion, ['texto' => 'Morelos 20, Alameda']);

    expect($primera->texto)->toContain('Muy bien.')->toContain('¿En qué calle y número vive Alan, y en qué colonia?')
        ->and($segunda->texto)->not->toContain('¡Hola!')
        ->toContain('¿A qué número le marcamos a Alan si hace falta?')
        ->and(solicitud()->entrega_direccion)->toBe('Morelos 20, Alameda, Gto.');
});

it('arma el resumen con botones, registra al cliente nuevo y crea el pedido al confirmar', function () {
    iaDevuelve(pedidoCompleto(), ['nombre_cliente' => 'Abdias']);

    $pideNombre = clienteEscribe($this->conversacion, ['texto' => 'Todo el pedido']);
    $resumen = clienteEscribe($this->conversacion, ['texto' => 'Abdias']);

    expect($pideNombre->texto)->toContain('¿Cómo te llamas')
        ->and($resumen->botones)->toBe(['confirmar' => 'Confirmar', 'corregir' => 'Corregir', 'cancelar' => 'Cancelar'])
        ->and($resumen->texto)
        ->toContain('Recoger en: Hidalgo 10, Centro, Gto.')
        ->toContain('Recibe: Alan, 4611234567')
        ->toContain('Envío: $15.00 (6 km), lo paga Alan')
        ->toContain('Alan paga en total: $315.00')
        // 300 de adelanto + 15 de envío + 30% de 15 por el regreso (RN-09).
        ->toContain('tú le devuelves $319.50');

    $cliente = Cliente::sole();
    expect($cliente->nombre)->toBe('Abdias')->and($cliente->telefono)->toBe('4613581090');

    $confirmado = pulsa($this->conversacion, 'confirmar');
    $pedido = Pedido::withoutGlobalScopes()->sole();

    expect($confirmado->texto)->toContain("Tu envío quedó como {$pedido->numero_pedido}")
        ->and($pedido->id_cliente)->toBe($cliente->id_cliente)
        ->and($pedido->nombre_solicitante)->toBe('Alan')
        ->and($pedido->telefono_solicitante)->toBe('4611234567')
        ->and($pedido->modalidad_pago)->toBe('RECEPTOR_PAGA_ENVIO_PRODUCTOS')
        ->and((float) $pedido->importe_envio)->toBe(15.0)
        ->and((float) $pedido->importe_cobro)->toBe(300.0)
        ->and((bool) $pedido->lo_antes_posible)->toBeTrue()
        ->and(solicitud()->estado)->toBe(WhatsappSolicitud::CONFIRMADA)
        // RN-40: la recogida queda guardada para la próxima vez.
        ->and($cliente->direcciones()->sole()->calle)->toBe('Hidalgo 10, Centro, Gto.');
});

it('dos confirmaciones crean un solo pedido', function () {
    iaDevuelve(pedidoCompleto(), ['nombre_cliente' => 'Abdias']);
    clienteEscribe($this->conversacion, ['texto' => 'Todo el pedido']);
    clienteEscribe($this->conversacion, ['texto' => 'Abdias']);

    pulsa($this->conversacion, 'confirmar');
    $otra = pulsa($this->conversacion, 'confirmar');

    expect(Pedido::withoutGlobalScopes()->count())->toBe(1)
        ->and($otra->texto)->toContain('ya quedó confirmado como PED-000001');
});

it('sin ruta real por calles no da precio ni resumen', function () {
    googleResponde(lugaresDeCelaya(), metros: null);
    Cliente::create(['nombre' => 'Abdias', 'telefono' => '461 358 1090', 'estado' => 'Activo']);
    iaDevuelve(pedidoCompleto());

    $respuesta = clienteEscribe($this->conversacion, ['texto' => 'Todo el pedido']);

    expect($respuesta->texto)->toContain('no puedo calcular el precio')->toContain('4613581090')
        ->and($respuesta->botones)->toBe([]);
});

it('reconoce al cliente por su teléfono y le ofrece su última dirección de recogida', function () {
    $cliente = Cliente::create(['nombre' => 'Abdias', 'telefono' => '461-358-1090', 'estado' => 'Activo']);
    $cliente->direcciones()->create(['calle' => 'Real del Seminario 122', 'latitud' => 20.54, 'longitud' => -100.83]);
    iaDevuelve(pedidoCompleto(['recogida_direccion' => '', 'quien_paga_envio' => 'no_dicho']), ['acepta_direccion_ofrecida' => 'si']);

    $oferta = clienteEscribe($this->conversacion, ['texto' => 'Para Alan']);
    $siguiente = clienteEscribe($this->conversacion, ['texto' => 'Sí']);

    expect($oferta->texto)->toContain('¿Lo recogemos en Real del Seminario 122, como la vez pasada?')
        ->and($siguiente->texto)->toContain('¿Quién paga el envío, tú o Alan?')
        ->and(solicitud()->recogida_lat)->toBe(20.54)
        ->and($this->conversacion->fresh()->id_cliente)->toBe($cliente->id_cliente);
});

it('no adivina una dirección imprecisa y la vuelve a pedir', function () {
    googleResponde(['Centro de Celaya' => [20.52, -100.81, 'APPROXIMATE']]);
    iaDevuelve(['destinatario_nombre' => 'Alan', 'entrega_direccion' => 'Centro de Celaya']);

    $respuesta = clienteEscribe($this->conversacion, ['texto' => 'A Alan, en el centro']);

    expect($respuesta->texto)->toContain('No encontré bien «Centro de Celaya»')
        ->not->toContain('¿En qué calle y número vive')
        ->and(solicitud()->paso)->toBe('entrega')
        ->and(solicitud()->tieneEntrega())->toBeFalse();
});

it('avisa cuando la dirección cae fuera de la cobertura', function () {
    ZonaServicio::create(['nombre' => 'Centro', 'estado' => 'Activo', 'poligono' => [
        ['lat' => 20.50, 'lng' => -100.83], ['lat' => 20.50, 'lng' => -100.80],
        ['lat' => 20.54, 'lng' => -100.80], ['lat' => 20.54, 'lng' => -100.83],
    ]]);
    googleResponde(['Rancho Lejano 1' => [20.70, -100.60]]);
    iaDevuelve(['destinatario_nombre' => 'Alan', 'entrega_direccion' => 'Rancho Lejano 1']);

    expect(clienteEscribe($this->conversacion, ['texto' => 'A Alan, al rancho'])->texto)
        ->toContain('queda fuera de la zona donde damos servicio');
});

it('una ubicación compartida responde la dirección que se estaba preguntando, sin la IA', function () {
    iaDevuelve(['destinatario_nombre' => 'Alan']);
    clienteEscribe($this->conversacion, ['texto' => 'Para Alan']);

    $respuesta = clienteEscribe($this->conversacion, ['tipo' => 'location', 'texto' => null, 'latitud' => 20.531, 'longitud' => -100.821]);

    expect(solicitud()->entrega_lat)->toBe(20.531)
        ->and(solicitud()->entrega_direccion)->toBe('Av. Tecnológico 50, Celaya, Gto.')
        ->and($respuesta->texto)->toContain('¿A qué número le marcamos a Alan');
});

it('no admite que pague quien envía y además se cobre un producto', function () {
    Cliente::create(['nombre' => 'Abdias', 'telefono' => '4613581090', 'estado' => 'Activo']);
    iaDevuelve(pedidoCompleto(['quien_paga_envio' => 'remitente']));

    $respuesta = clienteEscribe($this->conversacion, ['texto' => 'Yo pago y le cobran 300']);

    expect($respuesta->texto)->toContain('¿Mejor que Alan pague el envío junto con el producto?')
        ->and(solicitud()->quien_paga)->toBeNull();
});

it('corregir vuelve a preguntar y el resumen sale con el cambio', function () {
    Cliente::create(['nombre' => 'Abdias', 'telefono' => '4613581090', 'estado' => 'Activo']);
    iaDevuelve(pedidoCompleto(), ['destinatario_nombre' => 'Beto']);
    clienteEscribe($this->conversacion, ['texto' => 'Todo el pedido']);

    $pregunta = pulsa($this->conversacion, 'corregir');
    $resumen = clienteEscribe($this->conversacion, ['texto' => 'Mejor lo recibe Beto']);

    expect($pregunta->texto)->toBe('Claro, ¿qué cambiamos?')
        ->and($resumen->texto)->toContain('Recibe: Beto')
        ->and($resumen->botones)->toHaveKey('confirmar');
});

it('cancelar descarta la hoja y el siguiente mensaje empieza de cero', function () {
    Cliente::create(['nombre' => 'Abdias', 'telefono' => '4613581090', 'estado' => 'Activo']);
    iaDevuelve(pedidoCompleto(), ['intencion' => 'otra']);
    clienteEscribe($this->conversacion, ['texto' => 'Todo el pedido']);

    expect(pulsa($this->conversacion, 'cancelar')->texto)->toContain('lo cancelé')
        ->and(solicitud()->estado)->toBe(WhatsappSolicitud::CANCELADA)
        ->and(clienteEscribe($this->conversacion, ['texto' => 'Otra cosa'])->texto)->toContain('Pásame estos datos')
        ->and(Pedido::withoutGlobalScopes()->count())->toBe(0);
});

it('con la flotilla en TEST no crea el pedido y lo avisa', function () {
    ConfiguracionTenant::establecer(ConfiguracionTenant::AMBIENTE, Pedido::AMBIENTE_TEST);
    Cliente::create(['nombre' => 'Abdias', 'telefono' => '4613581090', 'estado' => 'Activo']);
    iaDevuelve(pedidoCompleto());
    clienteEscribe($this->conversacion, ['texto' => 'Todo el pedido']);

    expect(pulsa($this->conversacion, 'confirmar')->texto)->toContain('no estamos tomando envíos')
        ->and(Pedido::withoutGlobalScopes()->count())->toBe(0);
});

it('un cliente inactivo no puede pedir', function () {
    Cliente::create(['nombre' => 'Abdias', 'telefono' => '4613581090', 'estado' => 'Inactivo']);

    expect(clienteEscribe($this->conversacion)->texto)->toContain('no puedo tomarte envíos por aquí')->toContain('4613581090');
});

it('una hoja sin actividad en 2 horas se descarta', function () {
    iaDevuelve(['destinatario_nombre' => 'Alan'], ['intencion' => 'otra']);
    clienteEscribe($this->conversacion, ['texto' => 'Para Alan']);
    WhatsappSolicitud::query()->update(['updated_at' => now()->subHours(3)]);

    expect(clienteEscribe($this->conversacion, ['texto' => 'Hola'])->texto)->toContain('Pásame estos datos')
        ->and(WhatsappSolicitud::oldest('id_solicitud')->first()->estado)->toBe(WhatsappSolicitud::DESCARTADA);
});

it('si la IA falla contesta con una pregunta, nunca en silencio', function () {
    $this->mock(ExtractorPedidoIA::class)->shouldReceive('extraer')->andThrow(new ExtraccionFallida('caída'));

    expect(clienteEscribe($this->conversacion)->texto)->toContain('no te entendí bien');
});

it('le pasa a la IA la hoja y la última pregunta, no todo el chat', function () {
    iaDevuelve(['destinatario_nombre' => 'Alan']);
    clienteEscribe($this->conversacion, ['texto' => 'Para Alan']);

    $this->mock(ExtractorPedidoIA::class)->shouldReceive('extraer')->once()
        ->withArgs(fn ($hoja, $pregunta, $mensaje) => $hoja === ['Quien recibe' => 'Alan']
            && str_ends_with($pregunta, '¿En qué calle y número vive Alan, y en qué colonia?')
            && $mensaje === 'Morelos 20')
        ->andThrow(new ExtraccionFallida('no importa aquí'));

    clienteEscribe($this->conversacion, ['texto' => 'Morelos 20']);
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
        ->and($pedido->destinatarioTelefono)->toBe('524611234567')
        ->and($pedido->quienPagaEnvio)->toBe(ExtraccionPedido::NO_DICHO)
        ->and($pedido->importeProducto)->toBe(0.0);

    expect(fn () => $extractor->interpretar('esto no es JSON'))->toThrow(ExtraccionFallida::class);
});
