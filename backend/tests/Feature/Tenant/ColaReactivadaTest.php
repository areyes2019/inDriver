<?php

use App\Events\Tenant\ConductorColaReactivada;
use App\Jobs\ExpirarOfertaPedido;
use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\PedidoOferta;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\VentaViajeConductor;
use App\Support\ColorConductor;
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

function colaTenant(): Tenant
{
    return Tenant::create([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ]);
}

function colaAdmin(Tenant $tenant): Usuario
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
 * Conductor en línea y con viajes prepagados: sin saldo no podría recibir la cola (spec
 * tenant/026, RN-07) y el escenario que se quiere probar ni siquiera arrancaría.
 */
function colaConductor(Tenant $tenant, string $email, int $viajes = 5): Conductor
{
    tenancy()->initialize($tenant);

    $usuario = Usuario::create([
        'nombre' => 'Conductor', 'apellido_paterno' => $email, 'email' => $email,
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);

    $conductor = Conductor::create([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'LIC-'.$usuario->id_usuario,
        'estado' => 'ACTIVO',
        'disponibilidad' => 'DISPONIBLE',
    ]);

    ConductorEstado::create([
        'id_conductor' => $conductor->id_conductor,
        'estado' => 'ONLINE',
        'ultima_conexion' => now(),
        'ultima_actualizacion' => now(),
    ]);

    if ($viajes > 0) {
        VentaViajeConductor::create([
            'id_conductor' => $conductor->id_conductor,
            'cantidad_viajes' => $viajes,
            'monto_pagado' => 100,
            'id_usuario' => Usuario::where('rol', 'AdminCliente')->value('id_usuario'),
            'fecha_venta' => now(),
        ]);
    }

    tenancy()->end();

    return $conductor;
}

function colaToken(string $email): string
{
    return test()->postJson('/api/v1/t/cafe-luna/conductor/login', [
        'email' => $email,
        'password' => 'Password123!',
    ])->assertOk()->json('token');
}

function colaPedido(array $overrides = []): Pedido
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

it('reoffers the queued pedidos to the conductor as soon as he delivers', function () {
    Bus::fake([ExpirarOfertaPedido::class]);
    Event::fake([ConductorColaReactivada::class]);

    $tenant = colaTenant();
    colaAdmin($tenant);
    $conductor = colaConductor($tenant, 'a@cafeluna.com');

    tenancy()->initialize($tenant);
    // El suyo, a punto de cerrarse. Los otros dos llevan rato publicados sin que nadie los tomara:
    // se le ofrecieron mientras estaba ocupado a nadie, y sus ofertas ya vencieron.
    $activo = colaPedido(['estado' => 'ARRIBADO_A_ENTREGA', 'id_conductor' => $conductor->id_conductor]);
    colaPedido(['numero_pedido' => 'PED-COLA-1', 'estado' => 'PUBLICADO', 'fecha_publicacion' => now()->subMinutes(20)]);
    colaPedido(['numero_pedido' => 'PED-COLA-2', 'estado' => 'PUBLICADO', 'fecha_publicacion' => now()->subMinutes(10)]);
    tenancy()->end();

    $token = colaToken('a@cafeluna.com');

    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$activo->id_pedido}/estado", ['estado' => 'ENTREGADO'])
        ->assertOk();

    $pool = $this->withToken($token)
        ->getJson('/api/v1/t/cafe-luna/conductor/pedidos/disponibles')
        ->assertOk()
        ->json('data');

    expect($pool)->toHaveCount(2);

    // RN-09: un solo aviso con el total, no uno por pedido.
    Event::assertDispatchedTimes(ConductorColaReactivada::class, 1);
    Event::assertDispatched(ConductorColaReactivada::class, fn ($e) => $e->total === 2
        && $e->idConductor === $conductor->id_conductor);
});

