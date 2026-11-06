<?php

use App\Events\Tenant\ConductorColaReactivada;
use App\Events\Tenant\PedidoCanceladoParaConductor;
use App\Events\Tenant\PedidoCanceladoSinConductor;
use App\Events\Tenant\PedidoCerradoPorIncidencia;
use App\Events\Tenant\PedidoDevuelto;
use App\Events\Tenant\PedidoDireccionActualizada;
use App\Events\Tenant\PedidoDisponible;
use App\Events\Tenant\PedidoRequiereAsignacionManual;
use App\Events\Tenant\PedidoSinConfirmar;
use App\Jobs\AvisarSinConfirmar;
use App\Jobs\ExpirarOfertaPedido;
use App\Models\Tenant;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConductorPosicion;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\PedidoCambio;
use App\Models\Tenant\PedidoCotizacion;
use App\Models\Tenant\PedidoOferta;
use App\Models\Tenant\SimulacionEnvio;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\VentaViajeConductor;
use App\Models\Tenant\ZonaServicio;
use App\Services\Gps\BuzonGps;
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

function cambioTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function cambioAdmin(Tenant $tenant): Usuario
{
    tenancy()->initialize($tenant);
    $admin = Usuario::create([
        'nombre' => 'Laura', 'apellido_paterno' => 'Torres', 'email' => 'laura@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'AdminCliente', 'estado' => 'Activo',
    ]);
    tenancy()->end();

    return $admin;
}

/**
 * @return array{usuario: Usuario, conductor: Conductor, password: string}
 */
function cambioConductor(Tenant $tenant): array
{
    tenancy()->initialize($tenant);
    $password = 'Password123!';
    $usuario = Usuario::create([
        'nombre' => 'Beto', 'apellido_paterno' => 'Salgado', 'email' => 'beto@cafeluna.com',
        'password' => bcrypt($password), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductor = Conductor::create([
        'id_usuario' => $usuario->id_usuario, 'numero_licencia' => 'LIC-'.$usuario->id_usuario,
        'estado' => 'ACTIVO', 'disponibilidad' => 'DISPONIBLE',
    ]);
    tenancy()->end();

    return ['usuario' => $usuario, 'conductor' => $conductor, 'password' => $password];
}

function cambioToken(string $email = 'beto@cafeluna.com', string $password = 'Password123!'): string
{
    return test()->postJson('/api/v1/t/cafe-luna/conductor/login', [
        'email' => $email,
        'password' => $password,
    ])->assertOk()->json('token');
}

function cambioPedido(array $overrides = []): Pedido
{
    return Pedido::create(array_merge([
        'numero_pedido' => 'PED-'.random_int(100000, 999999),
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4326, 'longitud_recogida' => -99.1332,
        'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.4200, 'longitud_entrega' => -99.1600,
        'fecha_servicio' => now()->addDay()->toDateString(), 'hora_desde' => '09:00', 'hora_hasta' => '11:00',
        'lo_antes_posible' => false, 'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 80, 'estado' => 'TOMADO',
    ], $overrides));
}

it('rejects cancelling a pedido once the conductor has the package', function (string $estado) {
    Event::fake([PedidoCanceladoParaConductor::class]);

    $tenant = cambioTenant();
    $admin = cambioAdmin($tenant);
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['estado' => $estado, 'id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'CANCELADO'])
        ->assertUnprocessable();

    Event::assertNotDispatched(PedidoCanceladoParaConductor::class);

    tenancy()->initialize($tenant);
    expect(Pedido::find($pedido->id_pedido)->estado)->toBe($estado);
    tenancy()->end();
})->with(['EN_CAMINO', 'ARRIBADO_A_ENTREGA']);

it('cancels a pedido before pickup and tells the conductor to stop', function (string $estado) {
    Event::fake([PedidoCanceladoParaConductor::class]);

    $tenant = cambioTenant();
    $admin = cambioAdmin($tenant);
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['estado' => $estado, 'id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/estado", [
            'estado' => 'CANCELADO',
            'motivo' => 'Ya no lo necesito',
            'cancelado_por' => 'CLIENTE',
        ])
        ->assertOk();

    Event::assertDispatched(PedidoCanceladoParaConductor::class, fn ($event) => $event->idPedido === $pedido->id_pedido
        && $event->instruction === 'STOP'
        && $event->motivo === 'Ya no lo necesito'
        && $event->canceladoPor === 'CLIENTE');

    tenancy()->initialize($tenant);
    expect(PedidoCambio::where('id_pedido', $pedido->id_pedido)->where('tipo', 'CANCELADO')->exists())->toBeTrue();
    expect(Pedido::find($pedido->id_pedido)->notificado_conductor_en)->toBeNull();
    tenancy()->end();
})->with(['TOMADO', 'ARRIBADO']);

it('closes a pedido En entrega by incident, with a mandatory reason', function (string $estado) {
    Event::fake([PedidoCerradoPorIncidencia::class]);

    $tenant = cambioTenant();
    $admin = cambioAdmin($tenant);
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['estado' => $estado, 'id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'INCIDENCIA'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo');

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/estado", [
            'estado' => 'INCIDENCIA',
            'motivo' => 'Choque del conductor',
        ])
        ->assertOk()
        ->assertJsonPath('estado', 'INCIDENCIA');

    Event::assertDispatched(PedidoCerradoPorIncidencia::class, fn ($event) => $event->idPedido === $pedido->id_pedido
        && $event->idConductor === $datos['conductor']->id_conductor
        && $event->motivo === 'Choque del conductor');

    tenancy()->initialize($tenant);
    $cerrado = Pedido::find($pedido->id_pedido);
    expect($cerrado->motivo_cancelacion)->toBe('Choque del conductor')
        ->and($cerrado->fecha_cancelacion)->not->toBeNull()
        ->and(Conductor::find($datos['conductor']->id_conductor)->tienePedidoActivo())->toBeFalse()
        ->and(PedidoCambio::where('id_pedido', $pedido->id_pedido)->exists())->toBeTrue();
    tenancy()->end();
})->with(['EN_CAMINO', 'ARRIBADO_A_ENTREGA']);

