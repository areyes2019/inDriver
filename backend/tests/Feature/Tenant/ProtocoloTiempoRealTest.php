<?php

use App\Events\Tenant\PedidoReprogramado;
use App\Events\Tenant\SaldoAcreditado;
use App\Events\Tenant\UbicacionActualizada;
use App\Models\Tenant;
use App\Models\Tenant\CompraPaquete;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorDispositivo;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

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

function protocoloTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function protocoloConfigurar(Tenant $tenant): void
{
    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::BANDERAZO, '10');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_INCLUIDOS, '5');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_ADICIONAL, '5');
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, 'Prepago');
    ConfiguracionTenant::establecer(ConfiguracionTenant::COSTO_VIAJE_PREPAGO, '50');
    tenancy()->end();
}

/**
 * @return array{usuario: Usuario, conductor: Conductor, password: string}
 */
function protocoloCrearConductor(Tenant $tenant, array $usuarioOverrides = []): array
{
    tenancy()->initialize($tenant);

    $password = 'Password123!';
    $usuario = Usuario::create(array_merge([
        'nombre' => 'Beto',
        'apellido_paterno' => 'Salgado',
        'email' => 'beto@cafeluna.com',
        'password' => bcrypt($password),
        'rol' => 'Conductor',
        'estado' => 'Activo',
    ], $usuarioOverrides));

    $conductor = Conductor::create([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'LIC-'.$usuario->id_usuario,
        'estado' => 'ACTIVO',
        'disponibilidad' => 'DISPONIBLE',
    ]);

    tenancy()->end();

    return ['usuario' => $usuario, 'conductor' => $conductor, 'password' => $password];
}

function protocoloAdminUsuario(Tenant $tenant): Usuario
{
    tenancy()->initialize($tenant);

    $admin = Usuario::create([
        'nombre' => 'Laura', 'apellido_paterno' => 'Torres', 'email' => 'laura@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'AdminCliente', 'estado' => 'Activo',
    ]);

    tenancy()->end();

    return $admin;
}

function protocoloConductorToken(string $email, string $password): string
{
    $response = test()->postJson('/api/v1/t/cafe-luna/conductor/login', [
        'email' => $email,
        'password' => $password,
    ])->assertOk();

    return $response->json('token');
}

/**
 * Misma llave de prueba que tests/Feature/FcmSenderTest.php — no es una credencial real.
 * `openssl_pkey_new()` no se usa aquí porque requiere un `openssl.cnf` que no siempre está
 * disponible en el entorno donde corren las pruebas (p. ej. PHP para Windows sin ese archivo
 * configurado).
 */
