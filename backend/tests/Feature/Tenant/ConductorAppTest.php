<?php

use App\Events\Tenant\PedidoCanceladoParaConductor;
use App\Events\Tenant\PedidoDisponible;
use App\Events\Tenant\PedidoYaTomado;
use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConductorPosicion;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\Vehiculo;
use App\Models\Tenant\VentaViajeConductor;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

function conductorAppTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

/**
 * Configura las tres tarifas (spec 015) y la modalidad Prepago con costo de viaje, porque la
 * liquidación al entregar (spec tenant/013) las necesita.
 */
function conductorAppConfigurar(Tenant $tenant): void
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
 * Crea el Usuario (rol Conductor) y su perfil Conductor. Devuelve ambos ids y la contraseña en
 * claro, para poder loguearse por HTTP como lo haría panda_express.
 *
 * @return array{usuario: Usuario, conductor: Conductor, password: string}
 */
function conductorAppCrear(Tenant $tenant, array $usuarioOverrides = [], array $conductorOverrides = []): array
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

    $conductor = Conductor::create(array_merge([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'LIC-'.$usuario->id_usuario,
        'estado' => 'ACTIVO',
        'disponibilidad' => 'DISPONIBLE',
    ], $conductorOverrides));

    tenancy()->end();

    return ['usuario' => $usuario, 'conductor' => $conductor, 'password' => $password];
}

/**
 * Login real contra /conductor/login, igual que panda_express — devuelve el token Bearer.
 */
function conductorAppToken(string $email, string $password): string
{
    $response = test()->postJson('/api/v1/t/cafe-luna/conductor/login', [
        'email' => $email,
        'password' => $password,
    ])->assertOk();

    return $response->json('token');
}

function conductorAppPedidoPublicado(Tenant $tenant, array $overrides = []): Pedido
{
    tenancy()->initialize($tenant);

    $pedido = Pedido::create(array_merge([
        'numero_pedido' => 'PED-'.random_int(100000, 999999),
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
    ], $overrides));

    tenancy()->end();

    return $pedido;
}

it('issues a token only for a usuario with rol Conductor', function () {
    $tenant = conductorAppTenant();
    ['usuario' => $admin] = conductorAppCrear($tenant, ['email' => 'admin@cafeluna.com', 'rol' => 'AdminCliente']);

    $this->postJson('/api/v1/t/cafe-luna/conductor/login', [
        'email' => 'admin@cafeluna.com',
        'password' => 'Password123!',
    ])->assertUnprocessable();
});

it('logs in a Conductor and returns a bearer token', function () {
    $tenant = conductorAppTenant();
    conductorAppCrear($tenant);

    $response = $this->postJson('/api/v1/t/cafe-luna/conductor/login', [
        'email' => 'beto@cafeluna.com',
        'password' => 'Password123!',
    ])->assertOk();

    expect($response->json('token'))->not->toBeNull();
    expect($response->json('usuario.rol'))->toBe('Conductor');
});

it('rejects conductor routes without a token', function () {
    conductorAppTenant();

    $this->getJson('/api/v1/t/cafe-luna/conductor/pedidos/disponibles')->assertUnauthorized();
});

it('only lists PUBLICADO pedidos with no conductor assigned', function () {
    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    $datos = conductorAppCrear($tenant);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');

    $disponible = conductorAppPedidoPublicado($tenant);
    conductorAppPedidoPublicado($tenant, ['numero_pedido' => 'PED-999999', 'estado' => 'PENDIENTE']);
    conductorAppPedidoPublicado($tenant, [
        'numero_pedido' => 'PED-888888',
        'estado' => 'TOMADO',
        'id_conductor' => $datos['conductor']->id_conductor,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/t/cafe-luna/conductor/pedidos/disponibles')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id_pedido'))->toBe($disponible->id_pedido);
    expect($response->json('data.0.latitud_recogida'))->not->toBeNull();
});

