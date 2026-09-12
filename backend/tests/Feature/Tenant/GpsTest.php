<?php

use App\Events\Tenant\UbicacionActualizada;
use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConductorPosicion;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\VentaViajeConductor;
use App\Services\DisponibilidadService;
use App\Services\Gps\BuzonGps;
use App\Services\PedidoEstadoService;
use App\Support\TokenGps;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

/**
 * spec tenant/028 — microservicio GPS.
 *
 * Se cubren las tres costuras entre Laravel y el servicio: el permiso que se le emite al conductor,
 * los avisos que Laravel deja en el buzón, y el worker que aplica lo que el servicio devuelve. El
 * servicio en sí (Go) tiene sus propias pruebas en `gps-service/`.
 *
 * `BuzonGps` se sustituye por un doble en casi todas: lo que se está probando es que Laravel avise
 * en el momento correcto, no que Redis funcione.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);

    config([
        'gps.url' => 'https://delivery.prosello.com.mx',
        'gps.secreto' => 'secreto-de-pruebas',
        'gps.token_servicio' => 'token-de-servicio',
    ]);

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

function gpsTenant(): Tenant
{
    return Tenant::create([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ]);
}

/**
 * @return array{usuario: Usuario, conductor: Conductor}
 */
function gpsConductor(Tenant $tenant): array
{
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

    VentaViajeConductor::create([
        'id_conductor' => $conductor->id_conductor,
        'cantidad_viajes' => 5,
        'monto_pagado' => 250,
        'id_usuario' => $usuario->id_usuario,
        'fecha_venta' => now(),
    ]);

    tenancy()->end();

    return ['usuario' => $usuario, 'conductor' => $conductor];
}

function gpsToken(): string
{
    return test()->postJson('/api/v1/t/cafe-luna/conductor/login', [
        'email' => 'beto@cafeluna.com',
        'password' => 'Password123!',
    ])->assertOk()->json('token');
}

function gpsPedido(Tenant $tenant, Conductor $conductor, string $estado = 'PUBLICADO'): Pedido
{
    tenancy()->initialize($tenant);

    $pedido = Pedido::create([
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
        'estado' => $estado,
        'fecha_publicacion' => now(),
        'id_conductor' => $conductor->id_conductor,
    ]);

    tenancy()->end();

    return $pedido;
}

// ---------------------------------------------------------------- el permiso

it('emite un permiso GPS con el tenant y el conductor dentro', function () {
    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    $token = gpsToken();

    $respuesta = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/t/cafe-luna/conductor/gps-token')
        ->assertOk();

    $claims = TokenGps::verificar($respuesta->json('token'), 'secreto-de-pruebas');

    expect($claims)->not->toBeNull()
        ->and($claims['tenant'])->toBe('cafe-luna')
        ->and($claims['sub'])->toBe($conductor->id_conductor)
        ->and($respuesta->json('url'))->toBe('https://delivery.prosello.com.mx/gps/v1/ping');
});

it('dice cuándo renovar antes de que el permiso caduque', function () {
    $tenant = gpsTenant();
    gpsConductor($tenant);
    $token = gpsToken();

    $respuesta = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/t/cafe-luna/conductor/gps-token')
        ->assertOk();

    // RN-07: 30 minutos de vida, aviso de renovación a los 20. El margen es lo que le da a la App
    // diez minutos para reintentar sin quedarse sin rastreo.
    expect(strtotime($respuesta->json('renovar_en')))
        ->toBeLessThan(strtotime($respuesta->json('expira_en')));
});

it('no emite permiso si la integración está apagada', function () {
    config(['gps.url' => '']);

    $tenant = gpsTenant();
    gpsConductor($tenant);
    $token = gpsToken();

    // 503 y no 500: la App lo entiende como "sigue mandando la posición a Laravel" (RN-19).
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/t/cafe-luna/conductor/gps-token')
        ->assertStatus(503)
        ->assertJson(['error' => 'GPS_SERVICE_DISABLED']);
});

