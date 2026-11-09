<?php

use App\Jobs\ProcesarMensajeWhatsapp;
use App\Models\Tenant;
use App\Models\Tenant\WhatsappConversacion;
use App\Models\Tenant\WhatsappMensaje;
use App\Models\WhatsappNumero;
use App\Services\Whatsapp\ConversacionWhatsappService;
use App\Services\Whatsapp\RespuestaBot;
use App\Services\Whatsapp\WhatsappClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const WEBHOOK_URL = '/api/v1/webhooks/whatsapp';

const RESPUESTA_ASISTENTE = 'Esto es lo que entendí';

beforeEach(function () {
    gc_collect_cycles();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }

    config([
        'services.whatsapp.app_secret' => 'secreto-de-la-app',
        'services.whatsapp.verify_token' => 'token-de-verificacion',
        'services.whatsapp.graph_version' => 'v23.0',
    ]);

    // La IA y la cotización tienen sus propias pruebas (WhatsappAsistenteTest): aquí solo importa el canal.
    $this->mock(ConversacionWhatsappService::class)->shouldReceive('atender')->andReturn(new RespuestaBot(RESPUESTA_ASISTENTE));
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

function flotillaConNumero(string $slug, string $idTelefono, array $datosTenant = []): Tenant
{
    $tenant = Tenant::create(array_merge([
        'nombre_comercial' => $slug,
        'razon_social' => "{$slug} SA de CV",
        'slug' => $slug,
        'estado' => 'Activo',
    ], $datosTenant));

    WhatsappNumero::create([
        'phone_number_id' => $idTelefono,
        'id_tenant' => $tenant->getTenantKey(),
        'access_token' => "token-{$slug}",
    ]);

    return $tenant;
}

function mensajeDeMeta(string $idTelefono, array $mensaje = [], array $contacto = []): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'WABA',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '524610000000', 'phone_number_id' => $idTelefono],
                    'contacts' => [array_merge(['user_id' => 'MX.BSUID1', 'wa_id' => '5214611234567', 'profile' => ['name' => 'María']], $contacto)],
                    'messages' => [array_merge([
                        'from' => '5214611234567',
                        'from_user_id' => 'MX.BSUID1',
                        'id' => 'wamid.UNO',
                        'timestamp' => (string) now()->timestamp,
                        'type' => 'text',
                        'text' => ['body' => 'Quiero un envío'],
                    ], $mensaje)],
                ],
            ]],
        ]],
    ];
}

function postFirmado($prueba, array $payload, ?string $firma = null)
{
    $cuerpo = json_encode($payload);

    return $prueba->call('POST', WEBHOOK_URL, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $firma ?? 'sha256='.hash_hmac('sha256', $cuerpo, 'secreto-de-la-app'),
    ], $cuerpo);
}

function fingirMeta(): void
{
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.RESPUESTA'.uniqid()]]])]);
}

it('responde el reto de Meta con el token correcto', function () {
    $this->get(WEBHOOK_URL.'?hub.mode=subscribe&hub.verify_token=token-de-verificacion&hub.challenge=12345')
        ->assertOk()
        ->assertSeeText('12345');
});

it('rechaza la verificación con un token incorrecto o sin configurar', function () {
    $this->get(WEBHOOK_URL.'?hub.mode=subscribe&hub.verify_token=otro&hub.challenge=12345')->assertForbidden();

    config(['services.whatsapp.verify_token' => '']);
    $this->get(WEBHOOK_URL.'?hub.mode=subscribe&hub.verify_token=&hub.challenge=12345')->assertForbidden();
});

it('no guarda nada si la firma es inválida', function (?string $firma) {
    $tenant = flotillaConNumero('moto-a', 'NUM-A');
    Queue::fake();

    postFirmado($this, mensajeDeMeta('NUM-A'), $firma ?? '')->assertForbidden();

    tenancy()->initialize($tenant);
    expect(WhatsappMensaje::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['firma falsa' => 'sha256=abc', 'sin firma' => [null]]);

it('no acepta nada si falta el secreto', function () {
    config(['services.whatsapp.app_secret' => '']);
    $cuerpo = json_encode(mensajeDeMeta('NUM-A'));

    $this->call('POST', WEBHOOK_URL, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $cuerpo, ''),
    ], $cuerpo)->assertForbidden();
});

