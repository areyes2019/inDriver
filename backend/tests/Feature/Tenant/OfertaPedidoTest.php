<?php

use App\Events\Tenant\PedidoDisponible;
use App\Events\Tenant\PedidoRequiereAsignacionManual;
use App\Jobs\ExpirarOfertaPedido;
use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\PedidoOferta;
use App\Models\Tenant\Usuario;
use App\Services\OfertaPedidoService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

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

function ofertaTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function ofertaAdmin(Tenant $tenant): Usuario
{
    tenancy()->initialize($tenant);
    $admin = Usuario::create([
        'nombre' => 'Laura', 'apellido_paterno' => 'Torres', 'email' => 'laura@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'AdminCliente', 'estado' => 'Activo',
    ]);
    ConfiguracionTenant::establecer(ConfiguracionTenant::BANDERAZO, '10');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_INCLUIDOS, '5');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_ADICIONAL, '5');
    tenancy()->end();

    return $admin;
}

/**
 * @return array{usuario: Usuario, conductor: Conductor, password: string}
 */
function ofertaConductorConLogin(Tenant $tenant, string $email, array $conductorOverrides = []): array
{
    tenancy()->initialize($tenant);
    $password = 'Password123!';
    $usuario = Usuario::create([
        'nombre' => 'Conductor', 'apellido_paterno' => $email, 'email' => $email,
        'password' => bcrypt($password), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductor = Conductor::create(array_merge([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'LIC-'.$usuario->id_usuario,
        'estado' => 'ACTIVO',
        'disponibilidad' => 'DISPONIBLE',
    ], $conductorOverrides));
    tenancy()->end();

    return ['usuario' => $usuario, 'conductor' => $conductor, 'password' => $password];
}

function ofertaToken(string $email, string $password = 'Password123!'): string
{
    return test()->postJson('/api/v1/t/cafe-luna/conductor/login', [
        'email' => $email,
        'password' => $password,
    ])->assertOk()->json('token');
}

function ofertaPedidoPendiente(array $overrides = []): Pedido
{
    return Pedido::create(array_merge([
        'numero_pedido' => 'PED-'.random_int(100000, 999999),
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4326, 'longitud_recogida' => -99.1332,
        'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.4200, 'longitud_entrega' => -99.1600,
        'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true, 'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 80, 'estado' => 'PENDIENTE',
    ], $overrides));
}

it('creates one pending offer per eligible online conductor when publishing', function () {
    Bus::fake([ExpirarOfertaPedido::class]);
    Event::fake([PedidoDisponible::class]);

    $tenant = ofertaTenant();
    $admin = ofertaAdmin($tenant);
    $a = ofertaConductorConLogin($tenant, 'a@cafeluna.com');
    $b = ofertaConductorConLogin($tenant, 'b@cafeluna.com');
    ofertaConductorConLogin($tenant, 'c@cafeluna.com', ['disponibilidad' => 'FUERA_DE_SERVICIO']);

    tenancy()->initialize($tenant);
    $pedido = ofertaPedidoPendiente();
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'PUBLICADO'])
        ->assertOk();

    tenancy()->initialize($tenant);
    expect(PedidoOferta::count())->toBe(2);
    expect(PedidoOferta::where('id_conductor', $a['conductor']->id_conductor)->first()->estado)->toBe('PENDIENTE');
    expect(PedidoOferta::where('id_conductor', $b['conductor']->id_conductor)->first()->estado)->toBe('PENDIENTE');
    expect(Pedido::find($pedido->id_pedido)->veces_ofertado)->toBe(1);
    tenancy()->end();

    Event::assertDispatched(PedidoDisponible::class);
});

