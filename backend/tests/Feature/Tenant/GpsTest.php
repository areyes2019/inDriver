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
use Mockery\MockInterface;

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

it('dice en segundos cuándo renovar, para no depender del reloj del teléfono', function () {
    $tenant = gpsTenant();
    gpsConductor($tenant);
    $token = gpsToken();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/t/cafe-luna/conductor/gps-token')
        ->assertOk()
        ->assertJsonPath('renovar_en_segundos', 1200);
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
    $buzon->shouldReceive('leerPendientes')->andReturn([]);
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

    // La posición actual es de `POSICION`, que ya pasó por RN-03. Si una tanda la moviera, un punto
    // recién rechazado volvería a entrar como base en la tanda siguiente.
    expect(ConductorEstado::where('id_conductor', $conductor->id_conductor)->value('ultima_latitud'))
        ->toBeNull();

    tenancy()->end();
});

it('difunde la posición al Panel con el evento de siempre', function () {
    Event::fake([UbicacionActualizada::class]);

    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);

    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnTrue();
    $buzon->shouldReceive('asegurarGrupo');
    $buzon->shouldReceive('leerPendientes')->andReturn([]);
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

it('guarda la posición sin envío para que la próxima simulación TEST tenga un origen real', function () {
    // spec tenant/028, RN-06 / spec tenant/025, RN-08: antes de este aviso, un conductor que
    // aceptaba su primer envío hablándole al servicio GPS nunca tenía `ultima_latitud/longitud` en
    // `conductor_estados`, y la simulación TEST arrancaba el tramo de acercamiento en la recogida
    // misma, cayendo en `ARRIBADO` al instante de aceptarse.
    Event::fake([UbicacionActualizada::class]);

    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);

    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnTrue();
    $buzon->shouldReceive('asegurarGrupo');
    $buzon->shouldReceive('leerPendientes')->andReturn([]);
    $buzon->shouldReceive('confirmar')->once();
    $buzon->shouldReceive('leer')->once()->andReturn([[
        'id' => '1-0',
        'tipo' => BuzonGps::POSICION_SIN_ENVIO,
        'datos' => [
            'tenant' => 'cafe-luna',
            'id_conductor' => $conductor->id_conductor,
            'latitud' => 20.5248,
            'longitud' => -100.8132,
        ],
    ]]);

    $this->artisan('gps:consumir-buzon', ['--ciclos' => 1])->assertSuccessful();

    tenancy()->initialize($tenant);
    $estado = ConductorEstado::where('id_conductor', $conductor->id_conductor)->first();
    expect((float) $estado->ultima_latitud)->toEqualWithDelta(20.5248, 0.0001);
    expect((float) $estado->ultima_longitud)->toEqualWithDelta(-100.8132, 0.0001);
    tenancy()->end();

    // RN-01: sin envío no hay nada que mostrarle al Panel.
    Event::assertNotDispatched(UbicacionActualizada::class);
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
    $buzon->shouldReceive('leerPendientes')->andReturn([]);
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
    $buzon->shouldReceive('leerPendientes')->andReturn([]);
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
    $buzon->shouldReceive('leerPendientes')->andReturn([]);
    $buzon->shouldReceive('leer')->once()->andReturn([[
        'id' => '1-0',
        'tipo' => BuzonGps::LATIDO,
        'datos' => ['tenant' => 'tenant-que-no-existe', 'id_conductor' => 1],
    ]]);

    // Se confirma igual: reintentar para siempre un aviso imposible solo atasca el buzón.
    $buzon->shouldReceive('confirmar')->once();

    $this->artisan('gps:consumir-buzon', ['--ciclos' => 1])->assertSuccessful();
});

/**
 * El worker con un buzón de mentira que entrega estos avisos una vez.
 *
 * @param  array<int, array<string, mixed>>  $avisos
 */
function gpsBuzonConAvisos(array $avisos, array $pendientes = []): MockInterface
{
    $buzon = test()->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnTrue();
    $buzon->shouldReceive('asegurarGrupo');
    $buzon->shouldReceive('leerPendientes')->andReturn($pendientes);
    $buzon->shouldReceive('leer')->andReturn($avisos);
    $buzon->shouldReceive('confirmar')->times(count($avisos) + count($pendientes));

    return $buzon;
}

function gpsCorrerWorker(): void
{
    test()->artisan('gps:consumir-buzon', ['--ciclos' => 1])->assertSuccessful();
}

function gpsBase(Tenant $tenant, Conductor $conductor, array $cambios = []): void
{
    tenancy()->initialize($tenant);
    ConductorEstado::where('id_conductor', $conductor->id_conductor)->update($cambios + [
        'ultima_latitud' => 19.4326,
        'ultima_longitud' => -99.1332,
        'ultima_posicion_en' => now(),
        'ultima_actualizacion' => now(),
    ]);
    tenancy()->end();
}