it('guarda el mensaje, responde y registra la respuesta', function () {
    $tenant = flotillaConNumero('moto-a', 'NUM-A');
    fingirMeta();

    postFirmado($this, mensajeDeMeta('NUM-A'))->assertOk();

    Http::assertSentCount(1);
    Http::assertSent(fn (PeticionHttp $p) => str_contains($p->url(), '/v23.0/NUM-A/messages')
        && $p->hasHeader('Authorization', 'Bearer token-moto-a')
        && $p['to'] === '5214611234567'
        && $p['text']['body'] === RESPUESTA_ASISTENTE);

    tenancy()->initialize($tenant);

    $conversacion = WhatsappConversacion::sole();
    $entrante = WhatsappMensaje::where('wamid', 'wamid.UNO')->sole();

    expect($conversacion->usuario_whatsapp)->toBe('MX.BSUID1')
        ->and($conversacion->telefono)->toBe('5214611234567')
        ->and($conversacion->nombre_perfil)->toBe('María')
        ->and($entrante->texto)->toBe('Quiero un envío')
        ->and($entrante->respondido_en)->not->toBeNull()
        ->and(WhatsappMensaje::where('direccion', WhatsappMensaje::SALIENTE)->count())->toBe(1);
});

it('encola en su propia cola, sin esperar a Meta', function () {
    flotillaConNumero('moto-a', 'NUM-A');
    Queue::fake();
    Http::fake();

    postFirmado($this, mensajeDeMeta('NUM-A'))->assertOk();

    Queue::assertPushedOn(ProcesarMensajeWhatsapp::COLA, ProcesarMensajeWhatsapp::class);
    Http::assertNothingSent();
});

it('un reintento de Meta no se atiende dos veces', function () {
    $tenant = flotillaConNumero('moto-a', 'NUM-A');
    fingirMeta();

    postFirmado($this, mensajeDeMeta('NUM-A'))->assertOk();
    postFirmado($this, mensajeDeMeta('NUM-A'))->assertOk();

    Http::assertSentCount(1);

    tenancy()->initialize($tenant);
    expect(WhatsappMensaje::where('wamid', 'wamid.UNO')->count())->toBe(1);
});

it('cada número guarda solo en su flotilla', function () {
    $a = flotillaConNumero('moto-a', 'NUM-A');
    $b = flotillaConNumero('moto-b', 'NUM-B');
    fingirMeta();

    postFirmado($this, mensajeDeMeta('NUM-B', ['id' => 'wamid.DE-B']))->assertOk();

    Http::assertSent(fn (PeticionHttp $p) => $p->hasHeader('Authorization', 'Bearer token-moto-b'));

    tenancy()->initialize($a);
    expect(WhatsappMensaje::count())->toBe(0)
        ->and(WhatsappConversacion::count())->toBe(0);
    tenancy()->end();

    tenancy()->initialize($b);
    expect(WhatsappMensaje::where('wamid', 'wamid.DE-B')->count())->toBe(1);
});

it('un lote con dos números reparte cada mensaje a su flotilla', function () {
    $a = flotillaConNumero('moto-a', 'NUM-A');
    $b = flotillaConNumero('moto-b', 'NUM-B');
    fingirMeta();

    $lote = mensajeDeMeta('NUM-A', ['id' => 'wamid.A']);
    $lote['entry'][] = mensajeDeMeta('NUM-B', ['id' => 'wamid.B'])['entry'][0];
    postFirmado($this, $lote)->assertOk();

    Http::assertSentCount(2);

    tenancy()->initialize($a);
    expect(WhatsappMensaje::where('wamid', 'wamid.A')->exists())->toBeTrue()
        ->and(WhatsappMensaje::where('wamid', 'wamid.B')->exists())->toBeFalse();
    tenancy()->end();

    tenancy()->initialize($b);
    expect(WhatsappMensaje::where('wamid', 'wamid.B')->exists())->toBeTrue()
        ->and(WhatsappMensaje::where('wamid', 'wamid.A')->exists())->toBeFalse();
});

it('ignora un número sin conectar, inactivo o de una flotilla suspendida', function () {
    $activa = flotillaConNumero('moto-a', 'NUM-A');
    WhatsappNumero::where('phone_number_id', 'NUM-A')->update(['estado' => 'Inactivo']);
    $suspendida = flotillaConNumero('moto-b', 'NUM-B', ['estado' => 'Suspendido']);
    Queue::fake();

    postFirmado($this, mensajeDeMeta('NUM-DESCONOCIDO'))->assertOk();
    postFirmado($this, mensajeDeMeta('NUM-A'))->assertOk();
    postFirmado($this, mensajeDeMeta('NUM-B'))->assertOk();

    Queue::assertNothingPushed();

    foreach ([$activa, $suspendida] as $tenant) {
        tenancy()->initialize($tenant);
        expect(WhatsappMensaje::count())->toBe(0);
        tenancy()->end();
    }
});