it('rejects an incident on a pedido that is not En entrega', function (string $estado) {
    $tenant = cambioTenant();
    $admin = cambioAdmin($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['estado' => $estado]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/estado", [
            'estado' => 'INCIDENCIA',
            'motivo' => 'Prueba',
        ])
        ->assertUnprocessable();
})->with(['PENDIENTE', 'PUBLICADO', 'TOMADO', 'ARRIBADO', 'ENTREGADO']);

it('rejects the retired RECHAZADO state', function () {
    $tenant = cambioTenant();
    $admin = cambioAdmin($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['estado' => 'PUBLICADO']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'RECHAZADO'])
        ->assertUnprocessable();
});

it('sends the panel what it needs when a pedido is closed by incident', function () {
    $tenant = cambioTenant();
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['estado' => 'INCIDENCIA', 'id_conductor' => $datos['conductor']->id_conductor]);

    $evento = new PedidoCerradoPorIncidencia($pedido->id_pedido, 'cafe-luna', $datos['conductor']->id_conductor, 'Choque');

    expect($evento->broadcastAs())->toBe('pedido.incidencia')
        ->and($evento->broadcastWith())->toMatchArray([
            'id_pedido' => $pedido->id_pedido,
            'estado' => 'INCIDENCIA',
            'id_conductor' => $datos['conductor']->id_conductor,
            'motivo' => 'Choque',
            'instruction' => 'CONTACT_SUPPORT',
        ]);
    tenancy()->end();
});