it('descarta el GPS real de un envío TEST que el servicio cree sin envío', function () {
    // spec tenant/028, §16: en TEST no se avisa al servicio (RN-13), así que para él este conductor
    // no tiene envío. Si la posición se guardara, el teléfono competiría con el simulador.
    Event::fake([UbicacionActualizada::class]);

    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    $pedido = gpsPedido($tenant, $conductor, 'EN_CAMINO');

    tenancy()->initialize($tenant);
    Pedido::where('id_pedido', $pedido->id_pedido)->update(['ambiente' => Pedido::AMBIENTE_TEST]);
    tenancy()->end();

    gpsBase($tenant, $conductor, ['ultima_actualizacion' => now()->subMinutes(5)]);

    $buzon = gpsBuzonConAvisos([[
        'id' => '1-0',
        'tipo' => BuzonGps::POSICION_SIN_ENVIO,
        'datos' => [
            'tenant' => 'cafe-luna',
            'id_conductor' => $conductor->id_conductor,
            'latitud' => 20.5248,
            'longitud' => -100.8132,
            'fecha_ms' => now()->getTimestampMs(),
        ],
    ]]);
    $buzon->shouldNotReceive('envioIniciado');

    gpsCorrerWorker();

    tenancy()->initialize($tenant);
    $estado = ConductorEstado::where('id_conductor', $conductor->id_conductor)->first();
    expect((float) $estado->ultima_latitud)->toEqualWithDelta(19.4326, 0.0001)
        // Se descarta la posición, no al conductor: sin el latido, se apagaría a media simulación.
        ->and($estado->ultima_actualizacion->greaterThan(now()->subMinute()))->toBeTrue();
    tenancy()->end();

    Event::assertNotDispatched(UbicacionActualizada::class);
});

it('recupera un envío LIVE cuando el servicio perdió la marca', function () {
    // Redis reiniciado o envío abierto antes de conectar el servicio: el Panel no debe dejar de ver
    // al conductor, y el servicio tiene que volver a enterarse.
    Event::fake([UbicacionActualizada::class]);

    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    $pedido = gpsPedido($tenant, $conductor, 'EN_CAMINO');

    $buzon = gpsBuzonConAvisos([[
        'id' => '1-0',
        'tipo' => BuzonGps::POSICION_SIN_ENVIO,
        'datos' => [
            'tenant' => 'cafe-luna',
            'id_conductor' => $conductor->id_conductor,
            'latitud' => 19.4326,
            'longitud' => -99.1332,
            'fecha_ms' => now()->getTimestampMs(),
        ],
    ]]);
    $buzon->shouldReceive('envioIniciado')
        ->once()
        ->with('cafe-luna', $conductor->id_conductor, $pedido->id_pedido);

    gpsCorrerWorker();

    Event::assertDispatched(UbicacionActualizada::class, fn (UbicacionActualizada $e) => $e->idConductor === $conductor->id_conductor);
});

it('no retrocede la última posición con un aviso sin envío más viejo', function () {
    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    gpsBase($tenant, $conductor);

    gpsBuzonConAvisos([[
        'id' => '1-0',
        'tipo' => BuzonGps::POSICION_SIN_ENVIO,
        'datos' => [
            'tenant' => 'cafe-luna',
            'id_conductor' => $conductor->id_conductor,
            'latitud' => 19.5,
            'longitud' => -99.2,
            'fecha_ms' => now()->subSeconds(30)->getTimestampMs(),
        ],
    ]]);

    gpsCorrerWorker();

    tenancy()->initialize($tenant);
    expect((float) ConductorEstado::where('id_conductor', $conductor->id_conductor)->value('ultima_latitud'))
        ->toEqualWithDelta(19.4326, 0.0001);
    tenancy()->end();
});

