<?php

use App\Events\Tenant\ConductorColaReactivada;
use App\Events\Tenant\PedidoCanceladoParaConductor;
use App\Events\Tenant\PedidoCerradoPorIncidencia;
use App\Events\Tenant\PedidoDevuelto;
use App\Events\Tenant\PedidoDisponible;
use App\Events\Tenant\PedidoEstadoCambiado;
use App\Events\Tenant\PedidoYaTomado;
use App\Events\Tenant\SaldoAcreditado;
use App\Events\Tenant\SaldoCambiado;
use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\MovimientoSaldo;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\PedidoOferta;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\VentaViajeConductor;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Contrato con panda_express
|--------------------------------------------------------------------------
|
| Las demás pruebas del conductor comprueban que cada endpoint haga lo correcto. Estas comprueban
| otra cosa: que las respuestas y los eventos traigan, con el mismo nombre, cada campo que
| panda_express lee (src/stores/auth.js, src/composables/useRealtime.js, useTripManagement.js,
| useOrderPolling.js, useEarnings.js y las tarjetas de src/components/driver). Renombrar uno de
| esos campos aquí no rompe nada del backend, pero deja a la app mostrando pantallas vacías sin
| ningún error visible — por eso las listas de campos de abajo se copian de lo que la app usa, no
| de lo que el backend devuelve.
*/

uses(RefreshDatabase::class);

const CONTRATO_BASE = '/api/v1/t/cafe-luna';

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

/**
 * Tenant en Prepago con tarifas y un conductor con 3 viajes acreditados: lo mínimo para que el
 * conductor pueda ponerse ONLINE (spec tenant/019) y se liquide el viaje al entregar.
 *
 * @return array{tenant: Tenant, usuario: Usuario, conductor: Conductor}
 */
function contratoEscenario(): array
{
    $tenant = Tenant::create([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ]);

    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::BANDERAZO, '10');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_INCLUIDOS, '5');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_ADICIONAL, '5');
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, 'Prepago');
    ConfiguracionTenant::establecer(ConfiguracionTenant::COSTO_VIAJE_PREPAGO, '50');

    $usuario = Usuario::create([
        'nombre' => 'Beto',
        'apellido_paterno' => 'Salgado',
        'email' => 'beto@cafeluna.com',
        'password' => bcrypt('Password123!'),
        'rol' => 'Conductor',
        'estado' => 'Activo',
    ]);
    $conductor = Conductor::create([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'LIC-1',
        'estado' => 'ACTIVO',
        'disponibilidad' => 'DISPONIBLE',
    ]);
    VentaViajeConductor::create([
        'id_conductor' => $conductor->id_conductor,
        'cantidad_viajes' => 3,
        'monto_pagado' => 150,
        'id_usuario' => $usuario->id_usuario,
        'fecha_venta' => now(),
    ]);
    tenancy()->end();

    return ['tenant' => $tenant, 'usuario' => $usuario, 'conductor' => $conductor];
}

/**
 * Pedido PUBLICADO con una oferta vigente para el conductor: así lo ve en "Envíos disponibles".
 */
function contratoPedidoOfertado(Tenant $tenant, Conductor $conductor, string $numero = 'PED-100001'): Pedido
{
    tenancy()->initialize($tenant);
    $pedido = Pedido::create([
        'numero_pedido' => $numero,
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100',
        'latitud_recogida' => 19.4326,
        'longitud_recogida' => -99.1332,
        'direccion_entrega' => 'Av. Insurgentes 200',
        'latitud_entrega' => 19.4200,
        'longitud_entrega' => -99.1600,
        'fecha_servicio' => now()->toDateString(),
        'lo_antes_posible' => true,
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 80,
        'estado' => 'PUBLICADO',
        'fecha_publicacion' => now(),
    ]);
    PedidoOferta::create([
        'id_pedido' => $pedido->id_pedido,
        'id_conductor' => $conductor->id_conductor,
        'estado' => 'PENDIENTE',
        'ofrecida_en' => now(),
        'expira_en' => now()->addSeconds(45),
    ]);
    tenancy()->end();

    return $pedido;
}