it('accepts an available pedido, assigns the vehicle, and broadcasts pedido.tomado', function () {
    Event::fake([PedidoYaTomado::class]);

    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    $datos = conductorAppCrear($tenant);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');
    $pedido = conductorAppPedidoPublicado($tenant);

    tenancy()->initialize($tenant);
    $vehiculo = Vehiculo::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'placa' => 'ABC-123',
        'marca' => 'Nissan',
    ]);
    tenancy()->end();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/aceptar")
        ->assertOk();

    expect($response->json('estado'))->toBe('TOMADO');
    expect($response->json('id_vehiculo'))->toBe($vehiculo->id_vehiculo);

    Event::assertDispatched(PedidoYaTomado::class, fn ($event) => $event->idPedido === $pedido->id_pedido);
});

it('rejects accepting a pedido that another conductor already took', function () {
    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    conductorAppCrear($tenant);
    $otro = conductorAppCrear($tenant, ['email' => 'otro@cafeluna.com']);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');
    $pedido = conductorAppPedidoPublicado($tenant, ['estado' => 'TOMADO', 'id_conductor' => $otro['conductor']->id_conductor]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/aceptar")
        ->assertUnprocessable();
});

it('rejects accepting a second pedido while one is already active', function () {
    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    $datos = conductorAppCrear($tenant);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');

    conductorAppPedidoPublicado($tenant, ['id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'TOMADO']);
    $segundo = conductorAppPedidoPublicado($tenant, ['numero_pedido' => 'PED-222222']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$segundo->id_pedido}/aceptar")
        ->assertUnprocessable();
});

it('advances a pedido through the conductor transitions and liquidates prepago on ENTREGADO', function () {
    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    $datos = conductorAppCrear($tenant);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');

    tenancy()->initialize($tenant);
    VentaViajeConductor::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'cantidad_viajes' => 1,
        'monto_pagado' => 50,
        'id_usuario' => $datos['usuario']->id_usuario,
        'fecha_venta' => now(),
    ]);
    tenancy()->end();

    $pedido = conductorAppPedidoPublicado($tenant);
    tenancy()->initialize($tenant);
    $pedido->update(['id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'TOMADO']);
    tenancy()->end();

    foreach (['ARRIBADO', 'EN_CAMINO', 'ARRIBADO_A_ENTREGA', 'ENTREGADO'] as $siguiente) {
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => $siguiente])
            ->assertOk()
            ->assertJsonPath('estado', $siguiente);
    }

    tenancy()->initialize($tenant);
    expect($pedido->fresh()->prepago_descontado)->toBeTrue();
    tenancy()->end();
});

it('rejects a conductor moving a pedido to RECHAZADO', function () {
    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    $datos = conductorAppCrear($tenant);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');

    $pedido = conductorAppPedidoPublicado($tenant);
    tenancy()->initialize($tenant);
    $pedido->update(['id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'TOMADO']);
    tenancy()->end();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'RECHAZADO'])
        ->assertUnprocessable();
});

it("rejects a conductor changing another conductor's pedido", function () {
    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    conductorAppCrear($tenant);
    $otro = conductorAppCrear($tenant, ['email' => 'otro@cafeluna.com']);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');

    $pedido = conductorAppPedidoPublicado($tenant, ['estado' => 'TOMADO', 'id_conductor' => $otro['conductor']->id_conductor]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'ARRIBADO'])
        ->assertForbidden();
});

