<?php

use App\Events\Tenant\ConductorDisponibilidadCambiada;
use App\Events\Tenant\PedidoEntregado;
use App\Events\Tenant\PedidoYaTomado;
use App\Events\Tenant\SaldoAcreditado;
use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\VentaViajeConductor;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * spec tenant/027 — el Panel se actualiza por evento en vez de recargar.
 *
 * Se cubren las dos mitades del backend: el endpoint que sustituye a paginar `GET /pedidos` entero,
 * y las cargas de los eventos, que son lo que permite al Panel pintar sin volver a preguntar.
 */
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

function panelTenant(): Tenant
{
    return Tenant::create([
        'nombre_comercial' => 'Panel Express',
        'razon_social' => 'Panel Express SA de CV',
        'slug' => 'panel-express',
    ]);
}

function panelAdmin(Tenant $tenant): Usuario
{
    tenancy()->initialize($tenant);
    $admin = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Ruiz', 'email' => 'ana@panelexpress.com',
        'password' => bcrypt('Password123!'), 'rol' => 'AdminCliente', 'estado' => 'Activo',
    ]);
    ConfiguracionTenant::establecer(ConfiguracionTenant::BANDERAZO, '10');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_INCLUIDOS, '5');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_ADICIONAL, '5');
    tenancy()->end();

    return $admin;
}