const PROTOCOLO_FCM_LLAVE_PRIVADA_PRUEBA = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDN9zV0e+0pB/qt
XoB0WdoEIzD9aysvnoWiHtD9BEyJRR9YWleUvsyGfe1Dw3keohsVz82gfm8K1iSO
PXfT0+P+8Ih2mq8Og12oPrpL3g3JaC33o8V+2VR9wH2jxbQ3qLtynjmRufl0FbPE
46FOrHD4eKWxFcgZYO2jw4ImLZbMaj5t9aQ7nYe4/x5hNbpCFxgk1GuXR0l0RKmG
BkoYiZiBDouX7xe13FD8ddp92yjP8uIP/BoZSIoXCsj133wDJTNN7C0fQUUzOc6/
10Pny1l+VOPeuxFiwD28XaO1NsAtXotLNYGRAj8D76kSlQhrox7aViyMn1+AYq8G
4KCeWXi1AgMBAAECggEAM92+ZNR2TwBW5ISpNWORDryr8A0mRWoWfdJjz2tfOKwi
7hVl+6umhnG8p3VYkVnCF1aKkhF0thZiAz3AaKPxxLferXtbfPygv6b4M/W5pA/r
j3J63+wrpjUsjmrRbLi9Z2on1iYuhsiWSg0GiHDNTAzZsMPq7VUm0rf/lMyjLltZ
Md9vCX3pidj61slemGwaPatGvFDcsfj1goTFI1Q/fw6T+oFk61qjiI0hAB0+zNw0
w/AQfGaDJhuKphLbY6krwfGLpUf8yaIF3xvmGL51tK83tMcICmKosd9u1jxd+On4
0O5OHveOC96BqixyyXpsrHr0XDlPQ1qZWgWiGb6eywKBgQDmXoBsD18Fv1czJG7k
HSZzGVLLB2rGKJHy74GBjEDRvpVpPsJU171GWNRSVS5bHjSy6KrOC6SB2z65H4hP
iABTIxZpNLcBEh6abvppYDJH6rWXh2d0Rq61oIxD5szrL2CPccs1ATkRJ8S8QqF1
dmz7s1IZyUv14JKWOl4iNrG6ewKBgQDk4aJEa6xG5O6KoBqKU1c24CCOMcnGPVzc
9PdQEtX/xM562Zd94e+vgwXEyn2uBnP9MAmyDVSeURWNlxNHZs6bknUSGzzOfEm1
dzA2EB3vsFIFEmtTj7rFyIhfRrpdyXJRtXzloOSIus0EBeRncMKaRPubNdbqIzNS
s+h9d+SKjwKBgBB2EkEmfAjCGm4KHW5pctToq1Tcq9GLFprAaIWkSwFx1+VUWbiM
TfcX49waQBy8tNFP9NySUmgBDaNW0Hu2YSePq0tLPAR0kgFBCt26xP0ElYNFZqwV
XOiXl05G0L/Be+nkHLwl4TkLmXBGZpkpJDJ8JtK24pmoOXFIrG9PbzW/AoGBANbq
T5XzjMbc/GhKweEVNJWgirE6av6sa+BGXVtg9HS/9ipA2xEm8Atb+jS49p5MDOm3
C8OW5Nfrx1M2grHPBT3rneYskUJKTmQI0MpTA+knJT0B+Kl0EqrZC8R7A1BBcgjr
Y6WzGCSTUyLt7XR72x9EmwU43t7nwq9ro2j9BSpdAoGBAJzWdml6sO2FnVrHtMGM
77+kH0s4sDDSHB7ES87trWUfG2bbC8e7ktkuwpCbVhwJrpn4XvVvVjdJ+TuMl5e0
G8lvF1oTA8vmjhB5E3Bflaa5nsSMeuepLFhrczw1HDyK15+9JkzF0HQzDT00ZSbx
cWcLyhQTH/1lsPBkvfhAQTHt
-----END PRIVATE KEY-----
PEM;

function protocoloFcmCredenciales(): string
{
    $ruta = sys_get_temp_dir().'/fcm-credenciales-'.uniqid().'.json';
    File::put($ruta, json_encode([
        'client_email' => 'panda-express@demo-project.iam.gserviceaccount.com',
        'private_key' => PROTOCOLO_FCM_LLAVE_PRIVADA_PRUEBA,
    ]));

    return $ruta;
}

it('registers a device token and overwrites it on a second login', function () {
    $tenant = protocoloTenant();
    $datos = protocoloCrearConductor($tenant);
    $token = protocoloConductorToken('beto@cafeluna.com', 'Password123!');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/t/cafe-luna/conductor/dispositivo', ['fcm_token' => 'token-uno'])
        ->assertNoContent();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/t/cafe-luna/conductor/dispositivo', ['fcm_token' => 'token-dos'])
        ->assertNoContent();

    tenancy()->initialize($tenant);
    $dispositivo = ConductorDispositivo::where('id_conductor', $datos['conductor']->id_conductor)->first();
    expect(ConductorDispositivo::where('id_conductor', $datos['conductor']->id_conductor)->count())->toBe(1);
    expect($dispositivo->fcm_token)->toBe('token-dos');
    expect($dispositivo->updated_at)->not->toBeNull();
    tenancy()->end();
});