it('cancels the active pedido and broadcasts pedido.cancelado', function () {
    Event::fake([PedidoCanceladoParaConductor::class]);

    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    $datos = conductorAppCrear($tenant);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');

    $pedido = conductorAppPedidoPublicado($tenant);
    tenancy()->initialize($tenant);
    $pedido->update(['id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'TOMADO']);
    tenancy()->end();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/cancelar")
        ->assertOk()
        ->assertJsonPath('estado', 'CANCELADO');

    Event::assertDispatched(PedidoCanceladoParaConductor::class, fn ($event) => $event->idPedido === $pedido->id_pedido);
});

it('broadcasts pedido.disponible when a pedido is published', function () {
    Event::fake([PedidoDisponible::class]);

    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    conductorAppCrear($tenant);

    tenancy()->initialize($tenant);
    $adminUsuario = Usuario::create([
        'nombre' => 'Laura', 'apellido_paterno' => 'Torres', 'email' => 'laura@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'AdminCliente', 'estado' => 'Activo',
    ]);
    $pedido = Pedido::create([
        'numero_pedido' => 'PED-000111', 'nombre_solicitante' => 'Mario', 'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'direccion_entrega' => 'Av. Insurgentes 200',
        'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true,
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO', 'importe_envio' => 80, 'estado' => 'PENDIENTE',
    ]);
    tenancy()->end();

    $this->actingAs($adminUsuario, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/estado", ['estado' => 'PUBLICADO'])
        ->assertOk();

    Event::assertDispatched(PedidoDisponible::class, fn ($event) => $event->pedido->id_pedido === $pedido->id_pedido);
});

it('returns null for the active pedido when the conductor has none', function () {
    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    conductorAppCrear($tenant);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/t/cafe-luna/conductor/pedidos/activo')
        ->assertOk();

    expect($response->getContent())->toBe('null');
});

it('connects and disconnects the conductor', function () {
    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    $datos = conductorAppCrear($tenant);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/t/cafe-luna/conductor/estado', ['estado' => 'ONLINE'])
        ->assertOk()
        ->assertJsonPath('estado', 'ONLINE');

    tenancy()->initialize($tenant);
    $estado = ConductorEstado::where('id_conductor', $datos['conductor']->id_conductor)->first();
    expect($estado->estado)->toBe('ONLINE');
    expect($estado->ultima_conexion)->not->toBeNull();
    tenancy()->end();
});

it('syncs conductores.disponibilidad from the ONLINE/OFFLINE toggle, not the admin panel', function () {
    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    $datos = conductorAppCrear($tenant, [], ['disponibilidad' => 'FUERA_DE_SERVICIO']);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/t/cafe-luna/conductor/estado', ['estado' => 'ONLINE'])
        ->assertOk();

    tenancy()->initialize($tenant);
    expect($datos['conductor']->fresh()->disponibilidad)->toBe('DISPONIBLE');
    tenancy()->end();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/t/cafe-luna/conductor/estado', ['estado' => 'OFFLINE'])
        ->assertOk();

    tenancy()->initialize($tenant);
    expect($datos['conductor']->fresh()->disponibilidad)->toBe('FUERA_DE_SERVICIO');
    tenancy()->end();
});

it('rejects a conductor estado outside ONLINE/OFFLINE', function () {
    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    conductorAppCrear($tenant);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/t/cafe-luna/conductor/estado', ['estado' => 'OCUPADO'])
        ->assertUnprocessable();
});

it('records a location update in conductor_estado and conductor_posiciones', function () {
    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    $datos = conductorAppCrear($tenant);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/t/cafe-luna/conductor/ubicacion', [
            'latitud' => 19.4326,
            'longitud' => -99.1332,
            'precision' => 12.5,
            'velocidad' => 40,
            'rumbo' => 180,
            'bateria' => 80,
        ])
        ->assertNoContent();

    tenancy()->initialize($tenant);
    $estado = ConductorEstado::where('id_conductor', $datos['conductor']->id_conductor)->first();
    expect((float) $estado->ultima_latitud)->toBe(19.4326);
    expect(ConductorPosicion::where('id_conductor', $datos['conductor']->id_conductor)->count())->toBe(1);
    tenancy()->end();
});

it('returns the prepaid trip balance for Prepago modalidad', function () {
    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    $datos = conductorAppCrear($tenant);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');

    tenancy()->initialize($tenant);
    VentaViajeConductor::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'cantidad_viajes' => 3,
        'monto_pagado' => 150,
        'id_usuario' => $datos['usuario']->id_usuario,
        'fecha_venta' => now(),
    ]);
    tenancy()->end();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/t/cafe-luna/conductor/saldo-viajes')
        ->assertOk()
        ->assertJsonPath('saldo', 3);
});

it('returns a null balance for Comision modalidad', function () {
    $tenant = conductorAppTenant();
    conductorAppConfigurar($tenant);
    conductorAppCrear($tenant);
    $token = conductorAppToken('beto@cafeluna.com', 'Password123!');

    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, 'Comision');
    tenancy()->end();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/t/cafe-luna/conductor/saldo-viajes')
        ->assertOk()
        ->assertJsonPath('saldo', null);
});
