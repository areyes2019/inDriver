<?php

use App\Events\Tenant\PedidoCanceladoParaConductor;
use App\Events\Tenant\PedidoDireccionActualizada;
use App\Events\Tenant\PedidoSinConfirmar;
use App\Jobs\AvisarSinConfirmar;
use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\PedidoCambio;
use App\Models\Tenant\PedidoCotizacion;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\ZonaServicio;
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

it('flags compensation and RETURN_TO_PICKUP when cancelling a pedido already EN_CAMINO', function () {
    Event::fake([PedidoCanceladoParaConductor::class]);

    $tenant = cambioTenant();
    $admin = cambioAdmin($tenant);
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['estado' => 'EN_CAMINO', 'id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/estado", [
            'estado' => 'CANCELADO',
            'motivo' => 'Ya no lo necesito',
            'cancelado_por' => 'CLIENTE',
        ])
        ->assertOk();

    Event::assertDispatched(PedidoCanceladoParaConductor::class, fn ($event) => $event->idPedido === $pedido->id_pedido
        && $event->compensationEligible === true
        && $event->instruction === 'RETURN_TO_PICKUP'
        && $event->motivo === 'Ya no lo necesito'
        && $event->canceladoPor === 'CLIENTE');

    tenancy()->initialize($tenant);
    expect(PedidoCambio::where('id_pedido', $pedido->id_pedido)->where('tipo', 'CANCELADO')->exists())->toBeTrue();
    expect(Pedido::find($pedido->id_pedido)->notificado_conductor_en)->toBeNull();
    tenancy()->end();
});

it('flags STOP instead of RETURN_TO_PICKUP when cancelling before pickup', function () {
    Event::fake([PedidoCanceladoParaConductor::class]);

    $tenant = cambioTenant();
    $admin = cambioAdmin($tenant);
    $datos = cambioConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = cambioPedido(['estado' => 'TOMADO', 'id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'CANCELADO'])
        ->assertOk();

    Event::assertDispatched(PedidoCanceladoParaConductor::class, fn ($event) => $event->compensationEligible === false
        && $event->instruction === 'STOP');
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