it('syncs pedido activo, pedidos disponibles, and saldo in one response', function () {
    $tenant = protocoloTenant();
    protocoloConfigurar($tenant);
    $datos = protocoloCrearConductor($tenant);
    $token = protocoloConductorToken('beto@cafeluna.com', 'Password123!');

    tenancy()->initialize($tenant);
    $activo = Pedido::create([
        'numero_pedido' => 'PED-100001', 'nombre_solicitante' => 'Mario', 'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'direccion_entrega' => 'Av. Insurgentes 200',
        'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true,
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO', 'importe_envio' => 80, 'estado' => 'TOMADO',
        'id_conductor' => $datos['conductor']->id_conductor,
    ]);
    Pedido::create([
        'numero_pedido' => 'PED-100002', 'nombre_solicitante' => 'Ana', 'telefono_solicitante' => '5511223355',
        'direccion_recogida' => 'Av. Juárez 50', 'direccion_entrega' => 'Av. Chapultepec 80',
        'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true,
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO', 'importe_envio' => 60, 'estado' => 'PUBLICADO',
        'fecha_publicacion' => now(),
    ]);
    tenancy()->end();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/t/cafe-luna/conductor/sync')
        ->assertOk();

    expect($response->json('pedido_activo.id_pedido'))->toBe($activo->id_pedido);
    expect($response->json('pedidos_disponibles'))->toHaveCount(1);
    expect($response->json('saldo'))->toBe(0);
});

it("authorizes the panel's own tenant channel and rejects another tenant's", function () {
    // BROADCAST_CONNECTION=null en pruebas (phpunit.xml) no ejecuta la autorización real de
    // canales — se fuerza el driver "reverb" (protocolo Pusher, firma local, sin red) solo aquí
    // para ejercer de verdad el callback de routes/channels.php. routes/channels.php ya corrió una
    // vez en el arranque contra el broadcaster "null" (cacheado, sin canales registrados); se
    // vuelve a requerir para registrar el canal en el broadcaster "reverb" recién creado.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    require base_path('routes/channels.php');

    $tenant = protocoloTenant();
    $admin = protocoloAdminUsuario($tenant);

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/broadcasting/auth', [
            'channel_name' => 'private-tenant.cafe-luna.conductores',
            'socket_id' => '123.456',
        ])
        ->assertOk();

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/broadcasting/auth', [
            'channel_name' => 'private-tenant.otra-empresa.conductores',
            'socket_id' => '123.456',
        ])
        ->assertForbidden();
});

it('rejects broadcasting auth for the panel without a session', function () {
    protocoloTenant();

    $this->postJson('/api/v1/t/cafe-luna/broadcasting/auth', [
        'channel_name' => 'private-tenant.cafe-luna.conductores',
        'socket_id' => '123.456',
    ])->assertUnauthorized();
});

it('dispatches PedidoReprogramado when the schedule of an assigned pedido changes', function () {
    Event::fake([PedidoReprogramado::class]);

    $tenant = protocoloTenant();
    protocoloConfigurar($tenant);
    $admin = protocoloAdminUsuario($tenant);
    $datos = protocoloCrearConductor($tenant);

    tenancy()->initialize($tenant);
    $pedido = Pedido::create([
        'numero_pedido' => 'PED-200001', 'nombre_solicitante' => 'Mario', 'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'direccion_entrega' => 'Av. Insurgentes 200',
        'fecha_servicio' => '2026-01-01', 'lo_antes_posible' => false,
        'hora_desde' => '09:00', 'hora_hasta' => '11:00',
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO', 'importe_envio' => 80, 'estado' => 'TOMADO',
        'id_conductor' => $datos['conductor']->id_conductor,
    ]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}", [
            'nombre_solicitante' => 'Mario',
            'telefono_solicitante' => '5511223344',
            'direccion_recogida' => 'Av. Reforma 100',
            'direccion_entrega' => 'Av. Insurgentes 200',
            'fecha_servicio' => '2026-01-02',
            'lo_antes_posible' => false,
            'hora_desde' => '09:00',
            'hora_hasta' => '11:00',
            'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
            'importe_envio' => 80,
        ])
        ->assertOk();

    Event::assertDispatched(PedidoReprogramado::class, fn ($event) => $event->idPedido === $pedido->id_pedido
        && $event->idConductor === $datos['conductor']->id_conductor);
});

