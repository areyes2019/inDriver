<?php

use App\Console\Commands\PurgarPosicionesAntiguas;
use App\Events\Tenant\UbicacionActualizada;
use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConductorPosicion;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use App\Services\TrackingService;
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

function trackingTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function trackingAdmin(Tenant $tenant): Usuario
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
function trackingConductor(Tenant $tenant, array $conductorOverrides = []): array
{
    tenancy()->initialize($tenant);
    $password = 'Password123!';
    $usuario = Usuario::create([
        'nombre' => 'Beto', 'apellido_paterno' => 'Salgado', 'email' => 'beto@cafeluna.com',
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

function trackingToken(string $email = 'beto@cafeluna.com', string $password = 'Password123!'): string
{
    return test()->postJson('/api/v1/t/cafe-luna/conductor/login', [
        'email' => $email,
        'password' => $password,
    ])->assertOk()->json('token');
}

function trackingPedido(array $overrides = []): Pedido
{
    return Pedido::create(array_merge([
        'numero_pedido' => 'PED-'.random_int(100000, 999999),
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4326, 'longitud_recogida' => -99.1332,
        'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.4200, 'longitud_entrega' => -99.1600,
        'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true, 'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 80, 'estado' => 'TOMADO',
    ], $overrides));
}

it('does not record history when the conductor has no active pedido', function () {
    $tenant = trackingTenant();
    $datos = trackingConductor($tenant);
    $token = trackingToken();

    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/ubicacion', ['latitud' => 19.43, 'longitud' => -99.13])
        ->assertNoContent();

    // RN-01: estar en línea sin envío no genera tracking. Tampoco se inventa un `conductor_estado`
    // para quien nunca se conectó.
    tenancy()->initialize($tenant);
    expect(ConductorPosicion::count())->toBe(0);
    expect(ConductorEstado::count())->toBe(0);
    tenancy()->end();
});

it('remembers the last known position of an online conductor without a pedido', function () {
    $tenant = trackingTenant();
    $datos = trackingConductor($tenant);

    tenancy()->initialize($tenant);
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'estado' => 'ONLINE',
        'ultima_actualizacion' => now()->subMinutes(5),
    ]);
    tenancy()->end();

    $token = trackingToken();

    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/ubicacion', ['latitud' => 19.43, 'longitud' => -99.13])
        ->assertNoContent();

    // Sin esto un conductor que nunca hizo un envío no tiene posición, y en TEST no la tiene
    // nunca (spec tenant/025, RN-11): el tramo de acercamiento simulado medía cero metros y el
    // conductor se veía estático en el mapa del Panel.
    tenancy()->initialize($tenant);
    $estado = ConductorEstado::where('id_conductor', $datos['conductor']->id_conductor)->first();
    expect((float) $estado->ultima_latitud)->toBe(19.43);
    expect((float) $estado->ultima_longitud)->toBe(-99.13);
    expect(ConductorPosicion::count())->toBe(0);
    tenancy()->end();
});

it('records a position linked to the active pedido and broadcasts it', function () {
    Event::fake([UbicacionActualizada::class]);

    $tenant = trackingTenant();
    $datos = trackingConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = trackingPedido(['id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();
    $token = trackingToken();

    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/ubicacion', [
            'latitud' => 19.43, 'longitud' => -99.13, 'precision' => 10, 'velocidad' => 20, 'rumbo' => 90,
        ])
        ->assertNoContent();

    tenancy()->initialize($tenant);
    $posicion = ConductorPosicion::first();
    expect($posicion->id_pedido)->toBe($pedido->id_pedido);
    expect(ConductorEstado::where('id_conductor', $datos['conductor']->id_conductor)->first()->ultima_latitud)
        ->toEqualWithDelta(19.43, 0.0001);
    tenancy()->end();

    Event::assertDispatched(UbicacionActualizada::class, fn ($event) => $event->idConductor === $datos['conductor']->id_conductor);
});

it('discards a position that implies an impossible speed from the last known one', function () {
    Event::fake([UbicacionActualizada::class]);

    $tenant = trackingTenant();
    $datos = trackingConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = trackingPedido(['id_conductor' => $datos['conductor']->id_conductor]);
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'estado' => 'ONLINE',
        'ultima_latitud' => 19.4326,
        'ultima_longitud' => -99.1332,
        'ultima_actualizacion' => now()->subSeconds(10),
    ]);
    tenancy()->end();
    $token = trackingToken();

    // San Francisco, a unos 3000 km de la posición anterior: a 10 segundos de distancia implica
    // más de un millón de km/h, muy por encima de los 150 km/h de RN-03. Típico de una lectura de
    // geolocalización por red/IP en vez de GPS real (spec tenant/021).
    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/ubicacion', ['latitud' => 37.7749, 'longitud' => -122.4194])
        ->assertNoContent();

    tenancy()->initialize($tenant);
    expect(ConductorPosicion::count())->toBe(0);
    $estado = ConductorEstado::where('id_conductor', $datos['conductor']->id_conductor)->first();
    expect((float) $estado->ultima_latitud)->toEqualWithDelta(19.4326, 0.0001);
    expect((float) $estado->ultima_longitud)->toEqualWithDelta(-99.1332, 0.0001);
    tenancy()->end();

    Event::assertNotDispatched(UbicacionActualizada::class);
});