/**
 * Activa el broadcaster "reverb" (firma local, sin red) para ejercer de verdad routes/channels.php;
 * phpunit.xml deja BROADCAST_CONNECTION=null, que no autoriza nada. Mismo truco que
 * ProtocoloTiempoRealTest.
 */
function contratoBroadcasterReal(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    require base_path('routes/channels.php');
}

it('serves every field panda_express reads along a full conductor shift', function () {
    $e = contratoEscenario();

    // src/stores/auth.js: lee `token` y `usuario` sin envolver en "data"; useRealtime.esMio() filtra
    // los eventos del canal por `usuario.id_conductor`, y push.js solo registra si `rol` es Conductor.
    $login = $this->postJson(CONTRATO_BASE.'/conductor/login', [
        'email' => 'beto@cafeluna.com',
        'password' => 'Password123!',
    ])->assertOk()
        ->assertJsonStructure(['token', 'usuario' => ['id_conductor', 'rol', 'nombre']]);

    expect($login->json('usuario.rol'))->toBe('Conductor');
    expect($login->json('usuario.id_conductor'))->toBe($e['conductor']->id_conductor);

    $this->withHeader('Authorization', 'Bearer '.$login->json('token'));

    $this->getJson(CONTRATO_BASE.'/conductor/me')
        ->assertOk()
        ->assertJsonStructure(['id_conductor', 'rol', 'nombre'])
        ->assertJsonPath('id_conductor', $e['conductor']->id_conductor);

    // useEarnings.js: `data.saldo`, número en Prepago.
    $this->getJson(CONTRATO_BASE.'/conductor/saldo-viajes')
        ->assertOk()
        ->assertJsonPath('saldo', 3);

    // useTripManagement.toggleOnline(): body { estado }.
    $this->postJson(CONTRATO_BASE.'/conductor/estado', ['estado' => 'ONLINE'])
        ->assertOk()
        ->assertJsonPath('estado', 'ONLINE');

    // push.js: { fcm_token }.
    $this->postJson(CONTRATO_BASE.'/conductor/dispositivo', ['fcm_token' => 'fcm-de-prueba'])
        ->assertNoContent();

    $pedido = contratoPedidoOfertado($e['tenant'], $e['conductor']);

    // useOrderPolling.js lee `res.data.data` y AvailableOrderCard.vue estos campos de cada fila.
    $this->getJson(CONTRATO_BASE.'/conductor/pedidos/disponibles')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure(['data' => [[
            'id_pedido', 'estado', 'nombre_solicitante', 'direccion_recogida', 'direccion_entrega',
            'latitud_recogida', 'longitud_recogida', 'latitud_entrega', 'longitud_entrega',
            'modalidad_pago', 'importe_envio',
        ]]])
        ->assertJsonPath('data.0.id_pedido', $pedido->id_pedido);

    // ActiveTripCard.vue (teléfono para WhatsApp/llamar) y useMapController (coordenadas, tramo).
    $camposActivo = [
        'id_pedido', 'estado', 'nombre_solicitante', 'telefono_solicitante',
        'direccion_recogida', 'direccion_entrega',
        'latitud_recogida', 'longitud_recogida', 'latitud_entrega', 'longitud_entrega',
        'modalidad_pago', 'importe_envio', 'seguimiento',
    ];

    $this->postJson(CONTRATO_BASE."/conductor/pedidos/{$pedido->id_pedido}/aceptar")
        ->assertOk()
        ->assertJsonStructure($camposActivo)
        ->assertJsonPath('estado', 'TOMADO');

    $this->getJson(CONTRATO_BASE.'/conductor/pedidos/activo')
        ->assertOk()
        ->assertJsonStructure($camposActivo)
        ->assertJsonPath('id_pedido', $pedido->id_pedido);

    // useRealtime.syncState(): `pedido_activo` y `saldo` en la raíz.
    $this->getJson(CONTRATO_BASE.'/conductor/sync')
        ->assertOk()
        ->assertJsonStructure(['pedido_activo' => $camposActivo, 'pedidos_disponibles', 'saldo'])
        ->assertJsonPath('pedido_activo.id_pedido', $pedido->id_pedido);

    // useRealTracking.syncLocation(): { latitud, longitud }.
    $this->postJson(CONTRATO_BASE.'/conductor/ubicacion', [
        'latitud' => 19.4300,
        'longitud' => -99.1400,
    ])->assertSuccessful();

    // useTripManagement.advanceStatus(): la misma secuencia de estados que recorre la app.
    foreach (['ARRIBADO', 'EN_CAMINO', 'ARRIBADO_A_ENTREGA', 'ENTREGADO'] as $estado) {
        $this->postJson(CONTRATO_BASE."/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => $estado])
            ->assertOk()
            ->assertJsonPath('estado', $estado);
    }

    // Tras entregar: sin viaje activo (la app espera literalmente `null`) y un viaje menos.
    expect($this->getJson(CONTRATO_BASE.'/conductor/pedidos/activo')->assertOk()->getContent())->toBe('null');
    $this->getJson(CONTRATO_BASE.'/conductor/saldo-viajes')->assertJsonPath('saldo', 2);
});

it('lets the conductor cancel its active pedido the way the app does', function () {
    $e = contratoEscenario();
    $token = $this->postJson(CONTRATO_BASE.'/conductor/login', [
        'email' => 'beto@cafeluna.com',
        'password' => 'Password123!',
    ])->json('token');
    $this->withHeader('Authorization', "Bearer {$token}");

    $pedido = contratoPedidoOfertado($e['tenant'], $e['conductor']);
    $this->postJson(CONTRATO_BASE."/conductor/pedidos/{$pedido->id_pedido}/aceptar")->assertOk();

    // useTripManagement.cancelTrip(): POST sin cuerpo.
    $this->postJson(CONTRATO_BASE."/conductor/pedidos/{$pedido->id_pedido}/cancelar")->assertSuccessful();

    expect($this->getJson(CONTRATO_BASE.'/conductor/pedidos/activo')->getContent())->toBe('null');
});

it('authorizes the private channel panda_express subscribes to with the conductor bearer token', function () {
    contratoBroadcasterReal();
    contratoEscenario();
    $token = $this->postJson(CONTRATO_BASE.'/conductor/login', [
        'email' => 'beto@cafeluna.com',
        'password' => 'Password123!',
    ])->json('token');

    // src/services/realtime.js: `private-tenant.${VITE_TENANT_SLUG}.conductores`, autorizado contra
    // `${VITE_API_BASE_URL}/conductor/broadcasting/auth` con el header Bearer.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(CONTRATO_BASE.'/conductor/broadcasting/auth', [
            'channel_name' => 'private-tenant.cafe-luna.conductores',
            'socket_id' => '123.456',
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);

    // Un build con el slug equivocado no debe poder escuchar a otro tenant.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(CONTRATO_BASE.'/conductor/broadcasting/auth', [
            'channel_name' => 'private-tenant.otra-empresa.conductores',
            'socket_id' => '123.456',
        ])
        ->assertForbidden();
});