it('rescues a pedido that had fallen to manual assignment, with the round counter back to zero', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = colaTenant();
    colaAdmin($tenant);
    $conductor = colaConductor($tenant, 'a@cafeluna.com');

    tenancy()->initialize($tenant);
    $activo = colaPedido(['estado' => 'ARRIBADO_A_ENTREGA', 'id_conductor' => $conductor->id_conductor]);
    // Se ofertó 3 veces sin que nadie lo tomara y cayó a PENDIENTE (spec tenant/020, RN-04).
    $rescatable = colaPedido([
        'numero_pedido' => 'PED-MANUAL',
        'estado' => 'PENDIENTE',
        'fecha_publicacion' => now()->subHour(),
        'veces_ofertado' => 3,
    ]);
    // Borrador del despachador: nunca se publicó, así que no se toca (RN-03).
    $borrador = colaPedido(['numero_pedido' => 'PED-BORRADOR', 'estado' => 'PENDIENTE']);
    tenancy()->end();

    $this->withToken(colaToken('a@cafeluna.com'))
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$activo->id_pedido}/estado", ['estado' => 'ENTREGADO'])
        ->assertOk();

    tenancy()->initialize($tenant);
    $rescatado = Pedido::find($rescatable->id_pedido);
    expect($rescatado->estado)->toBe('PUBLICADO');
    // `ofertar()` cuenta su propia ronda sobre el contador ya reiniciado (RN-04).
    expect($rescatado->veces_ofertado)->toBe(1);
    expect(Pedido::find($borrador->id_pedido)->estado)->toBe('PENDIENTE');
    tenancy()->end();
});

it('does not bring back a pedido the conductor had explicitly rejected', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = colaTenant();
    colaAdmin($tenant);
    $conductor = colaConductor($tenant, 'a@cafeluna.com');

    tenancy()->initialize($tenant);
    $activo = colaPedido(['estado' => 'ARRIBADO_A_ENTREGA', 'id_conductor' => $conductor->id_conductor]);
    $rechazado = colaPedido(['numero_pedido' => 'PED-NO', 'estado' => 'PUBLICADO', 'fecha_publicacion' => now()->subHour()]);
    $perdido = colaPedido(['numero_pedido' => 'PED-SI', 'estado' => 'PUBLICADO', 'fecha_publicacion' => now()->subHour()]);

    PedidoOferta::create([
        'id_pedido' => $rechazado->id_pedido, 'id_conductor' => $conductor->id_conductor,
        'estado' => 'RECHAZADA', 'ofrecida_en' => now()->subHour(), 'expira_en' => now()->subHour()->addSeconds(45),
    ]);
    // Perder una carrera no es una decisión suya: ese sí se reabre (RN-06).
    PedidoOferta::create([
        'id_pedido' => $perdido->id_pedido, 'id_conductor' => $conductor->id_conductor,
        'estado' => 'PERDIDA', 'ofrecida_en' => now()->subHour(), 'expira_en' => now()->subHour()->addSeconds(45),
    ]);
    tenancy()->end();

    $token = colaToken('a@cafeluna.com');

    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$activo->id_pedido}/estado", ['estado' => 'ENTREGADO'])
        ->assertOk();

    $pool = $this->withToken($token)
        ->getJson('/api/v1/t/cafe-luna/conductor/pedidos/disponibles')
        ->assertOk()
        ->json('data');

    expect(collect($pool)->pluck('numero_pedido')->all())->toBe(['PED-SI']);
});

it('lists the pool from the oldest pedido to the newest', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = colaTenant();
    colaAdmin($tenant);
    $conductor = colaConductor($tenant, 'a@cafeluna.com');

    tenancy()->initialize($tenant);
    $activo = colaPedido(['estado' => 'ARRIBADO_A_ENTREGA', 'id_conductor' => $conductor->id_conductor]);
    colaPedido(['numero_pedido' => 'PED-NUEVO', 'estado' => 'PUBLICADO', 'fecha_publicacion' => now()])
        ->forceFill(['created_at' => now()->subMinute()])->save();
    colaPedido(['numero_pedido' => 'PED-VIEJO', 'estado' => 'PUBLICADO', 'fecha_publicacion' => now()])
        ->forceFill(['created_at' => now()->subHours(3)])->save();
    tenancy()->end();

    $token = colaToken('a@cafeluna.com');

    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$activo->id_pedido}/estado", ['estado' => 'ENTREGADO'])
        ->assertOk();

    $pool = $this->withToken($token)
        ->getJson('/api/v1/t/cafe-luna/conductor/pedidos/disponibles')
        ->assertOk()
        ->json('data');

    expect(collect($pool)->pluck('numero_pedido')->all())->toBe(['PED-VIEJO', 'PED-NUEVO']);
});

it('offers nothing to a conductor who went offline right after delivering', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = colaTenant();
    colaAdmin($tenant);
    $conductor = colaConductor($tenant, 'a@cafeluna.com');

    tenancy()->initialize($tenant);
    $conductor->update(['disponibilidad' => 'FUERA_DE_SERVICIO']);
    $activo = colaPedido(['estado' => 'ARRIBADO_A_ENTREGA', 'id_conductor' => $conductor->id_conductor]);
    colaPedido(['numero_pedido' => 'PED-COLA', 'estado' => 'PUBLICADO', 'fecha_publicacion' => now()->subHour()]);
    tenancy()->end();

    $this->withToken(colaToken('a@cafeluna.com'))
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$activo->id_pedido}/estado", ['estado' => 'ENTREGADO'])
        ->assertOk();

    tenancy()->initialize($tenant);
    expect(PedidoOferta::where('id_conductor', $conductor->id_conductor)->exists())->toBeFalse();
    tenancy()->end();
});