it('adopts a new base position after too many consecutive impossible jumps', function () {
    Event::fake([UbicacionActualizada::class]);

    $tenant = trackingTenant();
    $datos = trackingConductor($tenant);
    tenancy()->initialize($tenant);
    trackingPedido(['id_conductor' => $datos['conductor']->id_conductor]);

    // La base quedó envenenada con una lectura de geolocalización por red/IP en San Francisco
    // (el incidente de producción de spec tenant/028, §16). Sin RN-03b, cada posición real de
    // CDMX que la contradice se descarta por "salto imposible" y el conductor se queda clavado
    // en San Francisco el resto del envío.
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'estado' => 'ONLINE',
        'ultima_latitud' => 37.7749,
        'ultima_longitud' => -122.4194,
        'ultima_actualizacion' => now()->subSeconds(10),
    ]);
    tenancy()->end();
    $token = trackingToken();

    for ($i = 0; $i < TrackingService::MAX_RECHAZOS_CONSECUTIVOS; $i++) {
        $this->withToken($token)
            ->postJson('/api/v1/t/cafe-luna/conductor/ubicacion', ['latitud' => 19.4326, 'longitud' => -99.1332])
            ->assertNoContent();
    }

    tenancy()->initialize($tenant);
    expect(ConductorPosicion::count())->toBe(0);
    expect(ConductorEstado::where('id_conductor', $datos['conductor']->id_conductor)->first()->rechazos_consecutivos)
        ->toBe(TrackingService::MAX_RECHAZOS_CONSECUTIVOS);
    tenancy()->end();
    Event::assertNotDispatched(UbicacionActualizada::class);

    // La siguiente ya pasa: varias lecturas que coinciden en contradecir a la base pesan más que
    // la base sola.
    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/ubicacion', ['latitud' => 19.4326, 'longitud' => -99.1332])
        ->assertNoContent();

    tenancy()->initialize($tenant);
    expect(ConductorPosicion::count())->toBe(1);
    $estado = ConductorEstado::where('id_conductor', $datos['conductor']->id_conductor)->first();
    expect((float) $estado->ultima_latitud)->toEqualWithDelta(19.4326, 0.0001);
    expect($estado->rechazos_consecutivos)->toBe(0);
    tenancy()->end();

    Event::assertDispatched(UbicacionActualizada::class);
});

it('resets the rejection streak once a plausible position gets through', function () {
    $tenant = trackingTenant();
    $datos = trackingConductor($tenant);
    tenancy()->initialize($tenant);
    trackingPedido(['id_conductor' => $datos['conductor']->id_conductor]);
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'estado' => 'ONLINE',
        'ultima_latitud' => 19.4326,
        'ultima_longitud' => -99.1332,
        'ultima_actualizacion' => now()->subSeconds(10),
    ]);
    tenancy()->end();
    $token = trackingToken();

    // Dos lecturas malas sueltas, con una buena en medio: no deben sumarse entre sí, o un GPS que
    // parpadea de vez en cuando terminaría tumbando una base que está bien.
    foreach ([[37.7749, -122.4194], [19.4330, -99.1335], [37.7749, -122.4194]] as [$latitud, $longitud]) {
        $this->withToken($token)
            ->postJson('/api/v1/t/cafe-luna/conductor/ubicacion', ['latitud' => $latitud, 'longitud' => $longitud])
            ->assertNoContent();
    }

    tenancy()->initialize($tenant);
    $estado = ConductorEstado::where('id_conductor', $datos['conductor']->id_conductor)->first();
    expect($estado->rechazos_consecutivos)->toBe(1);
    expect((float) $estado->ultima_latitud)->toEqualWithDelta(19.4330, 0.0001);
    tenancy()->end();
});