it('exige haber iniciado sesión para pedir un permiso', function () {
    $tenant = gpsTenant();
    gpsConductor($tenant);

    $this->postJson('/api/v1/t/cafe-luna/conductor/gps-token')->assertUnauthorized();
});

// ------------------------------------------------------- los avisos al buzón

it('avisa al servicio cuando arranca un envío', function () {
    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnTrue();
    $buzon->shouldReceive('envioTerminado')->zeroOrMoreTimes();

    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    $pedido = gpsPedido($tenant, $conductor);

    $buzon->shouldReceive('envioIniciado')
        ->once()
        ->with('cafe-luna', $conductor->id_conductor, $pedido->id_pedido);

    tenancy()->initialize($tenant);
    $pedido = Pedido::find($pedido->id_pedido);
    app(PedidoEstadoService::class)->transicionar($pedido, 'TOMADO');
    $pedido->save();
    tenancy()->end();
});

it('avisa al servicio cuando el envío termina', function () {
    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnTrue();
    $buzon->shouldReceive('envioIniciado')->zeroOrMoreTimes();

    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    $pedido = gpsPedido($tenant, $conductor, 'ARRIBADO_A_ENTREGA');

    $buzon->shouldReceive('envioTerminado')
        ->once()
        ->with('cafe-luna', $conductor->id_conductor, $pedido->id_pedido);

    tenancy()->initialize($tenant);
    $pedido = Pedido::find($pedido->id_pedido);
    app(PedidoEstadoService::class)->transicionar($pedido, 'ENTREGADO');
    $pedido->save();
    tenancy()->end();
});

it('no avisa al servicio GPS cuando arranca un envío TEST', function () {
    // spec tenant/025 RN-11 / spec tenant/028 RN-13: en TEST el simulador es la única fuente de
    // posición. Si el servicio GPS también quedara habilitado para este conductor, el GPS real del
    // teléfono —sin señal confiable al probar fuera de sitio— competiría con el simulador por
    // `conductor_estado` (era exactamente el defecto: `avisarServicioGps()` no comprobaba `esTest()`
    // aunque `abrirTramoSimulado()`, un método hermano en el mismo `transicionar()`, sí lo hacía).
    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnTrue();
    $buzon->shouldNotReceive('envioIniciado');

    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    $pedido = gpsPedido($tenant, $conductor);

    tenancy()->initialize($tenant);
    $pedido = Pedido::find($pedido->id_pedido);
    $pedido->ambiente = Pedido::AMBIENTE_TEST;
    app(PedidoEstadoService::class)->transicionar($pedido, 'TOMADO');
    $pedido->save();
    tenancy()->end();
});

it('no avisa al servicio GPS cuando termina un envío TEST', function () {
    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnTrue();
    $buzon->shouldNotReceive('envioTerminado');

    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    $pedido = gpsPedido($tenant, $conductor, 'ARRIBADO_A_ENTREGA');

    tenancy()->initialize($tenant);
    $pedido = Pedido::find($pedido->id_pedido);
    $pedido->ambiente = Pedido::AMBIENTE_TEST;
    app(PedidoEstadoService::class)->transicionar($pedido, 'ENTREGADO');
    $pedido->save();
    tenancy()->end();
});

it('no avisa de un pedido que todavía no tiene conductor', function () {
    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnTrue();
    $buzon->shouldNotReceive('envioIniciado');
    $buzon->shouldNotReceive('envioTerminado');

    $tenant = gpsTenant();
    gpsConductor($tenant);

    tenancy()->initialize($tenant);
    $pedido = Pedido::create([
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
        'estado' => 'PENDIENTE',
    ]);
    app(PedidoEstadoService::class)->transicionar($pedido, 'CANCELADO');
    $pedido->save();
    tenancy()->end();
});