it('resolves a race between two conductores: winner ACEPTADA, loser PERDIDA and a 422', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = ofertaTenant();
    $admin = ofertaAdmin($tenant);
    $a = ofertaConductorConLogin($tenant, 'a@cafeluna.com');
    $b = ofertaConductorConLogin($tenant, 'b@cafeluna.com');

    tenancy()->initialize($tenant);
    $pedido = ofertaPedidoPendiente();
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'PUBLICADO'])
        ->assertOk();

    $tokenA = ofertaToken('a@cafeluna.com');
    $tokenB = ofertaToken('b@cafeluna.com');

    $this->withToken($tokenA)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/aceptar")
        ->assertOk();

    $this->withToken($tokenB)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/aceptar")
        ->assertUnprocessable();

    tenancy()->initialize($tenant);
    expect(PedidoOferta::where('id_conductor', $a['conductor']->id_conductor)->first()->estado)->toBe('ACEPTADA');
    expect(PedidoOferta::where('id_conductor', $b['conductor']->id_conductor)->first()->estado)->toBe('PERDIDA');
    expect(Pedido::find($pedido->id_pedido)->id_conductor)->toBe($a['conductor']->id_conductor);
    tenancy()->end();
});

it('does not offer to a conductor with an active pedido', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = ofertaTenant();
    $admin = ofertaAdmin($tenant);
    $ocupado = ofertaConductorConLogin($tenant, 'ocupado@cafeluna.com');
    $libre = ofertaConductorConLogin($tenant, 'libre@cafeluna.com');

    tenancy()->initialize($tenant);
    ofertaPedidoPendiente(['numero_pedido' => 'PED-OTRO', 'estado' => 'TOMADO', 'id_conductor' => $ocupado['conductor']->id_conductor]);
    $pedido = ofertaPedidoPendiente();
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'PUBLICADO'])
        ->assertOk();

    tenancy()->initialize($tenant);
    expect(PedidoOferta::where('id_conductor', $ocupado['conductor']->id_conductor)->exists())->toBeFalse();
    expect(PedidoOferta::where('id_conductor', $libre['conductor']->id_conductor)->exists())->toBeTrue();
    tenancy()->end();
});

it('lets a conductor reject an offer without penalty, leaving the other offer untouched', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = ofertaTenant();
    $admin = ofertaAdmin($tenant);
    $a = ofertaConductorConLogin($tenant, 'a@cafeluna.com');
    $b = ofertaConductorConLogin($tenant, 'b@cafeluna.com');

    tenancy()->initialize($tenant);
    $pedido = ofertaPedidoPendiente();
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'PUBLICADO'])
        ->assertOk();

    $tokenA = ofertaToken('a@cafeluna.com');

    $this->withToken($tokenA)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/rechazar")
        ->assertNoContent();

    tenancy()->initialize($tenant);
    expect(PedidoOferta::where('id_conductor', $a['conductor']->id_conductor)->first()->estado)->toBe('RECHAZADA');
    expect(PedidoOferta::where('id_conductor', $b['conductor']->id_conductor)->first()->estado)->toBe('PENDIENTE');
    tenancy()->end();
});

it('rejects rejecting an offer that was never made', function () {
    $tenant = ofertaTenant();
    $datos = ofertaConductorConLogin($tenant, 'a@cafeluna.com');

    tenancy()->initialize($tenant);
    $pedido = ofertaPedidoPendiente(['estado' => 'PUBLICADO']);
    tenancy()->end();

    $token = ofertaToken('a@cafeluna.com');

    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/rechazar")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['estado']);
});