it('lets the conductor return a pedido En recogida so that others can take it', function (string $estado) {
    Event::fake([PedidoDevuelto::class, PedidoCanceladoParaConductor::class, PedidoCanceladoSinConductor::class, PedidoDisponible::class]);
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = cambioTenant();
    $datos = cambioConductor($tenant);
    $idConductor = $datos['conductor']->id_conductor;

    tenancy()->initialize($tenant);
    $usuarioOtro = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Ruiz', 'email' => 'ana@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $otro = Conductor::create([
        'id_usuario' => $usuarioOtro->id_usuario, 'numero_licencia' => 'LIC-OTRO',
        'estado' => 'ACTIVO', 'disponibilidad' => 'DISPONIBLE',
    ]);
    $pedido = cambioPedido([
        'estado' => $estado, 'id_conductor' => $idConductor, 'fecha_asignacion' => now(), 'veces_ofertado' => 2,
    ]);
    PedidoOferta::create([
        'id_pedido' => $pedido->id_pedido, 'id_conductor' => $idConductor, 'estado' => 'ACEPTADA',
        'ofrecida_en' => now(), 'expira_en' => now(), 'respondida_en' => now(),
    ]);
    // Perdió la carrera original: ahora que el viaje quedó libre, también tiene que poder tomarlo.
    PedidoOferta::create([
        'id_pedido' => $pedido->id_pedido, 'id_conductor' => $otro->id_conductor, 'estado' => 'PERDIDA',
        'ofrecida_en' => now(), 'expira_en' => now(), 'respondida_en' => now(),
    ]);
    $posicion = ConductorPosicion::create([
        'id_conductor' => $idConductor, 'id_pedido' => $pedido->id_pedido,
        'latitud' => 19.43, 'longitud' => -99.13, 'fecha_posicion' => now(),
    ]);
    tenancy()->end();

    $this->withToken(cambioToken())
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/cancelar")
        ->assertOk()
        ->assertJsonPath('estado', 'PUBLICADO');

    Event::assertDispatched(PedidoDevuelto::class, fn ($event) => $event->idPedido === $pedido->id_pedido
        && $event->idConductorAnterior === $idConductor);
    Event::assertNotDispatched(PedidoCanceladoParaConductor::class);
    Event::assertNotDispatched(PedidoCanceladoSinConductor::class);

    tenancy()->initialize($tenant);
    $devuelto = Pedido::find($pedido->id_pedido);
    expect($devuelto->estado)->toBe('PUBLICADO')
        ->and($devuelto->id_conductor)->toBeNull()
        ->and($devuelto->id_vehiculo)->toBeNull()
        ->and($devuelto->fecha_asignacion)->toBeNull()
        ->and($devuelto->fecha_cancelacion)->toBeNull()
        ->and($devuelto->veces_ofertado)->toBe(1)
        ->and(Conductor::find($idConductor)->tienePedidoActivo())->toBeFalse()
        ->and(PedidoOferta::where('id_conductor', $idConductor)->value('estado'))->toBe('RECHAZADA')
        ->and(PedidoOferta::where('id_conductor', $otro->id_conductor)->value('estado'))->toBe('PENDIENTE')
        ->and($posicion->fresh()->id_pedido)->toBeNull()
        ->and(Auditoria::where('descripcion', 'like', '%regresó a PUBLICADO%')->exists())->toBeTrue();
    tenancy()->end();
})->with(['TOMADO', 'ARRIBADO']);

it('rejects the conductor cancelling a pedido En entrega', function (string $estado) {
    Event::fake([PedidoDevuelto::class]);

    $tenant = cambioTenant();
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['estado' => $estado, 'id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();

    $token = cambioToken();

    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/cancelar")
        ->assertUnprocessable();

    Event::assertNotDispatched(PedidoDevuelto::class);

    // Tampoco por el endpoint de avanzar estado, ni hacia la incidencia, que es solo del Panel.
    foreach (['CANCELADO', 'INCIDENCIA'] as $nuevo) {
        $this->withToken($token)
            ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => $nuevo])
            ->assertUnprocessable();
    }
})->with(['EN_CAMINO', 'ARRIBADO_A_ENTREGA']);