it('cancela los permisos del conductor cuando se apaga', function () {
    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnTrue();

    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);

    // RN-10: se revoca por conductor y por fecha, no por permiso, para que caigan también los que
    // tuviera vivos en otro teléfono.
    $buzon->shouldReceive('permisoCancelado')
        ->once()
        ->with('cafe-luna', $conductor->id_conductor, Mockery::type('int'));

    tenancy()->initialize($tenant);
    app(DisponibilidadService::class)->desconectar(Conductor::find($conductor->id_conductor), forzar: true);
    tenancy()->end();
});

// ------------------------------------------------------------- el worker

it('guarda el recorrido que manda el servicio', function () {
    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    $pedido = gpsPedido($tenant, $conductor, 'EN_CAMINO');

    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnTrue();
    $buzon->shouldReceive('asegurarGrupo');
    $buzon->shouldReceive('confirmar')->once();
    $buzon->shouldReceive('leer')->once()->andReturn([[
        'id' => '1-0',
        'tipo' => BuzonGps::RECORRIDO,
        'datos' => [
            'tenant' => 'cafe-luna',
            'id_conductor' => $conductor->id_conductor,
            'id_pedido' => $pedido->id_pedido,
            'puntos' => [
                ['latitud' => 19.4326, 'longitud' => -99.1332, 'fecha_ms' => now()->getTimestampMs(), 'rumbo' => 118, 'velocidad' => '', 'precision' => 12, 'bateria' => ''],
                ['latitud' => 19.4330, 'longitud' => -99.1340, 'fecha_ms' => now()->addSeconds(5)->getTimestampMs(), 'rumbo' => '', 'velocidad' => 34.5, 'precision' => '', 'bateria' => 74],
            ],
        ],
    ]]);

    $this->artisan('gps:consumir-buzon', ['--ciclos' => 1])->assertSuccessful();

    tenancy()->initialize($tenant);

    $puntos = ConductorPosicion::where('id_pedido', $pedido->id_pedido)->orderBy('id_posicion')->get();

    expect($puntos)->toHaveCount(2)
        ->and((float) $puntos[0]->latitud)->toBe(19.4326)
        // Los opcionales viajan como cadena vacía porque Redis no guarda nulos en un hash: tienen
        // que volver a entrar a MySQL como nulos, no como cero.
        ->and($puntos[0]->velocidad)->toBeNull()
        ->and($puntos[0]->rumbo)->toBe(118)
        ->and($puntos[1]->bateria)->toBe(74)
        ->and($puntos[1]->rumbo)->toBeNull();

    // RN-04 de SPEC-021: el recorrido también deja la posición "actual" al día.
    expect((float) ConductorEstado::where('id_conductor', $conductor->id_conductor)->value('ultima_latitud'))
        ->toBe(19.4330);

    tenancy()->end();
});

it('difunde la posición al Panel con el evento de siempre', function () {
    Event::fake([UbicacionActualizada::class]);

    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);

    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnTrue();
    $buzon->shouldReceive('asegurarGrupo');
    $buzon->shouldReceive('confirmar')->once();
    $buzon->shouldReceive('leer')->once()->andReturn([[
        'id' => '1-0',
        'tipo' => BuzonGps::POSICION,
        'datos' => [
            'tenant' => 'cafe-luna',
            'id_conductor' => $conductor->id_conductor,
            'latitud' => 19.4326,
            'longitud' => -99.1332,
            'fecha_ms' => now()->getTimestampMs(),
        ],
    ]]);

    $this->artisan('gps:consumir-buzon', ['--ciclos' => 1])->assertSuccessful();

    // El Panel no cambia con esta spec: recibe el mismo evento, en el mismo canal, que cuando la
    // posición entraba por `Conductor\UbicacionController`.
    Event::assertDispatched(UbicacionActualizada::class, fn (UbicacionActualizada $e) => $e->idConductor === $conductor->id_conductor
            && $e->tenantSlug === 'cafe-luna'
            && $e->latitud === 19.4326);
});