it('no guarda en el recorrido un punto que implica un salto imposible', function () {
    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    $pedido = gpsPedido($tenant, $conductor, 'EN_CAMINO');
    gpsBase($tenant, $conductor);

    gpsBuzonConAvisos([[
        'id' => '1-0',
        'tipo' => BuzonGps::RECORRIDO,
        'datos' => [
            'tenant' => 'cafe-luna',
            'id_conductor' => $conductor->id_conductor,
            'id_pedido' => $pedido->id_pedido,
            'puntos' => [
                ['latitud' => 19.4327, 'longitud' => -99.1333, 'fecha_ms' => now()->getTimestampMs()],
                // San Francisco, dos segundos después de CDMX.
                ['latitud' => 37.7749, 'longitud' => -122.4194, 'fecha_ms' => now()->addSeconds(2)->getTimestampMs()],
            ],
        ],
    ]]);

    gpsCorrerWorker();

    tenancy()->initialize($tenant);
    $puntos = ConductorPosicion::where('id_pedido', $pedido->id_pedido)->get();
    expect($puntos)->toHaveCount(1)
        ->and((float) $puntos[0]->latitud)->toEqualWithDelta(19.4327, 0.0001)
        ->and((float) ConductorEstado::where('id_conductor', $conductor->id_conductor)->value('ultima_latitud'))
        ->toEqualWithDelta(19.4326, 0.0001);
    tenancy()->end();
});

it('no duplica el recorrido si una tanda se vuelve a entregar', function () {
    // RN-17: si el worker muere entre insertar y confirmar, la tanda se reintenta entera.
    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    $pedido = gpsPedido($tenant, $conductor, 'EN_CAMINO');

    $tanda = [
        'id' => '1-0',
        'tipo' => BuzonGps::RECORRIDO,
        'datos' => [
            'tenant' => 'cafe-luna',
            'id_conductor' => $conductor->id_conductor,
            'id_pedido' => $pedido->id_pedido,
            'puntos' => [
                ['latitud' => 19.4326, 'longitud' => -99.1332, 'fecha_ms' => now()->getTimestampMs()],
                ['latitud' => 19.4330, 'longitud' => -99.1340, 'fecha_ms' => now()->addSeconds(5)->getTimestampMs()],
            ],
        ],
    ];

    gpsBuzonConAvisos([$tanda]);
    gpsCorrerWorker();

    gpsBuzonConAvisos([], [$tanda + ['entregas' => 2]]);
    gpsCorrerWorker();

    tenancy()->initialize($tenant);
    expect(ConductorPosicion::where('id_pedido', $pedido->id_pedido)->count())->toBe(2);
    tenancy()->end();
});

it('recalcula el resumen de ruta si la última tanda llega después de entregar', function () {
    // `calcularResumenRuta()` corre al entregar, y el aviso que suelta la última tanda sale después.
    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    $pedido = gpsPedido($tenant, $conductor, 'ENTREGADO');

    tenancy()->initialize($tenant);
    ConductorPosicion::create([
        'id_conductor' => $conductor->id_conductor,
        'id_pedido' => $pedido->id_pedido,
        'latitud' => 19.4300,
        'longitud' => -99.1300,
        'fecha_posicion' => now()->subMinute(),
    ]);
    tenancy()->end();

    gpsBuzonConAvisos([[
        'id' => '1-0',
        'tipo' => BuzonGps::RECORRIDO,
        'datos' => [
            'tenant' => 'cafe-luna',
            'id_conductor' => $conductor->id_conductor,
            'id_pedido' => $pedido->id_pedido,
            // Unos 1.1 km desde el punto anterior (0.01 grados de latitud).
            'puntos' => [['latitud' => 19.4400, 'longitud' => -99.1300, 'fecha_ms' => now()->getTimestampMs()]],
        ],
    ]]);

    gpsCorrerWorker();

    tenancy()->initialize($tenant);
    $pedido = Pedido::find($pedido->id_pedido);
    expect((float) $pedido->distancia_recorrida_km)->toEqualWithDelta(1.11, 0.02)
        ->and($pedido->resumen_ruta)->toHaveCount(2);
    tenancy()->end();
});

it('no recalcula el resumen de un envío que sigue en curso', function () {
    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    $pedido = gpsPedido($tenant, $conductor, 'EN_CAMINO');

    gpsBuzonConAvisos([[
        'id' => '1-0',
        'tipo' => BuzonGps::RECORRIDO,
        'datos' => [
            'tenant' => 'cafe-luna',
            'id_conductor' => $conductor->id_conductor,
            'id_pedido' => $pedido->id_pedido,
            'puntos' => [['latitud' => 19.4400, 'longitud' => -99.1300, 'fecha_ms' => now()->getTimestampMs()]],
        ],
    ]]);

    gpsCorrerWorker();

    tenancy()->initialize($tenant);
    expect(Pedido::find($pedido->id_pedido)->resumen_ruta)->toBeNull();
    tenancy()->end();
});