it('rejects the conductor channel auth when the app has no token yet', function () {
    contratoBroadcasterReal();
    contratoEscenario();

    // Caso del primer arranque (realtime.js conecta antes del login): debe ser 401 para que la app
    // reintente la suscripción tras loguearse, no un 500 ni un 200.
    $this->withHeader('Authorization', 'Bearer ')
        ->postJson(CONTRATO_BASE.'/conductor/broadcasting/auth', [
            'channel_name' => 'private-tenant.cafe-luna.conductores',
            'socket_id' => '123.456',
        ])
        ->assertUnauthorized();
});

it('broadcasts each event panda_express listens to under the name and channel it expects', function () {
    $e = contratoEscenario();
    $pedido = contratoPedidoOfertado($e['tenant'], $e['conductor']);
    $idConductor = $e['conductor']->id_conductor;

    tenancy()->initialize($e['tenant']);
    $pedido->update(['id_conductor' => $idConductor, 'estado' => 'TOMADO']);

    $movimiento = new MovimientoSaldo([
        'id_conductor' => $idConductor,
        'tipo' => 'ABONO',
        'monto' => 100,
        'saldo_resultante' => 250,
        'referencia' => 'Prueba',
    ]);

    // Evento => campos que lee el handler correspondiente en src/composables/useRealtime.js.
    $contrato = [
        'pedido.disponible' => [new PedidoDisponible($pedido->fresh(), 'cafe-luna'), ['id_pedido', 'event_id']],
        'pedido.tomado' => [new PedidoYaTomado($pedido->id_pedido, 'cafe-luna'), ['id_pedido', 'event_id']],
        'pedido.estado-cambiado' => [
            new PedidoEstadoCambiado($pedido->id_pedido, $idConductor, 'ARRIBADO', 'cafe-luna'),
            ['id_pedido', 'id_conductor', 'estado', 'event_id'],
        ],
        'conductor.cola-reactivada' => [new ConductorColaReactivada($idConductor, 2, 'cafe-luna'), ['id_conductor', 'event_id']],
        'pedido.cancelado' => [
            new PedidoCanceladoParaConductor($pedido->id_pedido, 'cafe-luna', $idConductor),
            ['id_pedido', 'event_id'],
        ],
        'pedido.devuelto' => [
            new PedidoDevuelto($pedido->id_pedido, 'cafe-luna', $idConductor),
            ['id_pedido', 'event_id'],
        ],
        'pedido.incidencia' => [
            new PedidoCerradoPorIncidencia($pedido->id_pedido, 'cafe-luna', $idConductor, 'Choque'),
            ['id_pedido', 'event_id'],
        ],
        'saldo.acreditado' => [new SaldoAcreditado($idConductor, 5, 'cafe-luna'), ['id_conductor', 'viajes_acreditados', 'event_id']],
        'saldo.cambiado' => [new SaldoCambiado($movimiento, 'cafe-luna'), ['id_conductor', 'monto', 'saldo_resultante', 'event_id']],
    ];

    foreach ($contrato as $nombre => [$evento, $campos]) {
        expect($evento->broadcastAs())->toBe($nombre);
        expect(array_map(fn ($canal) => $canal->name, $evento->broadcastOn()))
            ->toBe(['private-tenant.cafe-luna.conductores']);

        $payload = $evento->broadcastWith();
        foreach ($campos as $campo) {
            expect($payload)->toHaveKey($campo, message: "{$nombre} no trae `{$campo}`");
        }

        // esMio() compara como string; con un id de otro conductor dejaría de filtrar.
        if (array_key_exists('id_conductor', $payload) && in_array('id_conductor', $campos, true)) {
            expect((string) $payload['id_conductor'])->toBe((string) $idConductor);
        }
    }

    tenancy()->end();
});

it('dispatches pedido.estado-cambiado when the conductor advances its own pedido', function () {
    Event::fake([PedidoEstadoCambiado::class]);

    $e = contratoEscenario();
    $token = $this->postJson(CONTRATO_BASE.'/conductor/login', [
        'email' => 'beto@cafeluna.com',
        'password' => 'Password123!',
    ])->json('token');
    $this->withHeader('Authorization', "Bearer {$token}");

    $pedido = contratoPedidoOfertado($e['tenant'], $e['conductor']);
    $this->postJson(CONTRATO_BASE."/conductor/pedidos/{$pedido->id_pedido}/aceptar")->assertOk();
    $this->postJson(CONTRATO_BASE."/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'ARRIBADO'])->assertOk();

    Event::assertDispatched(
        PedidoEstadoCambiado::class,
        fn ($ev) => $ev->idPedido === $pedido->id_pedido
            && $ev->idConductor === $e['conductor']->id_conductor
            && $ev->estado === 'ARRIBADO',
    );
});