it('descarta una posición del microservicio que implica un salto imposible sin difundirla', function () {
    Event::fake([UbicacionActualizada::class]);

    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);

    tenancy()->initialize($tenant);
    ConductorEstado::where('id_conductor', $conductor->id_conductor)->update([
        'ultima_latitud' => 19.4326,
        'ultima_longitud' => -99.1332,
        'ultima_actualizacion' => now()->subSeconds(10),
    ]);
    tenancy()->end();

    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnTrue();
    $buzon->shouldReceive('asegurarGrupo');
    $buzon->shouldReceive('confirmar')->once();
    $buzon->shouldReceive('leer')->once()->andReturn([[
        'id' => '1-0',
        'tipo' => BuzonGps::POSICION,
        'datos' => [
            'tenant' => 'cafe-luna',
            'id_conductor' => $conductor->id_conductor,
            // San Francisco, igual que en TrackingTest: a 10s de la posición anterior implica más
            // de un millón de km/h, muy por encima de los 150 km/h de RN-03. Es el salto que se
            // vio en el Panel de producción una vez que Reverb empezó a conectar de verdad.
            'latitud' => 37.7749,
            'longitud' => -122.4194,
            'fecha_ms' => now()->getTimestampMs(),
        ],
    ]]);

    $this->artisan('gps:consumir-buzon', ['--ciclos' => 1])->assertSuccessful();

    tenancy()->initialize($tenant);
    $estado = ConductorEstado::where('id_conductor', $conductor->id_conductor)->first();
    expect((float) $estado->ultima_latitud)->toEqualWithDelta(19.4326, 0.0001);
    expect((float) $estado->ultima_longitud)->toEqualWithDelta(-99.1332, 0.0001);
    tenancy()->end();

    Event::assertNotDispatched(UbicacionActualizada::class);
});

it('mantiene vivo al conductor con el latido del servicio', function () {
    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);

    tenancy()->initialize($tenant);
    ConductorEstado::where('id_conductor', $conductor->id_conductor)
        ->update(['ultima_actualizacion' => now()->subMinutes(30)]);
    tenancy()->end();

    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnTrue();
    $buzon->shouldReceive('asegurarGrupo');
    $buzon->shouldReceive('confirmar')->once();
    $buzon->shouldReceive('leer')->once()->andReturn([[
        'id' => '1-0',
        'tipo' => BuzonGps::LATIDO,
        'datos' => ['tenant' => 'cafe-luna', 'id_conductor' => $conductor->id_conductor],
    ]]);

    $this->artisan('gps:consumir-buzon', ['--ciclos' => 1])->assertSuccessful();

    tenancy()->initialize($tenant);

    // RN-04: sin este latido, `conductor:apagar-inactivos` lo apagaría a los 10 minutos mientras
    // espera trabajo.
    expect(ConductorEstado::where('id_conductor', $conductor->id_conductor)->value('ultima_actualizacion'))
        ->toBeGreaterThan(now()->subMinute());

    tenancy()->end();
});

it('descarta un aviso de un tenant que no existe sin dejarlo pendiente', function () {
    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnTrue();
    $buzon->shouldReceive('asegurarGrupo');
    $buzon->shouldReceive('leer')->once()->andReturn([[
        'id' => '1-0',
        'tipo' => BuzonGps::LATIDO,
        'datos' => ['tenant' => 'tenant-que-no-existe', 'id_conductor' => 1],
    ]]);

    // Se confirma igual: reintentar para siempre un aviso imposible solo atasca el buzón.
    $buzon->shouldReceive('confirmar')->once();

    $this->artisan('gps:consumir-buzon', ['--ciclos' => 1])->assertSuccessful();
});

it('no hace nada si la integración está apagada', function () {
    config(['gps.url' => '']);

    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnFalse();
    $buzon->shouldNotReceive('leer');

    $this->artisan('gps:consumir-buzon', ['--ciclos' => 1])->assertSuccessful();
});