it('does not dispatch PedidoReprogramado when the schedule does not change', function () {
    Event::fake([PedidoReprogramado::class]);

    $tenant = protocoloTenant();
    protocoloConfigurar($tenant);
    $admin = protocoloAdminUsuario($tenant);
    $datos = protocoloCrearConductor($tenant);

    tenancy()->initialize($tenant);
    $pedido = Pedido::create([
        'numero_pedido' => 'PED-200002', 'nombre_solicitante' => 'Mario', 'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'direccion_entrega' => 'Av. Insurgentes 200',
        'fecha_servicio' => '2026-01-01', 'lo_antes_posible' => false,
        'hora_desde' => '09:00', 'hora_hasta' => '11:00',
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO', 'importe_envio' => 80, 'estado' => 'TOMADO',
        'id_conductor' => $datos['conductor']->id_conductor,
    ]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}", [
            'nombre_solicitante' => 'Mario editado',
            'telefono_solicitante' => '5511223344',
            'direccion_recogida' => 'Av. Reforma 100',
            'direccion_entrega' => 'Av. Insurgentes 200',
            'fecha_servicio' => '2026-01-01',
            'lo_antes_posible' => false,
            'hora_desde' => '09:00',
            'hora_hasta' => '11:00',
            'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
            'importe_envio' => 80,
        ])
        ->assertOk();

    Event::assertNotDispatched(PedidoReprogramado::class);
});

it('dispatches SaldoAcreditado when trips are credited to a conductor', function () {
    Event::fake([SaldoAcreditado::class]);

    $tenant = protocoloTenant();
    $admin = protocoloAdminUsuario($tenant);
    $datos = protocoloCrearConductor($tenant);

    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, 'Prepago');
    ConfiguracionTenant::establecer(ConfiguracionTenant::COSTO_VIAJE_PREPAGO, '100');
    CompraPaquete::create([
        'codigo_paquete' => 'PAQ-1', 'cantidad_paquetes' => 1, 'cantidad_viajes' => 50,
        'precio_unitario' => 0, 'importe_total' => 0, 'estado' => 'Activo', 'fecha_compra' => now(),
    ]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$datos['conductor']->id_conductor}/vender-viajes", [
            'monto_pagado' => 500,
        ])
        ->assertCreated();

    Event::assertDispatched(SaldoAcreditado::class, fn ($event) => $event->idConductor === $datos['conductor']->id_conductor
        && $event->viajesAcreditados === 5);
});

it('sends an FCM push for a critical event when the conductor has a registered device', function () {
    $ruta = protocoloFcmCredenciales();
    config(['services.fcm.project_id' => 'demo-project', 'services.fcm.credentials_path' => $ruta]);
    Cache::flush();

    $tenant = protocoloTenant();
    $admin = protocoloAdminUsuario($tenant);
    $datos = protocoloCrearConductor($tenant);
    $token = protocoloConductorToken('beto@cafeluna.com', 'Password123!');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/t/cafe-luna/conductor/dispositivo', ['fcm_token' => 'token-de-beto'])
        ->assertNoContent();

    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, 'Prepago');
    ConfiguracionTenant::establecer(ConfiguracionTenant::COSTO_VIAJE_PREPAGO, '100');
    CompraPaquete::create([
        'codigo_paquete' => 'PAQ-1', 'cantidad_paquetes' => 1, 'cantidad_viajes' => 50,
        'precio_unitario' => 0, 'importe_total' => 0, 'estado' => 'Activo', 'fecha_compra' => now(),
    ]);
    tenancy()->end();

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fake-token']),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/demo-project/messages/1']),
    ]);

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$datos['conductor']->id_conductor}/vender-viajes", [
            'monto_pagado' => 500,
        ])
        ->assertCreated();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com/v1/projects/demo-project/messages:send')
        && $request['message']['token'] === 'token-de-beto'
        && $request['message']['data']['tipo'] === 'saldo.acreditado');

    File::delete($ruta);
});

it('dispatches UbicacionActualizada when the conductor sends its location', function () {
    Event::fake([UbicacionActualizada::class]);

    $tenant = protocoloTenant();
    protocoloConfigurar($tenant);
    $datos = protocoloCrearConductor($tenant);
    $token = protocoloConductorToken('beto@cafeluna.com', 'Password123!');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/t/cafe-luna/conductor/ubicacion', [
            'latitud' => 19.4326,
            'longitud' => -99.1332,
        ])
        ->assertNoContent();

    Event::assertDispatched(UbicacionActualizada::class, fn ($event) => $event->idConductor === $datos['conductor']->id_conductor
        && $event->latitud === 19.4326
        && $event->longitud === -99.1332);
});