it('does not offer a returned pedido again to the conductor who cancelled it', function () {
    Event::fake([PedidoDevuelto::class, PedidoDisponible::class]);
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = cambioTenant();
    $datos = cambioConductor($tenant);
    $idConductor = $datos['conductor']->id_conductor;
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['estado' => 'TOMADO', 'id_conductor' => $idConductor]);
    tenancy()->end();

    $this->withToken(cambioToken())
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/cancelar")
        ->assertOk();

    tenancy()->initialize($tenant);
    expect(PedidoOferta::where('id_pedido', $pedido->id_pedido)->where('estado', 'PENDIENTE')->exists())->toBeFalse()
        ->and(PedidoOferta::where('id_conductor', $idConductor)->value('estado'))->toBe('RECHAZADA');

    // Tercera y última ronda, y nadie más disponible: ahí sí se le vuelve a ofrecer (RN-05).
    Pedido::whereKey($pedido->id_pedido)->update(['veces_ofertado' => 2]);
    app(OfertaPedidoService::class)->ofertar(Pedido::find($pedido->id_pedido));

    expect(PedidoOferta::where('id_conductor', $idConductor)->value('estado'))->toBe('PENDIENTE');
    tenancy()->end();
});

it('clears the simulated leg of a returned pedido so that the next conductor gets a new one', function () {
    Event::fake([PedidoDevuelto::class, PedidoDisponible::class]);
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = cambioTenant();
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['estado' => 'TOMADO', 'id_conductor' => $datos['conductor']->id_conductor, 'ambiente' => 'TEST']);
    SimulacionEnvio::create([
        'id_pedido' => $pedido->id_pedido, 'tramo' => SimulacionEnvio::TRAMO_ACERCAMIENTO, 'ruta' => [],
        'distancia_m' => 100, 'iniciada_en' => now(), 'avanzada_hasta_m' => 0,
    ]);
    tenancy()->end();

    $this->withToken(cambioToken())
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/cancelar")
        ->assertOk();

    tenancy()->initialize($tenant);
    expect(SimulacionEnvio::where('id_pedido', $pedido->id_pedido)->exists())->toBeFalse();
    tenancy()->end();
});

it('tells the GPS service that the returned envio ended', function () {
    Event::fake([PedidoDevuelto::class, PedidoDisponible::class]);
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = cambioTenant();
    $datos = cambioConductor($tenant);
    $idConductor = $datos['conductor']->id_conductor;
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['estado' => 'TOMADO', 'id_conductor' => $idConductor]);
    tenancy()->end();

    $token = cambioToken();

    $buzon = $this->mock(BuzonGps::class)->shouldIgnoreMissing();
    $buzon->shouldReceive('envioTerminado')->once()->with('cafe-luna', $idConductor, $pedido->id_pedido);

    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/cancelar")
        ->assertOk();
});

it('offers the other queued pedidos, but not the returned one, to the conductor who cancelled', function () {
    Event::fake([PedidoDevuelto::class, PedidoDisponible::class, ConductorColaReactivada::class]);
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = cambioTenant();
    $admin = cambioAdmin($tenant);
    $datos = cambioConductor($tenant);
    $idConductor = $datos['conductor']->id_conductor;
    tenancy()->initialize($tenant);
    // La cola solo se le ofrece a quien tiene saldo (spec tenant/026, RN-07).
    VentaViajeConductor::create([
        'id_conductor' => $idConductor, 'cantidad_viajes' => 5, 'monto_pagado' => 100,
        'id_usuario' => $admin->id_usuario, 'fecha_venta' => now(),
    ]);
    $devuelto = cambioPedido(['estado' => 'TOMADO', 'id_conductor' => $idConductor]);
    $enCola = cambioPedido(['estado' => 'PUBLICADO']);
    tenancy()->end();

    $this->withToken(cambioToken())
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$devuelto->id_pedido}/cancelar")
        ->assertOk();

    tenancy()->initialize($tenant);
    expect(PedidoOferta::where('id_pedido', $enCola->id_pedido)->where('id_conductor', $idConductor)->value('estado'))->toBe('PENDIENTE')
        ->and(PedidoOferta::where('id_pedido', $devuelto->id_pedido)->where('id_conductor', $idConductor)->value('estado'))->toBe('RECHAZADA');
    tenancy()->end();
});