it('reactivates the queue when the conductor connects, not only when he delivers', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = colaTenant();
    colaAdmin($tenant);
    $conductor = colaConductor($tenant, 'a@cafeluna.com');

    tenancy()->initialize($tenant);
    $conductor->update(['disponibilidad' => 'FUERA_DE_SERVICIO']);
    ConductorEstado::where('id_conductor', $conductor->id_conductor)->update(['estado' => 'OFFLINE']);
    colaPedido(['numero_pedido' => 'PED-COLA', 'estado' => 'PUBLICADO', 'fecha_publicacion' => now()->subHour()]);
    tenancy()->end();

    $token = colaToken('a@cafeluna.com');

    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/estado', ['estado' => 'ONLINE'])
        ->assertOk();

    $pool = $this->withToken($token)
        ->getJson('/api/v1/t/cafe-luna/conductor/pedidos/disponibles')
        ->assertOk()
        ->json('data');

    expect(collect($pool)->pluck('numero_pedido')->all())->toBe(['PED-COLA']);
});

it('sends the H1 tramo dashed while going to pickup and the H2 tramo solid after arriving', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = colaTenant();
    colaAdmin($tenant);
    $conductor = colaConductor($tenant, 'a@cafeluna.com');

    tenancy()->initialize($tenant);
    $pedido = colaPedido(['estado' => 'TOMADO', 'id_conductor' => $conductor->id_conductor]);
    tenancy()->end();

    $token = colaToken('a@cafeluna.com');

    $h1 = $this->withToken($token)
        ->getJson('/api/v1/t/cafe-luna/conductor/pedidos/activo')
        ->assertOk()
        ->json('seguimiento');

    expect($h1['hito'])->toBe('H1');
    expect($h1['estilo'])->toBe('GUIONES');
    expect($h1['color'])->toBe(ColorConductor::para($conductor->id_conductor));
    // El origen de H1 es la posición viva del conductor, no una coordenada del servidor (RN-14).
    expect($h1['origen'])->toBeNull();
    expect($h1['destino'])->toBe(['lat' => 19.4326, 'lng' => -99.1332]);

    // El cambio de tramo es al LLEGAR a la recogida, no al iniciar el viaje (RN-12).
    $h2 = $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'ARRIBADO'])
        ->assertOk()
        ->json('seguimiento');

    expect($h2['hito'])->toBe('H2');
    expect($h2['estilo'])->toBe('SOLIDO');
    expect($h2['color'])->toBe($h1['color']);
    expect($h2['origen'])->toBe(['lat' => 19.4326, 'lng' => -99.1332]);
    expect($h2['destino'])->toBe(['lat' => 19.42, 'lng' => -99.16]);
});

it('stops sending a tramo once the pedido is delivered', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = colaTenant();
    colaAdmin($tenant);
    $conductor = colaConductor($tenant, 'a@cafeluna.com');

    tenancy()->initialize($tenant);
    $pedido = colaPedido(['estado' => 'ARRIBADO_A_ENTREGA', 'id_conductor' => $conductor->id_conductor]);
    tenancy()->end();

    $this->withToken(colaToken('a@cafeluna.com'))
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'ENTREGADO'])
        ->assertOk()
        ->assertJsonPath('seguimiento', null);
});

it('gives the panel a stable color per conductor and the tramo of his pedido', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = colaTenant();
    $admin = colaAdmin($tenant);
    $conductor = colaConductor($tenant, 'a@cafeluna.com');

    tenancy()->initialize($tenant);
    colaPedido(['estado' => 'EN_CAMINO', 'id_conductor' => $conductor->id_conductor]);
    tenancy()->end();

    $primera = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores/activos')
        ->assertOk()
        ->json('data.0');

    expect($primera['color'])->toBe(ColorConductor::para($conductor->id_conductor));
    expect($primera['pedido_asignado']['seguimiento']['hito'])->toBe('H2');
    expect($primera['pedido_asignado']['seguimiento']['estilo'])->toBe('SOLIDO');

    // El color no cambia entre consultas: se calcula, no se sortea (RN-17).
    $segunda = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/conductores/activos')
        ->assertOk()
        ->json('data.0');

    expect($segunda['color'])->toBe($primera['color']);
});