function panelConductor(Tenant $tenant, string $email, int $viajes = 5): Conductor
{
    tenancy()->initialize($tenant);

    $usuario = Usuario::create([
        'nombre' => 'Beto', 'apellido_paterno' => 'Lara', 'email' => $email,
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

function panelPedido(array $overrides = []): Pedido
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

it('returns only the six en-turno states, without paginating', function () {
    $tenant = panelTenant();
    $admin = panelAdmin($tenant);

    tenancy()->initialize($tenant);
    foreach (['PENDIENTE', 'PUBLICADO', 'TOMADO', 'ARRIBADO', 'EN_CAMINO', 'ARRIBADO_A_ENTREGA'] as $estado) {
        panelPedido(['estado' => $estado]);
    }
    // Los tres finales no son "en turno": son historial, y traérselos era justo lo que obligaba al
    // Panel a paginarlo todo para después descartarlo en el navegador.
    foreach (['ENTREGADO', 'CANCELADO', 'RECHAZADO'] as $estado) {
        panelPedido(['estado' => $estado]);
    }
    tenancy()->end();

    $respuesta = $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/panel-express/pedidos/en-turno')
        ->assertOk();

    expect($respuesta->json('data'))->toHaveCount(6)
        ->and($respuesta->json('meta.snapshot_en'))->not->toBeNull()
        // Sin bloque de paginación: la respuesta es la lista completa.
        ->and($respuesta->json('meta.last_page'))->toBeNull();
});

it('does not paginate at fifteen like the historical listing does', function () {
    $tenant = panelTenant();
    $admin = panelAdmin($tenant);

    tenancy()->initialize($tenant);
    for ($i = 0; $i < 20; $i++) {
        panelPedido(['estado' => 'PUBLICADO']);
    }
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/panel-express/pedidos/en-turno')
        ->assertOk()
        ->assertJsonCount(20, 'data');
});

it('orders lo_antes_posible first and the scheduled ones by hora_desde', function () {
    $tenant = panelTenant();
    $admin = panelAdmin($tenant);

    tenancy()->initialize($tenant);
    panelPedido(['numero_pedido' => 'PED-TARDE', 'estado' => 'PUBLICADO', 'lo_antes_posible' => false, 'hora_desde' => '18:00']);
    panelPedido(['numero_pedido' => 'PED-YA', 'estado' => 'PUBLICADO', 'lo_antes_posible' => true]);
    panelPedido(['numero_pedido' => 'PED-TEMPRANO', 'estado' => 'PUBLICADO', 'lo_antes_posible' => false, 'hora_desde' => '09:00']);
    tenancy()->end();

    $numeros = collect(
        $this->actingAs($admin, 'usuario')
            ->getJson('/api/v1/t/panel-express/pedidos/en-turno')
            ->assertOk()
            ->json('data')
    )->pluck('numero_pedido')->all();

    expect($numeros)->toBe(['PED-YA', 'PED-TEMPRANO', 'PED-TARDE']);
});

it('lets the panel read its lists well past the twenty-per-minute write limit', function () {
    $tenant = panelTenant();
    $admin = panelAdmin($tenant);

    // 25 lecturas seguidas: con `tenant-usuarios` la número 21 devolvía 429 y el Panel se quedaba
    // en "No se pudo cargar" (spec tenant/027, §1).
    for ($i = 0; $i < 25; $i++) {
        $this->actingAs($admin, 'usuario')
            ->getJson('/api/v1/t/panel-express/pedidos/en-turno')
            ->assertOk();
    }

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/panel-express/conductores/activos')
        ->assertOk();
});

it('carries the taken pedido resolved, so the panel does not have to ask again', function () {
    $tenant = panelTenant();
    panelAdmin($tenant);
    $conductor = panelConductor($tenant, 'beto@panelexpress.com');

    tenancy()->initialize($tenant);
    $pedido = panelPedido([
        'estado' => 'TOMADO',
        'id_conductor' => $conductor->id_conductor,
        'prepago_descontado' => true,
    ]);

    $carga = (new PedidoYaTomado($pedido->id_pedido, 'panel-express'))->broadcastWith();
    tenancy()->end();

    expect($carga)->toMatchArray([
        'id_pedido' => $pedido->id_pedido,
        'estado' => 'TOMADO',
        'id_conductor' => $conductor->id_conductor,
        'ambiente' => 'live',
    ])
        ->and($carga['conductor_nombre'])->toBe('Beto Lara')
        // Cinco vendidos menos el que acaba de consumir.
        ->and($carga['saldo_viajes'])->toBe(4)
        ->and($carga['seguimiento']['hito'])->toBe('H1');
});

it('carries who was freed and with how much balance when a pedido is delivered', function () {
    $tenant = panelTenant();
    panelAdmin($tenant);
    $conductor = panelConductor($tenant, 'beto@panelexpress.com');

    tenancy()->initialize($tenant);
    $pedido = panelPedido([
        'estado' => 'ENTREGADO',
        'id_conductor' => $conductor->id_conductor,
        'prepago_descontado' => true,
    ]);

    $carga = (new PedidoEntregado($pedido->id_pedido, 'panel-express'))->broadcastWith();
    tenancy()->end();

    expect($carga['id_conductor'])->toBe($conductor->id_conductor)
        ->and($carga['saldo_viajes'])->toBe(4)
        // Estado final: ya no hay línea que dibujar (spec tenant/026, RN-21).
        ->and($carga['seguimiento'])->toBeNull();
});

it('carries the whole fleet row when a conductor comes online, and nothing but the id when he leaves', function () {
    $tenant = panelTenant();
    panelAdmin($tenant);
    $conductor = panelConductor($tenant, 'beto@panelexpress.com');

    tenancy()->initialize($tenant);
    $entra = (new ConductorDisponibilidadCambiada($conductor->id_conductor, 'DISPONIBLE', 'panel-express'))
        ->broadcastWith();
    $sale = (new ConductorDisponibilidadCambiada($conductor->id_conductor, 'FUERA_DE_SERVICIO', 'panel-express'))
        ->broadcastWith();
    tenancy()->end();

    // Sin esto el Panel no podría insertar la fila —le faltarían nombre, saldo y color— y su única
    // salida sería recargar la lista completa (spec tenant/027, §5).
    expect($entra['conductor'])->not->toBeNull()
        ->and($entra['conductor']['nombre'])->toBe('Beto Lara')
        ->and($entra['conductor']['saldo_viajes'])->toBe(5)
        ->and($entra['conductor']['color'])->toStartWith('#')
        ->and($sale['conductor'])->toBeNull();
});

it('carries the resulting balance, not just the credited amount', function () {
    $tenant = panelTenant();
    panelAdmin($tenant);
    $conductor = panelConductor($tenant, 'beto@panelexpress.com', viajes: 3);

    tenancy()->initialize($tenant);
    $carga = (new SaldoAcreditado($conductor->id_conductor, 3, 'panel-express'))->broadcastWith();
    tenancy()->end();

    // El Panel escribe `saldo_viajes` tal cual en vez de sumarle el delta al número que tenía, así
    // que un evento repetido o perdido no puede dejar la cifra corrida (spec tenant/027, RN-07).
    expect($carga['viajes_acreditados'])->toBe(3)
        ->and($carga['saldo_viajes'])->toBe(3);
});
