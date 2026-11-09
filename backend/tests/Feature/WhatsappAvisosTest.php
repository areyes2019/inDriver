<?php

use App\Jobs\AvisarEstadoPedido;
use App\Jobs\ProcesarMensajeWhatsapp;
use App\Models\Tenant;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\WhatsappAviso;
use App\Models\Tenant\WhatsappConversacion;
use App\Models\Tenant\WhatsappMensaje;
use App\Models\Tenant\WhatsappSolicitud;
use App\Models\WhatsappNumero;
use App\Services\Whatsapp\ConversacionWhatsappService;
use App\Services\Whatsapp\FrasesBot;
use App\Services\Whatsapp\PlantillasWhatsapp;
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

// spec tenant/031, etapa 7a: avisos de estado, BAJA y plantillas.

beforeEach(function () {
    gc_collect_cycles();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }

    config([
        'services.whatsapp.graph_version' => 'v23.0',
        'services.whatsapp.frases_al_azar' => false,
    ]);

    $this->tenant = Tenant::create([
        'nombre_comercial' => 'Moto Uno',
        'razon_social' => 'Moto Uno SA de CV',
        'slug' => 'moto-uno',
        'estado' => 'Activo',
        'telefono' => '4613581090',
    ]);

    WhatsappNumero::create([
        'phone_number_id' => 'NUM-A',
        'id_tenant' => $this->tenant->getTenantKey(),
        'access_token' => 'token-moto-uno',
    ]);

    tenancy()->initialize($this->tenant);
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

function pedidoDePrueba(array $cambios = []): Pedido
{
    return Pedido::create(array_merge([
        'numero_pedido' => 'PED-000012',
        'estado' => 'PUBLICADO',
        'nombre_solicitante' => 'Alan',
        'telefono_solicitante' => '4611234567',
        'direccion_recogida' => 'Hidalgo 10, Celaya',
        'direccion_entrega' => 'Morelos 20, Celaya',
        'fecha_servicio' => '2026-11-09',
        'lo_antes_posible' => true,
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO_PRODUCTOS',
        'importe_envio' => 50,
        'importe_cobro' => 300,
    ], $cambios));
}

/** Un pedido que nació en WhatsApp, con el último mensaje del cliente hace `$horas`. */
function pedidoDeWhatsapp(float $horas = 1, ?bool $avisos = true): Pedido
{
    $cliente = Cliente::create(['nombre' => 'Abdias', 'telefono' => '4619998877', 'estado' => 'Activo']);

    if ($avisos !== null) {
        $cliente->cambiarAvisosWhatsapp($avisos, 'prueba');
    }

    $conversacion = WhatsappConversacion::create(['usuario_whatsapp' => 'MX.BSUID1', 'telefono' => '5214619998877', 'id_cliente' => $cliente->id_cliente]);
    WhatsappMensaje::create([
        'id_conversacion' => $conversacion->id_conversacion, 'wamid' => 'wamid.CLIENTE', 'direccion' => WhatsappMensaje::ENTRANTE,
        'tipo' => 'text', 'texto' => 'Confirmar', 'enviado_en' => now()->subMinutes((int) ($horas * 60)),
    ]);

    $pedido = pedidoDePrueba(['id_cliente' => $cliente->id_cliente]);
    WhatsappSolicitud::create(['id_conversacion' => $conversacion->id_conversacion, 'estado' => 'CONFIRMADA', 'id_pedido' => $pedido->id_pedido]);

    return $pedido;
}

function avisar(Pedido $pedido, string $estado): void
{
    (new AvisarEstadoPedido(test()->tenant, $pedido->id_pedido, $estado))->handle(app(WhatsappClient::class), app(FrasesBot::class));
}

function fingirMetaParaAvisos(): void
{
    Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.AVISO'.uniqid()]]])]);
}

it('encola el aviso cuando el pedido cambia a un estado que se avisa', function () {
    $pedido = pedidoDePrueba();
    Queue::fake();

    $pedido->forceFill(['estado' => 'TOMADO'])->save();
    $pedido->forceFill(['estado' => 'ARRIBADO'])->save();

    Queue::assertPushedOn(ProcesarMensajeWhatsapp::COLA, AvisarEstadoPedido::class);
    Queue::assertPushed(AvisarEstadoPedido::class, 1);
});

it('avisa con texto al cliente de un pedido de WhatsApp, una sola vez', function () {
    fingirMetaParaAvisos();
    $pedido = pedidoDeWhatsapp();

    avisar($pedido, 'TOMADO');
    avisar($pedido, 'TOMADO');

    Http::assertSentCount(1);
    Http::assertSent(fn (PeticionHttp $p) => $p['to'] === '5214619998877'
        && $p['text']['body'] === '🏍️ ¡Ya tenemos moto! Tu repartidor va por el paquete de tu envío PED-000012.');

    expect(WhatsappAviso::sole()->estado)->toBe(WhatsappAviso::ENVIADO)
        ->and(WhatsappMensaje::where('tipo', WhatsappMensaje::TIPO_AVISO)->count())->toBe(1);
});

it('en el regreso le dice al vendedor cuánto paga', function () {
    fingirMetaParaAvisos();
    $pedido = pedidoDeWhatsapp();
    $pedido->forceFill(['tarifa_regreso' => 15])->saveQuietly();

    avisar($pedido, 'EN_REGRESO');

    Http::assertSent(fn (PeticionHttp $p) => str_contains($p['text']['body'], 'Al recibirlo le pagas $365.00'));
});

it('no avisa con la ventana de 24 horas cerrada', function () {
    Http::fake();
    $pedido = pedidoDeWhatsapp(horas: 25);

    avisar($pedido, 'ENTREGADO');

    Http::assertNothingSent();
    expect(WhatsappAviso::sole()->motivo)->toBe('Ventana de 24 horas cerrada');
});

it('no avisa a quien escribió BAJA', function () {
    Http::fake();
    $pedido = pedidoDeWhatsapp(avisos: false);

    avisar($pedido, 'EN_CAMINO');

    Http::assertNothingSent();
    expect(WhatsappAviso::sole()->estado)->toBe(WhatsappAviso::OMITIDO);
});

it('avisa con plantilla un pedido del Panel solo al asignar y al entregar, si el cliente aceptó', function () {
    fingirMetaParaAvisos();
    $cliente = Cliente::create(['nombre' => 'Abdias', 'telefono' => '461 999 8877', 'estado' => 'Activo']);
    $cliente->cambiarAvisosWhatsapp(true, 'prueba');
    $pedido = pedidoDePrueba(['id_cliente' => $cliente->id_cliente]);

    avisar($pedido, 'TOMADO');
    avisar($pedido, 'EN_CAMINO');
    avisar($pedido, 'ENTREGADO');

    Http::assertSentCount(2);
    Http::assertSent(fn (PeticionHttp $p) => $p['to'] === '524619998877'
        && $p['type'] === 'template'
        && $p['template']['name'] === PlantillasWhatsapp::ASIGNADO
        && $p['template']['language']['code'] === 'es_MX'
        && array_column($p['template']['components'][0]['parameters'], 'text') === ['Abdias', 'PED-000012', 'Tu repartidor']);
    Http::assertSent(fn (PeticionHttp $p) => $p['template']['name'] === PlantillasWhatsapp::ENTREGADO);
});

it('no avisa un pedido del Panel sin permiso del cliente ni un envío de prueba', function () {
    Http::fake();
    $sinPermiso = Cliente::create(['nombre' => 'Beto', 'telefono' => '4611112233', 'estado' => 'Activo']);
    $conPermiso = Cliente::create(['nombre' => 'Abdias', 'telefono' => '4619998877', 'estado' => 'Activo']);
    $conPermiso->cambiarAvisosWhatsapp(true, 'prueba');

    avisar(pedidoDePrueba(['id_cliente' => $sinPermiso->id_cliente]), 'TOMADO');

    $deTest = pedidoDePrueba(['id_cliente' => $conPermiso->id_cliente, 'numero_pedido' => 'PED-000013']);
    $deTest->ambiente = Pedido::AMBIENTE_TEST;
    $deTest->saveQuietly();
    avisar($deTest, 'TOMADO');

    Http::assertNothingSent();
});

it('una plantilla rechazada se anota sin reintentar; una caída de Meta sí se reintenta', function () {
    $cliente = Cliente::create(['nombre' => 'Abdias', 'telefono' => '4619998877', 'estado' => 'Activo']);
    $cliente->cambiarAvisosWhatsapp(true, 'prueba');
    $pedido = pedidoDePrueba(['id_cliente' => $cliente->id_cliente]);

    Http::fakeSequence('graph.facebook.com/*')
        ->push(['error' => ['message' => 'Template not approved']], 400)
        ->push(['error' => ['message' => 'caído']], 500);

    avisar($pedido, 'TOMADO');
    expect(WhatsappAviso::sole()->estado)->toBe(WhatsappAviso::FALLIDO)
        ->and(WhatsappAviso::sole()->motivo)->toBe('Template not approved');

    expect(fn () => avisar($pedido, 'ENTREGADO'))->toThrow(RequestException::class);
    expect(WhatsappAviso::count())->toBe(1);
});

it('un aviso no cuenta como respuesta a lo que escribió el cliente', function () {
    fingirMetaParaAvisos();
    $pedido = pedidoDeWhatsapp(horas: 0);
    $conversacion = WhatsappConversacion::sole();
    $pendiente = WhatsappMensaje::create([
        'id_conversacion' => $conversacion->id_conversacion, 'wamid' => 'wamid.PREGUNTA', 'direccion' => WhatsappMensaje::ENTRANTE,
        'tipo' => 'text', 'texto' => '¿Ya viene?', 'enviado_en' => now(),
    ]);

    avisar($pedido, 'TOMADO');

    $this->mock(ConversacionWhatsappService::class)->shouldReceive('atender')->once()
        ->withArgs(fn ($c, $pendientes) => $pendientes->pluck('wamid')->contains('wamid.PREGUNTA'))
        ->andReturn(new RespuestaBot('Ya va en camino'));

    (new ProcesarMensajeWhatsapp($this->tenant, WhatsappNumero::sole()->id_numero, $pendiente->id_mensaje))
        ->handle(app(WhatsappClient::class), app(ConversacionWhatsappService::class));
});

it('crea las plantillas que faltan, guarda la cuenta y muestra su estado', function () {
    Http::fakeSequence('graph.facebook.com/*')
        ->push(['data' => [['name' => PlantillasWhatsapp::ASIGNADO, 'status' => 'APPROVED', 'language' => 'es_MX']]])
        ->push(['id' => '123', 'status' => 'PENDING'])
        ->push(['data' => [
            ['name' => PlantillasWhatsapp::ASIGNADO, 'status' => 'APPROVED', 'language' => 'es_MX'],
            ['name' => PlantillasWhatsapp::ENTREGADO, 'status' => 'PENDING', 'language' => 'es_MX'],
        ]]);
    tenancy()->end();

    $this->artisan('whatsapp:crear-plantillas', ['slug' => 'moto-uno', '--waba' => 'WABA-1'])
        ->expectsOutputToContain('Plantilla pedido_entregado_v1 enviada a revisión.')
        ->expectsTable(['Plantilla', 'Estado en Meta'], [
            [PlantillasWhatsapp::ASIGNADO, 'APPROVED'],
            [PlantillasWhatsapp::ENTREGADO, 'PENDING'],
        ])
        ->assertSuccessful();

    Http::assertSent(fn (PeticionHttp $p) => $p->method() === 'POST'
        && str_contains($p->url(), '/WABA-1/message_templates')
        && $p['name'] === PlantillasWhatsapp::ENTREGADO
        && $p['category'] === 'UTILITY'
        && $p['components'][0]['example']['body_text'] === [['Abdias', 'PED-000012']]);

    expect(WhatsappNumero::sole()->waba_id)->toBe('WABA-1');
});