it('re-offers on expiration excluding whoever rejected, and falls back to manual assignment after 3 attempts', function () {
    // Sin este fake, el propio `ofertar()` dispararía `ExpirarOfertaPedido` en línea
    // (QUEUE_CONNECTION=sync) y agotaría las 3 rondas de una sola vez, antes de que la prueba
    // pueda intercalar el rechazo — a diferencia de los tests que publican por HTTP, aquí el
    // pedido ya nace en PUBLICADO, así que el job no encuentra un estado viejo que lo frene.
    Bus::fake([ExpirarOfertaPedido::class]);
    Event::fake([PedidoRequiereAsignacionManual::class]);

    $tenant = ofertaTenant();
    $c1 = ofertaConductorConLogin($tenant, 'a@cafeluna.com');
    $c2 = ofertaConductorConLogin($tenant, 'b@cafeluna.com');

    tenancy()->initialize($tenant);
    $pedido = ofertaPedidoPendiente(['estado' => 'PUBLICADO', 'fecha_publicacion' => now()]);
    $servicio = app(OfertaPedidoService::class);

    $servicio->ofertar($pedido); // ronda 1: veces_ofertado = 1, ofrece a c1 y c2
    $servicio->rechazar($pedido, $c1['conductor']);

    $servicio->expirar($pedido); // cierra ronda 1, reoferta ronda 2 solo a c2 (veces_ofertado = 2)
    expect($pedido->fresh()->veces_ofertado)->toBe(2);
    expect(PedidoOferta::where('id_conductor', $c1['conductor']->id_conductor)->first()->estado)->toBe('RECHAZADA');
    expect(PedidoOferta::where('id_conductor', $c2['conductor']->id_conductor)->first()->estado)->toBe('PENDIENTE');

    $servicio->expirar($pedido); // cierra ronda 2, reoferta ronda 3 solo a c2 (veces_ofertado = 3)
    expect($pedido->fresh()->veces_ofertado)->toBe(3);
    expect(PedidoOferta::where('id_conductor', $c2['conductor']->id_conductor)->first()->estado)->toBe('PENDIENTE');

    $servicio->expirar($pedido); // cierra ronda 3 sin respuesta: se agotaron los 3 intentos
    $pedido->refresh();
    expect($pedido->estado)->toBe('PENDIENTE');
    tenancy()->end();

    Event::assertDispatched(PedidoRequiereAsignacionManual::class, fn ($event) => $event->idPedido === $pedido->id_pedido);
});

it('re-offers to the only conductor even after they rejected, on the final attempt', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = ofertaTenant();
    $unico = ofertaConductorConLogin($tenant, 'unico@cafeluna.com');

    tenancy()->initialize($tenant);
    $pedido = ofertaPedidoPendiente(['estado' => 'PUBLICADO', 'fecha_publicacion' => now()]);
    $servicio = app(OfertaPedidoService::class);

    $servicio->ofertar($pedido); // ronda 1
    $servicio->rechazar($pedido, $unico['conductor']);

    $servicio->expirar($pedido); // ronda 2: sin elegibles (único descartado, no es el último intento) -> nadie
    expect(PedidoOferta::where('id_conductor', $unico['conductor']->id_conductor)->first()->estado)->toBe('RECHAZADA');

    $servicio->expirar($pedido); // ronda 3 (última): reincluye al único disponible pese al rechazo previo
    expect(PedidoOferta::where('id_conductor', $unico['conductor']->id_conductor)->first()->estado)->toBe('PENDIENTE');
    expect($pedido->fresh()->estado)->toBe('PUBLICADO');
    tenancy()->end();
});

it('excludes telefono_solicitante from the pool listing before acceptance', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = ofertaTenant();
    $admin = ofertaAdmin($tenant);
    $datos = ofertaConductorConLogin($tenant, 'a@cafeluna.com');

    tenancy()->initialize($tenant);
    $pedido = ofertaPedidoPendiente();
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'PUBLICADO'])
        ->assertOk();

    $token = ofertaToken('a@cafeluna.com');

    $response = $this->withToken($token)
        ->getJson('/api/v1/t/cafe-luna/conductor/pedidos/disponibles')
        ->assertOk();

    expect($response->json('data.0'))->not->toHaveKey('telefono_solicitante');
    expect($response->json('data.0.nombre_solicitante'))->toBe('Mario Sánchez');
    expect($response->json('data.0.expira_en'))->not->toBeNull();
});