it('does not reject real movement just because a heartbeat refreshed the timestamp', function () {
    Event::fake([UbicacionActualizada::class]);

    $tenant = trackingTenant();
    $datos = trackingConductor($tenant);
    tenancy()->initialize($tenant);
    trackingPedido(['id_conductor' => $datos['conductor']->id_conductor]);

    // El conductor tomó esta posición hace 5 minutos y desde entonces solo ha mandado latidos:
    // `ultima_actualizacion` está fresca, pero la coordenada es de hace 5 minutos. Es lo que pasa
    // en todo envío TEST, donde el GPS real se cambia por un latido cada 15 s (spec tenant/025,
    // RN-11) mientras el simulador mueve al conductor.
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'estado' => 'ONLINE',
        'ultima_latitud' => 20.5439297,
        'ultima_longitud' => -100.8179536,
        'ultima_posicion_en' => now()->subMinutes(5),
        'ultima_actualizacion' => now()->subSecond(),
    ]);
    tenancy()->end();
    $token = trackingToken();

    // 2 km en 5 minutos son 24 km/h: un conductor circulando por ciudad. Medido contra el latido
    // de hace un segundo serían más de 7000 km/h y se descartaba.
    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/ubicacion', ['latitud' => 20.5251896, 'longitud' => -100.8174188])
        ->assertNoContent();

    tenancy()->initialize($tenant);
    expect(ConductorPosicion::count())->toBe(1);
    $estado = ConductorEstado::where('id_conductor', $datos['conductor']->id_conductor)->first();
    expect((float) $estado->ultima_latitud)->toEqualWithDelta(20.5251896, 0.0001);
    tenancy()->end();

    Event::assertDispatched(UbicacionActualizada::class);
});

it('measures the implied speed between capture times, not processing times', function () {
    $tenant = trackingTenant();
    $datos = trackingConductor($tenant);
    tenancy()->initialize($tenant);
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'estado' => 'ONLINE',
        'ultima_latitud' => 19.4326,
        'ultima_longitud' => -99.1332,
        'ultima_posicion_en' => now()->subSeconds(10),
        'ultima_actualizacion' => now()->subSeconds(10),
    ]);

    $tracking = app(TrackingService::class);
    $conductor = $datos['conductor']->fresh();

    // San Francisco capturado 10 segundos después de CDMX: imposible, se rechaza.
    expect($tracking->filtrarPosicionEnVivo($conductor, 37.7749, -122.4194, now()))->toBeFalse();

    // La misma coordenada, pero capturada tres días después de la anterior: ya no implica ninguna
    // velocidad imposible, aunque Laravel la esté procesando en este mismo instante.
    expect($tracking->filtrarPosicionEnVivo($conductor->fresh(), 37.7749, -122.4194, now()->addDays(3)))->toBeTrue();
    tenancy()->end();
});