it('atiende a quien no comparte su teléfono', function () {
    $tenant = flotillaConNumero('moto-a', 'NUM-A');
    fingirMeta();

    $payload = mensajeDeMeta('NUM-A');
    unset($payload['entry'][0]['changes'][0]['value']['messages'][0]['from']);
    unset($payload['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id']);
    postFirmado($this, $payload)->assertOk();

    Http::assertSent(fn (PeticionHttp $p) => $p['recipient'] === 'MX.BSUID1' && ! isset($p['to']));

    tenancy()->initialize($tenant);
    expect(WhatsappConversacion::sole()->telefono)->toBeNull();
});

it('guarda la ubicación compartida y el botón pulsado', function () {
    $tenant = flotillaConNumero('moto-a', 'NUM-A');
    Queue::fake();

    $ubicacion = mensajeDeMeta('NUM-A', ['id' => 'wamid.UBI', 'type' => 'location', 'location' => ['latitude' => 20.5, 'longitude' => -100.8]]);
    unset($ubicacion['entry'][0]['changes'][0]['value']['messages'][0]['text']);
    postFirmado($this, $ubicacion)->assertOk();

    $boton = mensajeDeMeta('NUM-A', ['id' => 'wamid.BOTON', 'type' => 'interactive', 'interactive' => [
        'type' => 'button_reply', 'button_reply' => ['id' => 'confirmar', 'title' => 'Confirmar'],
    ]]);
    unset($boton['entry'][0]['changes'][0]['value']['messages'][0]['text']);
    postFirmado($this, $boton)->assertOk();

    tenancy()->initialize($tenant);
    $guardada = WhatsappMensaje::where('wamid', 'wamid.UBI')->sole();
    $pulsado = WhatsappMensaje::where('wamid', 'wamid.BOTON')->sole();

    expect($guardada->latitud)->toBe(20.5)->and($guardada->longitud)->toBe(-100.8)->and($guardada->texto)->toBeNull()
        ->and($pulsado->boton)->toBe('confirmar')->and($pulsado->texto)->toBe('Confirmar');
});

it('manda los botones en el formato de Meta y guarda la respuesta', function () {
    $tenant = flotillaConNumero('moto-a', 'NUM-A');
    fingirMeta();
    $this->mock(ConversacionWhatsappService::class)->shouldReceive('atender')
        ->andReturn(new RespuestaBot('Así quedaría tu envío', ['confirmar' => 'Confirmar', 'corregir' => 'Corregir', 'cancelar' => 'Cancelar']));

    postFirmado($this, mensajeDeMeta('NUM-A'))->assertOk();

    Http::assertSent(fn (PeticionHttp $p) => $p['type'] === 'interactive'
        && $p['interactive']['body']['text'] === 'Así quedaría tu envío'
        && $p['interactive']['action']['buttons'][0] === ['type' => 'reply', 'reply' => ['id' => 'confirmar', 'title' => 'Confirmar']]
        && count($p['interactive']['action']['buttons']) === 3);

    tenancy()->initialize($tenant);
    expect(WhatsappMensaje::where('direccion', WhatsappMensaje::SALIENTE)->sole()->tipo)->toBe('interactive');
});

it('los avisos de estado actualizan el mensaje sin crear filas ni retroceder', function () {
    $tenant = flotillaConNumero('moto-a', 'NUM-A');
    Queue::fake();
    postFirmado($this, mensajeDeMeta('NUM-A'))->assertOk();

    $aviso = function (string $estado) {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => 'NUM-A'],
                'statuses' => [['id' => 'wamid.UNO', 'status' => $estado]],
            ],
        ]]]]];
    };

    postFirmado($this, $aviso('read'))->assertOk();
    postFirmado($this, $aviso('delivered'))->assertOk();

    tenancy()->initialize($tenant);
    expect(WhatsappMensaje::count())->toBe(1)
        ->and(WhatsappMensaje::sole()->estado_envio)->toBe('read');
});

it('si Meta falla no marca el mensaje como respondido y el reintento lo envía una vez', function () {
    $tenant = flotillaConNumero('moto-a', 'NUM-A');
    Queue::fake();
    postFirmado($this, mensajeDeMeta('NUM-A'))->assertOk();

    tenancy()->initialize($tenant);
    $mensaje = WhatsappMensaje::sole();
    $numero = WhatsappNumero::sole();
    $job = new ProcesarMensajeWhatsapp($tenant, $numero->id_numero, $mensaje->id_mensaje);

    Http::fakeSequence('graph.facebook.com/*')
        ->push(['error' => ['message' => 'caído']], 500)
        ->push(['messages' => [['id' => 'wamid.OK']]]);

    $asistente = app(ConversacionWhatsappService::class);

    expect(fn () => $job->handle(app(WhatsappClient::class), $asistente))->toThrow(RequestException::class);
    expect($mensaje->fresh()->respondido_en)->toBeNull();

    $job->handle(app(WhatsappClient::class), $asistente);
    $job->handle(app(WhatsappClient::class), $asistente);

    expect($mensaje->fresh()->respondido_en)->not->toBeNull();
    Http::assertSentCount(2);
});