it('does not let a job from a previous round cut the current offers short', function () {
    Event::fake([PedidoRequiereAsignacionManual::class]);

    $tenant = cambioTenant();
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['estado' => 'PUBLICADO', 'veces_ofertado' => 1]);
    $oferta = PedidoOferta::create([
        'id_pedido' => $pedido->id_pedido, 'id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'PENDIENTE',
        'ofrecida_en' => now(), 'expira_en' => now()->addSeconds(30),
    ]);

    (new ExpirarOfertaPedido($tenant, $pedido->id_pedido))->handle(app(OfertaPedidoService::class));

    expect($oferta->fresh()->estado)->toBe('PENDIENTE')
        ->and(Pedido::find($pedido->id_pedido)->veces_ofertado)->toBe(1);
    tenancy()->end();
});

it('lets the conductor acknowledge a change, clearing the pending flag', function () {
    $tenant = cambioTenant();
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['id_conductor' => $datos['conductor']->id_conductor, 'notificado_conductor_en' => null]);
    tenancy()->end();
    $token = cambioToken();

    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/notificado")
        ->assertNoContent();

    tenancy()->initialize($tenant);
    expect(Pedido::find($pedido->id_pedido)->notificado_conductor_en)->not->toBeNull();
    tenancy()->end();
});

it('warns the panel when a conductor does not confirm within 60 seconds', function () {
    Event::fake([PedidoSinConfirmar::class]);

    $tenant = cambioTenant();
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['id_conductor' => $datos['conductor']->id_conductor, 'notificado_conductor_en' => null]);
    tenancy()->end();

    // Simula el vencimiento de los 60s (RN-03) sin llamar al job real con delay.
    (new AvisarSinConfirmar($tenant, $pedido->id_pedido))->handle();

    Event::assertDispatched(PedidoSinConfirmar::class, fn ($event) => $event->idPedido === $pedido->id_pedido);
});

it('does not warn the panel once the conductor already confirmed', function () {
    Event::fake([PedidoSinConfirmar::class]);

    $tenant = cambioTenant();
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['id_conductor' => $datos['conductor']->id_conductor, 'notificado_conductor_en' => now()]);
    tenancy()->end();

    (new AvisarSinConfirmar($tenant, $pedido->id_pedido))->handle();

    Event::assertNotDispatched(PedidoSinConfirmar::class);
});