it('ignora una posición más vieja que la vigente, sin moverla ni difundirla', function () {
    Event::fake([UbicacionActualizada::class]);

    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    gpsBase($tenant, $conductor);

    gpsBuzonConAvisos([[
        'id' => '1-0',
        'tipo' => BuzonGps::POSICION,
        'datos' => [
            'tenant' => 'cafe-luna',
            'id_conductor' => $conductor->id_conductor,
            'latitud' => 19.4330,
            'longitud' => -99.1340,
            'fecha_ms' => now()->subSeconds(30)->getTimestampMs(),
        ],
    ]]);

    gpsCorrerWorker();

    tenancy()->initialize($tenant);
    $estado = ConductorEstado::where('id_conductor', $conductor->id_conductor)->first();
    expect((float) $estado->ultima_latitud)->toEqualWithDelta(19.4326, 0.0001)
        // No contradice a la base, solo llegó tarde: no cuenta para RN-03b.
        ->and($estado->rechazos_consecutivos)->toBe(0);
    tenancy()->end();

    Event::assertNotDispatched(UbicacionActualizada::class);
});

it('guarda sin difundir una posición atrasada, para no reproducir el viaje en cámara rápida', function () {
    Event::fake([UbicacionActualizada::class]);

    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);

    gpsBuzonConAvisos([[
        'id' => '1-0',
        'tipo' => BuzonGps::POSICION,
        'datos' => [
            'tenant' => 'cafe-luna',
            'id_conductor' => $conductor->id_conductor,
            'latitud' => 19.4330,
            'longitud' => -99.1340,
            'fecha_ms' => now()->subMinutes(2)->getTimestampMs(),
        ],
    ]]);

    gpsCorrerWorker();

    tenancy()->initialize($tenant);
    expect((float) ConductorEstado::where('id_conductor', $conductor->id_conductor)->value('ultima_latitud'))
        ->toEqualWithDelta(19.4330, 0.0001);
    tenancy()->end();

    Event::assertNotDispatched(UbicacionActualizada::class);
});

it('confirma la posición aunque Reverb no conteste', function () {
    // La siguiente posición reemplaza a esta (spec tenant/018, RN-05): reintentarla solo atrasaría
    // al Panel. `gpsBuzonConAvisos` exige la confirmación.
    Event::listen(UbicacionActualizada::class, fn () => throw new RuntimeException('Reverb caído'));

    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);

    gpsBuzonConAvisos([[
        'id' => '1-0',
        'tipo' => BuzonGps::POSICION,
        'datos' => [
            'tenant' => 'cafe-luna',
            'id_conductor' => $conductor->id_conductor,
            'latitud' => 19.4330,
            'longitud' => -99.1340,
            'fecha_ms' => now()->getTimestampMs(),
        ],
    ]]);

    gpsCorrerWorker();

    tenancy()->initialize($tenant);
    expect((float) ConductorEstado::where('id_conductor', $conductor->id_conductor)->value('ultima_latitud'))
        ->toEqualWithDelta(19.4330, 0.0001);
    tenancy()->end();
});

it('reintenta un aviso que quedó sin confirmar', function () {
    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    gpsBase($tenant, $conductor, ['ultima_actualizacion' => now()->subMinutes(30)]);

    gpsBuzonConAvisos([], [[
        'id' => '1-0',
        'tipo' => BuzonGps::LATIDO,
        'datos' => ['tenant' => 'cafe-luna', 'id_conductor' => $conductor->id_conductor],
        'entregas' => 1,
    ]]);

    gpsCorrerWorker();

    tenancy()->initialize($tenant);
    expect(ConductorEstado::where('id_conductor', $conductor->id_conductor)->value('ultima_actualizacion'))
        ->toBeGreaterThan(now()->subMinute());
    tenancy()->end();
});

it('descarta un aviso que agotó sus reintentos sin volver a aplicarlo', function () {
    $tenant = gpsTenant();
    ['conductor' => $conductor] = gpsConductor($tenant);
    gpsBase($tenant, $conductor, ['ultima_actualizacion' => now()->subMinutes(30)]);

    gpsBuzonConAvisos([], [[
        'id' => '1-0',
        'tipo' => BuzonGps::LATIDO,
        'datos' => ['tenant' => 'cafe-luna', 'id_conductor' => $conductor->id_conductor],
        'entregas' => BuzonGps::MAX_ENTREGAS,
    ]]);

    gpsCorrerWorker();

    tenancy()->initialize($tenant);
    expect(ConductorEstado::where('id_conductor', $conductor->id_conductor)->value('ultima_actualizacion'))
        ->toBeLessThan(now()->subMinutes(29));
    tenancy()->end();
});

it('no hace nada si la integración está apagada', function () {
    config(['gps.url' => '']);

    $buzon = $this->mock(BuzonGps::class);
    $buzon->shouldReceive('habilitado')->andReturnFalse();
    $buzon->shouldNotReceive('leer');

    $this->artisan('gps:consumir-buzon', ['--ciclos' => 1])->assertSuccessful();
});
