<?php

use App\Events\Tenant\ConversacionWhatsappActualizada;
use App\Jobs\ProcesarMensajeWhatsapp;
use App\Models\Tenant;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\WhatsappAviso;
use App\Models\Tenant\WhatsappConversacion;
use App\Models\Tenant\WhatsappMensaje;
use App\Models\Tenant\WhatsappSolicitud;
use App\Models\WhatsappNumero;
use App\Services\Whatsapp\ConversacionWhatsappService;
use App\Services\Whatsapp\WhatsappClient;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// spec tenant/031, etapa 7b: la bandeja de conversaciones del Panel.

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);

    gc_collect_cycles();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }

    config(['services.whatsapp.graph_version' => 'v23.0']);

    $this->tenant = Tenant::create([
        'nombre_comercial' => 'Moto Uno',
        'razon_social' => 'Moto Uno SA de CV',
        'slug' => 'moto-uno',
        'estado' => 'Activo',
    ]);

    WhatsappNumero::create([
        'phone_number_id' => 'NUM-A',
        'id_tenant' => $this->tenant->getTenantKey(),
        'access_token' => 'token-moto-uno',
    ]);

    tenancy()->initialize($this->tenant);

    $this->despachador = Usuario::create([
        'nombre' => 'Laura', 'apellido_paterno' => 'Torres', 'email' => 'laura@motouno.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Despachador', 'estado' => 'Activo',
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

/** Una conversación con un mensaje del cliente de hace `$horas`. */
function conversacionConMensaje(string $usuario = 'MX.BSUID1', float $horas = 1, array $cambios = []): WhatsappConversacion
{
    $conversacion = WhatsappConversacion::create(array_merge(['usuario_whatsapp' => $usuario, 'telefono' => '5214619998877', 'nombre_perfil' => 'Abdi'], $cambios));
    WhatsappMensaje::create([
        'id_conversacion' => $conversacion->id_conversacion, 'wamid' => 'wamid.'.uniqid(), 'direccion' => WhatsappMensaje::ENTRANTE,
        'tipo' => 'text', 'texto' => 'Hola', 'enviado_en' => now()->subMinutes((int) ($horas * 60)),
    ]);

    return $conversacion;
}

function bandeja(string $ruta = ''): string
{
    return '/api/v1/t/moto-uno/whatsapp/conversaciones'.$ruta;
}

it('la ven el administrador y los despachadores, no un conductor', function () {
    $conductor = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@motouno.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);

    $this->actingAs($conductor, 'usuario')->getJson(bandeja())->assertForbidden();
    $this->actingAs($this->despachador, 'usuario')->getJson(bandeja())->assertOk();
});

it('ordena por el último mensaje, marca a quien pidió una persona y los cuenta', function () {
    $vieja = conversacionConMensaje('MX.A', 3, ['pide_persona_en' => now()]);
    $nueva = conversacionConMensaje('MX.B', 1);
    WhatsappConversacion::create(['usuario_whatsapp' => 'MX.SIN-MENSAJES']);

    $lista = $this->actingAs($this->despachador, 'usuario')->getJson(bandeja())->assertOk()->json('data');

    expect(array_column($lista, 'id_conversacion'))->toBe([$nueva->id_conversacion, $vieja->id_conversacion])
        ->and($lista[1]['pide_persona'])->toBeTrue()
        ->and($lista[0]['ultimo_mensaje']['texto'])->toBe('Hola');

    $this->actingAs($this->despachador, 'usuario')->getJson(bandeja('/esperando'))->assertExactJson(['esperando' => 1]);
});

it('muestra el historial con su autor, el folio de los avisos, ubicaciones y textos borrados', function () {
    $cliente = Cliente::create(['nombre' => 'Abdias Reyes', 'telefono' => '4619998877', 'estado' => 'Activo']);
    $conversacion = conversacionConMensaje(cambios: ['id_cliente' => $cliente->id_cliente]);
    $pedido = Pedido::create([
        'numero_pedido' => 'PED-000012', 'estado' => 'TOMADO', 'nombre_solicitante' => 'Abdias', 'telefono_solicitante' => '4619998877',
        'direccion_recogida' => 'Hidalgo 10', 'direccion_entrega' => 'Morelos 20', 'fecha_servicio' => '2026-11-09',
        'lo_antes_posible' => true, 'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO', 'importe_envio' => 50,
    ]);
    WhatsappSolicitud::create(['id_conversacion' => $conversacion->id_conversacion, 'estado' => 'CONFIRMADA', 'id_pedido' => $pedido->id_pedido]);
    $base = ['id_conversacion' => $conversacion->id_conversacion, 'enviado_en' => now()];
    WhatsappMensaje::create([...$base, 'wamid' => 'wamid.UBI', 'direccion' => 'ENTRANTE', 'tipo' => 'location', 'latitud' => 20.52, 'longitud' => -100.81]);
    WhatsappMensaje::create([...$base, 'wamid' => 'wamid.AVISO', 'direccion' => 'SALIENTE', 'tipo' => WhatsappMensaje::TIPO_AVISO, 'texto' => 'Ya tenemos moto']);
    WhatsappAviso::create(['id_pedido' => $pedido->id_pedido, 'momento' => 'asignado', 'estado' => 'ENVIADO', 'wamid' => 'wamid.AVISO']);
    WhatsappMensaje::create([...$base, 'wamid' => 'wamid.PERSONA', 'direccion' => 'SALIENTE', 'tipo' => 'text', 'texto' => 'Voy', 'id_usuario' => $this->despachador->id_usuario]);
    WhatsappMensaje::create([...$base, 'wamid' => 'wamid.VIEJO', 'direccion' => 'ENTRANTE', 'tipo' => 'text', 'texto' => null, 'enviado_en' => now()->subDays(91)]);

    $detalle = $this->actingAs($this->despachador, 'usuario')->getJson(bandeja("/{$conversacion->id_conversacion}"))->assertOk()->json();
    $mensajes = $detalle['mensajes'];

    expect($detalle['nombre'])->toBe('Abdias Reyes')
        ->and($detalle['ventana_abierta'])->toBeTrue()
        ->and($detalle['pedidos'])->toBe([['id_pedido' => $pedido->id_pedido, 'numero_pedido' => 'PED-000012', 'estado' => 'TOMADO']])
        ->and(array_column($mensajes, 'autor'))->toBe(['cliente', 'cliente', 'aviso', 'persona', 'cliente'])
        ->and($mensajes[1]['mapa_url'])->toBe('https://www.google.com/maps?q=20.52,-100.81')
        ->and($mensajes[2]['folio'])->toBe('PED-000012')
        ->and($mensajes[3]['nombre_usuario'])->toBe('Laura Torres')
        ->and($mensajes[4]['borrado'])->toBeTrue()
        ->and($mensajes[0]['borrado'])->toBeFalse();
});

it('contestar manda el texto, lo firma, calla al bot dos horas y quita la marca', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.PANEL']]])]);
    $conversacion = conversacionConMensaje(cambios: ['pide_persona_en' => now()]);

    $this->actingAs($this->despachador, 'usuario')
        ->postJson(bandeja("/{$conversacion->id_conversacion}/mensajes"), ['texto' => ' Ya va el repartidor '])
        ->assertCreated()
        ->assertJsonPath('autor', 'persona');

    Http::assertSent(fn (PeticionHttp $p) => $p['to'] === '5214619998877' && $p['text']['body'] === 'Ya va el repartidor');

    $conversacion->refresh();
    expect(WhatsappMensaje::where('wamid', 'wamid.PANEL')->sole()->id_usuario)->toBe($this->despachador->id_usuario)
        ->and($conversacion->pide_persona_en)->toBeNull()
        ->and($conversacion->botPausado())->toBeTrue()
        ->and(abs($conversacion->bot_pausado_hasta->diffInMinutes(now()->addHours(2))))->toBeLessThan(1.0);
});