it('rejects rescheduling a pedido that is already EN_CAMINO', function () {
    $tenant = cambioTenant();
    $admin = cambioAdmin($tenant);
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['estado' => 'EN_CAMINO', 'id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}", [
            'nombre_solicitante' => 'Mario Sánchez', 'telefono_solicitante' => '5511223344',
            'direccion_recogida' => 'Av. Reforma 100', 'direccion_entrega' => 'Av. Insurgentes 200',
            'fecha_servicio' => now()->addDays(2)->toDateString(), 'lo_antes_posible' => false,
            'hora_desde' => '09:00', 'hora_hasta' => '11:00',
            'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO', 'importe_envio' => 80,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fecha_servicio']);
});

it('quotes and applies a relocation, accumulating the extra charge and payout', function () {
    Bus::fake([AvisarSinConfirmar::class]);
    Event::fake([PedidoDireccionActualizada::class]);

    $tenant = cambioTenant();
    $admin = cambioAdmin($tenant);
    $datos = cambioConductor($tenant);

    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_ADICIONAL, '10');
    $pedido = cambioPedido(['id_conductor' => $datos['conductor']->id_conductor]);
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'ONLINE',
        'ultima_conexion' => now(), 'ultima_latitud' => 19.4200, 'ultima_longitud' => -99.1600,
    ]);
    tenancy()->end();

    $cotizacion = $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/reubicacion/cotizar", [
            'direccion' => 'Calle Roble 88', 'latitud' => 19.4402, 'longitud' => -99.1988,
        ])
        ->assertOk()
        ->json();

    expect($cotizacion['extra_distance_km'])->toBeGreaterThan(0);
    expect($cotizacion['extra_charge'])->toBeGreaterThan(0);

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/destino", ['quote_id' => $cotizacion['quote_id']])
        ->assertOk()
        ->assertJsonPath('direccion_entrega', 'Calle Roble 88')
        ->assertJsonPath('conteo_reubicaciones', 1);

    tenancy()->initialize($tenant);
    $pedido->refresh();
    expect((float) $pedido->cargo_extra)->toBeGreaterThan(0);
    expect((float) $pedido->pago_extra)->toEqual((float) $pedido->cargo_extra);
    expect(PedidoCambio::where('id_pedido', $pedido->id_pedido)->where('tipo', 'REUBICADO')->exists())->toBeTrue();
    tenancy()->end();

    Event::assertDispatched(PedidoDireccionActualizada::class);
});

it('rejects applying an expired quote', function () {
    $tenant = cambioTenant();
    $admin = cambioAdmin($tenant);
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['id_conductor' => $datos['conductor']->id_conductor]);
    $cotizacion = PedidoCotizacion::create([
        'id_pedido' => $pedido->id_pedido, 'direccion_nueva' => 'Calle Roble 88',
        'latitud_nueva' => 19.44, 'longitud_nueva' => -99.19, 'distancia_extra_km' => 2,
        'cargo_extra' => 20, 'pago_extra' => 20, 'expira_en' => now()->subMinute(),
    ]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/destino", ['quote_id' => $cotizacion->id_cotizacion])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['quote_id']);
});

it('rejects a third relocation for the same pedido', function () {
    $tenant = cambioTenant();
    $admin = cambioAdmin($tenant);
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['id_conductor' => $datos['conductor']->id_conductor, 'conteo_reubicaciones' => 2]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/reubicacion/cotizar", [
            'direccion' => 'Calle Roble 88', 'latitud' => 19.44, 'longitud' => -99.19,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['destino']);
});

it('rejects a relocation quote outside the tenant coverage zones', function () {
    $tenant = cambioTenant();
    $admin = cambioAdmin($tenant);
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    ZonaServicio::create([
        'nombre' => 'Centro', 'estado' => 'Activo',
        'poligono' => [
            ['lat' => 19.40, 'lng' => -99.20], ['lat' => 19.40, 'lng' => -99.10],
            ['lat' => 19.45, 'lng' => -99.10], ['lat' => 19.45, 'lng' => -99.20],
        ],
    ]);
    $pedido = cambioPedido(['id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/reubicacion/cotizar", [
            'direccion' => 'Fuera de zona', 'latitud' => 20.00, 'longitud' => -100.50,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['destino']);
});

it('accepts a relocation quote inside the tenant coverage zone', function () {
    $tenant = cambioTenant();
    $admin = cambioAdmin($tenant);
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    ZonaServicio::create([
        'nombre' => 'Centro', 'estado' => 'Activo',
        'poligono' => [
            ['lat' => 19.40, 'lng' => -99.20], ['lat' => 19.40, 'lng' => -99.10],
            ['lat' => 19.45, 'lng' => -99.10], ['lat' => 19.45, 'lng' => -99.20],
        ],
    ]);
    $pedido = cambioPedido(['id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/reubicacion/cotizar", [
            'direccion' => 'Dentro de zona', 'latitud' => 19.42, 'longitud' => -99.15,
        ])
        ->assertOk();
});
