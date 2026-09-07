<?php

use App\Events\Tenant\PedidoDisponible;
use App\Jobs\ExpirarOfertaPedido;
use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\PedidoOferta;
use App\Models\Tenant\Usuario;
use Illuminate\Console\Scheduling\Schedule;
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

function publicacionTenant(): Tenant
{
    return Tenant::create([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ]);
}

function publicacionAdmin(Tenant $tenant): Usuario
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

function publicacionConductorDisponible(Tenant $tenant): Conductor
{
    tenancy()->initialize($tenant);
    $usuario = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Ruiz', 'email' => 'ana@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductor = Conductor::create([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'LIC-'.$usuario->id_usuario,
        'estado' => 'ACTIVO',
        'disponibilidad' => 'DISPONIBLE',
    ]);
    tenancy()->end();

    return $conductor;
}

/**
 * @return array<string, mixed>
 */
function publicacionDatosPedido(array $overrides = []): array
{
    return array_merge([
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4326, 'longitud_recogida' => -99.1332,
        'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.4200, 'longitud_entrega' => -99.1600,
        'lo_antes_posible' => true,
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 80,
    ], $overrides);
}

function publicacionAgendado(array $overrides = []): Pedido
{
    return Pedido::create(array_merge([
        'numero_pedido' => 'PED-'.random_int(100000, 999999),
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4326, 'longitud_recogida' => -99.1332,
        'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.4200, 'longitud_entrega' => -99.1600,
        'lo_antes_posible' => false, 'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 80, 'estado' => 'PENDIENTE',
    ], $overrides));
}

it('publishes an ASAP pedido right when the panel creates it', function () {
    Bus::fake([ExpirarOfertaPedido::class]);
    Event::fake([PedidoDisponible::class]);

    $tenant = publicacionTenant();
    $admin = publicacionAdmin($tenant);
    $conductor = publicacionConductorDisponible($tenant);

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', publicacionDatosPedido())
        ->assertCreated()
        ->assertJsonPath('estado', 'PUBLICADO');

    tenancy()->initialize($tenant);
    expect(Pedido::first()->estado)->toBe('PUBLICADO');
    expect(PedidoOferta::where('id_conductor', $conductor->id_conductor)->where('estado', 'PENDIENTE')->count())->toBe(1);
    tenancy()->end();

    Event::assertDispatched(PedidoDisponible::class);
});

it('leaves a scheduled pedido PENDIENTE when the panel creates it', function () {
    Bus::fake([ExpirarOfertaPedido::class]);
    Event::fake([PedidoDisponible::class]);

    $tenant = publicacionTenant();
    $admin = publicacionAdmin($tenant);
    publicacionConductorDisponible($tenant);

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', publicacionDatosPedido([
            'lo_antes_posible' => false,
            'fecha_servicio' => now()->addDay()->toDateString(),
            'hora_desde' => '10:00',
            'hora_hasta' => '11:00',
        ]))
        ->assertCreated()
        ->assertJsonPath('estado', 'PENDIENTE');

    tenancy()->initialize($tenant);
    expect(PedidoOferta::count())->toBe(0);
    tenancy()->end();

    Event::assertNotDispatched(PedidoDisponible::class);
});

it('publishes a scheduled pedido 15 minutes before its window opens', function () {
    Bus::fake([ExpirarOfertaPedido::class]);
    Event::fake([PedidoDisponible::class]);

    $tenant = publicacionTenant();
    publicacionAdmin($tenant);
    $conductor = publicacionConductorDisponible($tenant);

    $inicio = now()->addMinutes(10);

    tenancy()->initialize($tenant);
    $pedido = publicacionAgendado([
        'fecha_servicio' => $inicio->toDateString(),
        'hora_desde' => $inicio->format('H:i:s'),
        'hora_hasta' => $inicio->copy()->addHour()->format('H:i:s'),
    ]);
    tenancy()->end();

    $this->artisan('pedidos:publicar-agendados')->assertSuccessful();

    tenancy()->initialize($tenant);
    expect(Pedido::find($pedido->id_pedido)->estado)->toBe('PUBLICADO');
    expect(PedidoOferta::where('id_conductor', $conductor->id_conductor)->where('estado', 'PENDIENTE')->count())->toBe(1);
    tenancy()->end();

    Event::assertDispatched(PedidoDisponible::class);
});

it('does not publish a scheduled pedido that is still far from its window', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = publicacionTenant();
    publicacionAdmin($tenant);
    publicacionConductorDisponible($tenant);

    $inicio = now()->addMinutes(45);

    tenancy()->initialize($tenant);
    $pedido = publicacionAgendado([
        'fecha_servicio' => $inicio->toDateString(),
        'hora_desde' => $inicio->format('H:i:s'),
        'hora_hasta' => $inicio->copy()->addHour()->format('H:i:s'),
    ]);
    tenancy()->end();

    $this->artisan('pedidos:publicar-agendados')->assertSuccessful();

    tenancy()->initialize($tenant);
    expect(Pedido::find($pedido->id_pedido)->estado)->toBe('PENDIENTE');
    expect(PedidoOferta::count())->toBe(0);
    tenancy()->end();
});

it('does not publish a scheduled pedido whose window already closed', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = publicacionTenant();
    publicacionAdmin($tenant);
    publicacionConductorDisponible($tenant);

    tenancy()->initialize($tenant);
    $pedido = publicacionAgendado([
        'fecha_servicio' => now()->subDays(3)->toDateString(),
        'hora_desde' => '09:00:00',
        'hora_hasta' => '10:00:00',
    ]);
    tenancy()->end();

    $this->artisan('pedidos:publicar-agendados')->assertSuccessful();

    tenancy()->initialize($tenant);
    expect(Pedido::find($pedido->id_pedido)->estado)->toBe('PENDIENTE');
    expect(PedidoOferta::count())->toBe(0);
    tenancy()->end();
});

it('schedules the offer window to close when publishing', function () {
    Bus::fake([ExpirarOfertaPedido::class]);

    $tenant = publicacionTenant();
    $admin = publicacionAdmin($tenant);
    publicacionConductorDisponible($tenant);

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', publicacionDatosPedido())
        ->assertCreated();

    // Publicar deja programado el cierre de la ventana de 45s (spec tenant/020, RN-03). Que ese job
    // se ejecute de verdad depende del worker del schedule (spec tenant/024, RN-06); lo que hace la
    // reoferta cuando corre ya lo cubre OfertaPedidoTest.
    Bus::assertDispatched(ExpirarOfertaPedido::class);
});

it('keeps a queue worker in the schedule so the deferred jobs actually run', function () {
    // spec tenant/024, RN-06: sin esta entrada, ExpirarOfertaPedido, AvisarSinConfirmar y
    // SimularSiguientePunto no corren en producción, donde no hay ningún proceso supervisado.
    $comandos = collect(app(Schedule::class)->events())->map(fn ($evento) => $evento->command);

    expect($comandos->contains(fn ($comando) => str_contains((string) $comando, 'queue:work')))->toBeTrue();
    expect($comandos->contains(fn ($comando) => str_contains((string) $comando, 'pedidos:publicar-agendados')))->toBeTrue();
});