it('uploads a batch of offline points without broadcasting, updating the current position', function () {
    Event::fake([UbicacionActualizada::class]);

    $tenant = trackingTenant();
    $datos = trackingConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = trackingPedido(['id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();
    $token = trackingToken();

    $puntos = [
        ['latitud' => 19.430, 'longitud' => -99.130, 'fecha_posicion' => now()->subMinutes(3)->toIso8601String()],
        ['latitud' => 19.431, 'longitud' => -99.131, 'fecha_posicion' => now()->subMinutes(2)->toIso8601String()],
        ['latitud' => 19.432, 'longitud' => -99.132, 'fecha_posicion' => now()->subMinute()->toIso8601String()],
    ];

    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/ubicaciones/lote", ['puntos' => $puntos])
        ->assertNoContent();

    tenancy()->initialize($tenant);
    expect(ConductorPosicion::where('id_pedido', $pedido->id_pedido)->count())->toBe(3);
    $estado = ConductorEstado::where('id_conductor', $datos['conductor']->id_conductor)->first();
    expect((float) $estado->ultima_latitud)->toEqualWithDelta(19.432, 0.0001);
    tenancy()->end();

    Event::assertNotDispatched(UbicacionActualizada::class);
});

it('rejects a batch of more than 200 points', function () {
    $tenant = trackingTenant();
    $datos = trackingConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = trackingPedido(['id_conductor' => $datos['conductor']->id_conductor]);
    tenancy()->end();
    $token = trackingToken();

    $puntos = array_fill(0, 201, ['latitud' => 19.43, 'longitud' => -99.13, 'fecha_posicion' => now()->toIso8601String()]);

    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/ubicaciones/lote", ['puntos' => $puntos])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['puntos']);
});

it('rejects uploading a batch for a pedido that belongs to another conductor', function () {
    $tenant = trackingTenant();
    $datos = trackingConductor($tenant);
    tenancy()->initialize($tenant);
    $usuarioOtro = Usuario::create([
        'nombre' => 'Otro', 'apellido_paterno' => 'Conductor', 'email' => 'otro@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);
    $conductorOtro = Conductor::create([
        'id_usuario' => $usuarioOtro->id_usuario, 'numero_licencia' => 'LIC-OTRO',
        'estado' => 'ACTIVO', 'disponibilidad' => 'DISPONIBLE',
    ]);
    $pedido = trackingPedido(['id_conductor' => $conductorOtro->id_conductor]);
    tenancy()->end();
    $token = trackingToken();

    $this->withToken($token)
        ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/ubicaciones/lote", [
            'puntos' => [['latitud' => 19.43, 'longitud' => -99.13, 'fecha_posicion' => now()->toIso8601String()]],
        ])
        ->assertForbidden();
});

it('computes distance and a route summary when the pedido is delivered', function () {
    $tenant = trackingTenant();
    $datos = trackingConductor($tenant);
    tenancy()->initialize($tenant);
    $pedido = trackingPedido(['id_conductor' => $datos['conductor']->id_conductor]);

    // Aproximadamente 1.1 km entre cada punto (0.01 grados de latitud).
    foreach ([19.4300, 19.4400, 19.4500] as $i => $lat) {
        ConductorPosicion::create([
            'id_conductor' => $datos['conductor']->id_conductor,
            'id_pedido' => $pedido->id_pedido,
            'latitud' => $lat,
            'longitud' => -99.1300,
            'fecha_posicion' => now()->addSeconds($i),
        ]);
    }
    tenancy()->end();
    $token = trackingToken();

    foreach (['ARRIBADO', 'EN_CAMINO', 'ARRIBADO_A_ENTREGA', 'ENTREGADO'] as $siguiente) {
        $this->withToken($token)
            ->postJson("/api/v1/t/cafe-luna/conductor/pedidos/{$pedido->id_pedido}/estado", ['estado' => $siguiente])
            ->assertOk();
    }

    tenancy()->initialize($tenant);
    $pedido->refresh();
    expect((float) $pedido->distancia_recorrida_km)->toBeGreaterThan(2.0);
    expect($pedido->resumen_ruta)->toHaveCount(3);
    expect($pedido->resumen_ruta[0])->toHaveKeys(['lat', 'lng', 'fecha']);
    tenancy()->end();
});

it('lets the panel read the ordered track of a pedido', function () {
    $tenant = trackingTenant();
    $admin = trackingAdmin($tenant);
    $datos = trackingConductor($tenant);

    tenancy()->initialize($tenant);
    $pedido = trackingPedido(['id_conductor' => $datos['conductor']->id_conductor]);
    ConductorPosicion::create(['id_conductor' => $datos['conductor']->id_conductor, 'id_pedido' => $pedido->id_pedido, 'latitud' => 19.43, 'longitud' => -99.13, 'fecha_posicion' => now()->subMinute()]);
    ConductorPosicion::create(['id_conductor' => $datos['conductor']->id_conductor, 'id_pedido' => $pedido->id_pedido, 'latitud' => 19.44, 'longitud' => -99.14, 'fecha_posicion' => now()]);
    tenancy()->end();

    $response = $this->actingAs($admin, 'usuario')
        ->getJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}/recorrido")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    expect((float) $response->json('data.0.latitud'))->toEqualWithDelta(19.43, 0.0001);
});

it('purges positions of pedidos delivered more than 7 days ago but keeps recent ones', function () {
    $tenant = trackingTenant();
    $datos = trackingConductor($tenant);

    tenancy()->initialize($tenant);
    $viejo = trackingPedido(['numero_pedido' => 'PED-VIEJO', 'estado' => 'ENTREGADO', 'fecha_entrega' => now()->subDays(10), 'id_conductor' => $datos['conductor']->id_conductor]);
    $reciente = trackingPedido(['numero_pedido' => 'PED-RECIENTE', 'estado' => 'ENTREGADO', 'fecha_entrega' => now()->subDays(2), 'id_conductor' => $datos['conductor']->id_conductor]);
    ConductorPosicion::create(['id_conductor' => $datos['conductor']->id_conductor, 'id_pedido' => $viejo->id_pedido, 'latitud' => 19.43, 'longitud' => -99.13, 'fecha_posicion' => now()->subDays(10)]);
    ConductorPosicion::create(['id_conductor' => $datos['conductor']->id_conductor, 'id_pedido' => $reciente->id_pedido, 'latitud' => 19.43, 'longitud' => -99.13, 'fecha_posicion' => now()->subDays(2)]);
    tenancy()->end();

    $this->artisan(PurgarPosicionesAntiguas::class)->assertExitCode(0);

    tenancy()->initialize($tenant);
    expect(ConductorPosicion::where('id_pedido', $viejo->id_pedido)->exists())->toBeFalse();
    expect(ConductorPosicion::where('id_pedido', $reciente->id_pedido)->exists())->toBeTrue();
    tenancy()->end();
});