it('fuera de las 24 horas no deja contestar', function () {
    Http::fake();
    $conversacion = conversacionConMensaje(horas: 25);

    $this->actingAs($this->despachador, 'usuario')
        ->postJson(bandeja("/{$conversacion->id_conversacion}/mensajes"), ['texto' => 'Hola'])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $m) => str_contains($m, '24 horas'));

    $this->actingAs($this->despachador, 'usuario')
        ->getJson(bandeja("/{$conversacion->id_conversacion}"))
        ->assertJsonPath('ventana_abierta', false);

    Http::assertNothingSent();
});

it('si Meta rechaza la respuesta no la guarda y lo dice', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'falló']], 400)]);
    $conversacion = conversacionConMensaje();

    $this->actingAs($this->despachador, 'usuario')
        ->postJson(bandeja("/{$conversacion->id_conversacion}/mensajes"), ['texto' => 'Hola'])
        ->assertStatus(502);

    expect(WhatsappMensaje::where('direccion', 'SALIENTE')->count())->toBe(0)
        ->and($conversacion->fresh()->botPausado())->toBeFalse();
});

it('devolver al bot lo reactiva antes de las dos horas', function () {
    $conversacion = conversacionConMensaje(cambios: ['pide_persona_en' => now(), 'bot_pausado_hasta' => now()->addHours(2)]);

    $this->actingAs($this->despachador, 'usuario')
        ->postJson(bandeja("/{$conversacion->id_conversacion}/devolver-al-bot"))
        ->assertOk()
        ->assertJsonPath('bot_pausado_hasta', null);

    $conversacion->refresh();
    expect($conversacion->botPausado())->toBeFalse()->and($conversacion->pide_persona_en)->toBeNull();
});

it('con el bot pausado, el mensaje del cliente queda atendido sin respuesta', function () {
    Http::fake();
    $conversacion = conversacionConMensaje(cambios: ['bot_pausado_hasta' => now()->addHour()]);
    $mensaje = $conversacion->mensajes()->sole();

    (new ProcesarMensajeWhatsapp($this->tenant, WhatsappNumero::sole()->id_numero, $mensaje->id_mensaje))
        ->handle(app(WhatsappClient::class), app(ConversacionWhatsappService::class));

    Http::assertNothingSent();
    expect($mensaje->fresh()->respondido_en)->not->toBeNull();
});

it('avisa al canal del Panel, no al de los conductores, cuando la conversación cambia', function () {
    Event::fake([ConversacionWhatsappActualizada::class]);
    $conversacion = conversacionConMensaje();
    Event::assertDispatchedTimes(ConversacionWhatsappActualizada::class, 1);

    $conversacion->update(['nombre_perfil' => 'Otro']);
    Event::assertDispatchedTimes(ConversacionWhatsappActualizada::class, 1);

    $conversacion->update(['pide_persona_en' => now()]);
    Event::assertDispatched(ConversacionWhatsappActualizada::class, function (ConversacionWhatsappActualizada $e) use ($conversacion) {
        return $e->idConversacion === $conversacion->id_conversacion
            && $e->broadcastOn()[0]->name === 'private-tenant.moto-uno.panel'
            && $e->broadcastWith() === ['id_conversacion' => $conversacion->id_conversacion, 'esperando' => 1];
    });
});