it('varios mensajes seguidos reciben una sola respuesta (RN-34)', function () {
    $tenant = flotillaConNumero('moto-a', 'NUM-A');
    Queue::fake();
    postFirmado($this, mensajeDeMeta('NUM-A', ['id' => 'wamid.UNO', 'text' => ['body' => 'Quiero un envío']]))->assertOk();
    postFirmado($this, mensajeDeMeta('NUM-A', ['id' => 'wamid.DOS', 'text' => ['body' => 'a la calle Morelos 20']]))->assertOk();

    tenancy()->initialize($tenant);
    $numero = WhatsappNumero::sole();
    $uno = WhatsappMensaje::where('wamid', 'wamid.UNO')->sole();
    $dos = WhatsappMensaje::where('wamid', 'wamid.DOS')->sole();
    fingirMeta();
    $this->mock(ConversacionWhatsappService::class)->shouldReceive('atender')->once()
        ->withArgs(fn ($conversacion, $pendientes) => $pendientes->pluck('wamid')->all() === ['wamid.UNO', 'wamid.DOS'])
        ->andReturn(new RespuestaBot(RESPUESTA_ASISTENTE));

    // La cola los atiende en orden: el primero ve que hay uno más nuevo y le deja la respuesta.
    (new ProcesarMensajeWhatsapp($tenant, $numero->id_numero, $uno->id_mensaje))->handle(app(WhatsappClient::class), app(ConversacionWhatsappService::class));
    Http::assertNothingSent();
    expect($uno->fresh()->respondido_en)->not->toBeNull();

    (new ProcesarMensajeWhatsapp($tenant, $numero->id_numero, $dos->id_mensaje))->handle(app(WhatsappClient::class), app(ConversacionWhatsappService::class));
    Http::assertSentCount(1);
    expect(WhatsappMensaje::where('direccion', WhatsappMensaje::SALIENTE)->count())->toBe(1);
});

it('no responde un mensaje fuera de la ventana de 24 horas', function () {
    flotillaConNumero('moto-a', 'NUM-A');
    Http::fake();

    postFirmado($this, mensajeDeMeta('NUM-A', ['timestamp' => (string) now()->subHours(25)->timestamp]))->assertOk();

    Http::assertNothingSent();
});

it('el token queda cifrado en la base', function () {
    flotillaConNumero('moto-a', 'NUM-A');

    $guardado = DB::table('whatsapp_numeros')->where('phone_number_id', 'NUM-A')->value('access_token');

    expect($guardado)->not->toContain('token-moto-a')
        ->and(WhatsappNumero::sole()->access_token)->toBe('token-moto-a');
});

it('conecta un número y no permite robárselo a otra flotilla', function () {
    $a = flotillaConNumero('moto-a', 'NUM-A');
    Tenant::create(['nombre_comercial' => 'B', 'razon_social' => 'B', 'slug' => 'moto-b']);

    $this->artisan('whatsapp:conectar-numero', ['slug' => 'moto-b', 'phone_number_id' => 'NUM-NUEVO', '--telefono' => '524611112222'])
        ->expectsQuestion('Token de acceso de Meta', 'token-nuevo')
        ->assertSuccessful();

    expect(WhatsappNumero::where('phone_number_id', 'NUM-NUEVO')->sole()->access_token)->toBe('token-nuevo');

    $this->artisan('whatsapp:conectar-numero', ['slug' => 'moto-b', 'phone_number_id' => 'NUM-A'])->assertFailed();

    expect(WhatsappNumero::where('phone_number_id', 'NUM-A')->sole()->id_tenant)->toBe($a->getTenantKey());
});

it('borra el texto de los mensajes viejos y conserva la fila', function () {
    $tenant = flotillaConNumero('moto-a', 'NUM-A');
    Queue::fake();
    postFirmado($this, mensajeDeMeta('NUM-A'))->assertOk();
    postFirmado($this, mensajeDeMeta('NUM-A', ['id' => 'wamid.RECIENTE']))->assertOk();

    tenancy()->initialize($tenant);
    WhatsappMensaje::where('wamid', 'wamid.UNO')->update(['created_at' => now()->subDays(91)]);
    tenancy()->end();

    $this->artisan('whatsapp:purgar-mensajes')->assertSuccessful();

    tenancy()->initialize($tenant);
    expect(WhatsappMensaje::where('wamid', 'wamid.UNO')->sole()->texto)->toBeNull()
        ->and(WhatsappMensaje::where('wamid', 'wamid.RECIENTE')->sole()->texto)->toBe('Quiero un envío');
});
