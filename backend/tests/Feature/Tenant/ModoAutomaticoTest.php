<?php

use App\Jobs\EnviarLatido;
use App\Jobs\ExpirarOfertaPedido;
use App\Models\Tenant;
use App\Models\Tenant\AutomaticoBitacora;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Despachador;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use App\Services\ModoAutomaticoService;
use App\Services\OfertaPedidoService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// spec tenant/034: el modo automático, sin avisos al dueño (D7). Todo queda en la bitácora.

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);

    gc_collect_cycles();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }

    Bus::fake([ExpirarOfertaPedido::class]);

    $this->tenant = Tenant::create([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
        'estado' => 'Activo',
    ]);

    tenancy()->initialize($this->tenant);
    $this->admin = Usuario::create([
        'nombre' => 'Laura', 'apellido_paterno' => 'Torres', 'email' => 'laura@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'AdminCliente', 'estado' => 'Activo',
    ]);
    tenancy()->end();
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

/** Un pedido que agotó sus rondas y cayó a PENDIENTE hace `$minutos` minutos. */
function automaticoSinConductor(int $minutos, array $overrides = []): Pedido
{
    $pedido = Pedido::create(array_merge([
        'numero_pedido' => 'PED-'.random_int(100000, 999999),
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4326, 'longitud_recogida' => -99.1332,
        'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.4200, 'longitud_entrega' => -99.1600,
        'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true, 'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 80, 'estado' => 'PENDIENTE', 'veces_ofertado' => 3,
        'fecha_publicacion' => now()->subMinutes($minutos + 3),
        'sin_conductor_desde' => now()->subMinutes($minutos),
    ], $overrides));

    if (isset($overrides['ambiente'])) {
        $pedido->forceFill(['ambiente' => $overrides['ambiente']])->save();
    }

    return $pedido;
}

/** Prende el modo como si se hubiera prendido hace una hora, para que no recorte los conteos. */
function automaticoPrendido(): void
{
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODO_AUTOMATICO, 'Si');
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODO_AUTOMATICO_DESDE, now()->subHour()->toIso8601String());
}

function automaticoCorre(): void
{
    tenancy()->end();
    test()->artisan('automatico:atender-sin-conductor')->assertSuccessful();
    tenancy()->initialize(test()->tenant);
}

function automaticoConductor(string $email): Conductor
{
    $usuario = Usuario::create([
        'nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => $email,
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);

    return Conductor::create([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'LIC-'.$usuario->id_usuario,
        'estado' => 'ACTIVO',
        'disponibilidad' => 'FUERA_DE_SERVICIO',
    ]);
}

it('marca cuándo un pedido se quedó sin conductor al agotar sus rondas, con el modo apagado', function () {
    tenancy()->initialize($this->tenant);
    $pedido = automaticoSinConductor(0, ['estado' => 'PUBLICADO', 'sin_conductor_desde' => null]);

    app(OfertaPedidoService::class)->expirar($pedido);

    $pedido->refresh();
    expect($pedido->estado)->toBe('PENDIENTE')
        ->and($pedido->sin_conductor_desde)->not->toBeNull();
});

it('con el modo apagado no toca los pedidos sin conductor', function () {
    tenancy()->initialize($this->tenant);
    $pedido = automaticoSinConductor(40);

    automaticoCorre();

    expect($pedido->fresh()->estado)->toBe('PENDIENTE')
        ->and(AutomaticoBitacora::count())->toBe(0);
});

it('reofrece a los 5 minutos y lo anota en la bitácora', function () {
    tenancy()->initialize($this->tenant);
    automaticoPrendido();
    $pedido = automaticoSinConductor(6);

    automaticoCorre();

    $pedido->refresh();
    expect($pedido->estado)->toBe('PUBLICADO')
        ->and($pedido->veces_ofertado)->toBe(1)
        ->and($pedido->ultima_reoferta_automatica)->not->toBeNull()
        ->and($pedido->sin_conductor_desde)->not->toBeNull()
        ->and(AutomaticoBitacora::sole()->accion)->toBe('REOFERTA');
});

it('no reofrece antes de 5 minutos desde la última reoferta', function () {
    tenancy()->initialize($this->tenant);
    automaticoPrendido();
    $pedido = automaticoSinConductor(12, ['ultima_reoferta_automatica' => now()->subMinutes(3)]);

    automaticoCorre();

    expect($pedido->fresh()->estado)->toBe('PENDIENTE')
        ->and(AutomaticoBitacora::count())->toBe(0);
});

it('cancela a los 30 minutos como SISTEMA', function () {
    tenancy()->initialize($this->tenant);
    automaticoPrendido();
    $pedido = automaticoSinConductor(31);

    automaticoCorre();

    $pedido->refresh();
    expect($pedido->estado)->toBe('CANCELADO')
        ->and($pedido->cancelado_por)->toBe('SISTEMA')
        ->and($pedido->motivo_cancelacion)->toBe('Sin conductor disponible')
        ->and(AutomaticoBitacora::sole()->accion)->toBe('CANCELACION');
});

it('cuenta los 30 minutos desde que se prendió el modo, no desde antes (RN-04)', function () {
    tenancy()->initialize($this->tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODO_AUTOMATICO, 'Si');
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODO_AUTOMATICO_DESDE, now()->subMinutes(2)->toIso8601String());
    $pedido = automaticoSinConductor(120);

    automaticoCorre();

    expect($pedido->fresh()->estado)->toBe('PENDIENTE');
});

it('no toca un envío de prueba antiguo', function () {
    tenancy()->initialize($this->tenant);
    automaticoPrendido();
    $pedidoTest = automaticoSinConductor(40, ['ambiente' => 'test']);

    automaticoCorre();
    expect(Pedido::withoutGlobalScopes()->find($pedidoTest->id_pedido)->estado)->toBe('PENDIENTE')
        ->and(AutomaticoBitacora::count())->toBe(0);
});

it('actúa aunque la flotilla se haya quedado en TEST antes de quitar el modo', function () {
    tenancy()->initialize($this->tenant);
    automaticoPrendido();
    // El interruptor TEST/LIVE ya no existe, pero la fila vieja puede seguir en la base.
    ConfiguracionTenant::establecer('ambiente', 'test');

    expect(app(ModoAutomaticoService::class)->activo())->toBeTrue();
});

it('no toca un pedido que una persona ya canceló o que nunca se publicó', function () {
    tenancy()->initialize($this->tenant);
    automaticoPrendido();
    $borrador = automaticoSinConductor(40, ['fecha_publicacion' => null]);
    $cancelado = automaticoSinConductor(40, ['estado' => 'CANCELADO', 'cancelado_por' => 'ADMIN']);

    automaticoCorre();

    expect($borrador->fresh()->estado)->toBe('PENDIENTE')
        ->and($cancelado->fresh()->cancelado_por)->toBe('ADMIN')
        ->and(AutomaticoBitacora::count())->toBe(0);
});

it('se pausa solo a la quinta cancelación en una hora, y al reactivarlo el conteo empieza de nuevo', function () {
    tenancy()->initialize($this->tenant);
    automaticoPrendido();
    foreach (range(1, 5) as $i) {
        automaticoSinConductor(31);
    }
    $sexto = automaticoSinConductor(31);

    automaticoCorre();

    expect(ConfiguracionTenant::obtener(ConfiguracionTenant::MODO_AUTOMATICO))->toBe('Pausado')
        ->and(AutomaticoBitacora::where('accion', 'PAUSA')->count())->toBe(1)
        ->and($sexto->fresh()->estado)->toBe('PENDIENTE');

    tenancy()->end();
    $this->actingAs($this->admin, 'usuario')
        ->putJson('/api/v1/t/cafe-luna/configuracion/modo-automatico', ['modo_automatico' => 'Si'])
        ->assertOk()
        ->assertJsonPath('modo_automatico', 'Si');
    tenancy()->initialize($this->tenant);

    // Recién reactivado, el sexto empieza su conteo de cero (RN-25).
    automaticoCorre();
    expect($sexto->fresh()->estado)->toBe('PENDIENTE')
        ->and(ConfiguracionTenant::obtener(ConfiguracionTenant::MODO_AUTOMATICO))->toBe('Si');
});

it('anota una sola vez un pedido atorado en recogida y no le quita el conductor', function () {
    tenancy()->initialize($this->tenant);
    automaticoPrendido();
    $conductor = automaticoConductor('pedro@cafeluna.com');
    $pedido = automaticoSinConductor(0, [
        'estado' => 'TOMADO', 'sin_conductor_desde' => null, 'id_conductor' => $conductor->id_conductor,
        'estado_cambiado_en' => now()->subMinutes(35),
    ]);
    $enEntrega = automaticoSinConductor(0, [
        'estado' => 'EN_CAMINO', 'sin_conductor_desde' => null, 'id_conductor' => $conductor->id_conductor,
        'estado_cambiado_en' => now()->subMinutes(35),
    ]);

    automaticoCorre();
    automaticoCorre();

    $entrada = AutomaticoBitacora::sole();
    expect($entrada->accion)->toBe('ATORADO')
        ->and($entrada->id_pedido)->toBe($pedido->id_pedido)
        ->and($entrada->detalle)->toContain('Pedro Ruiz')
        ->and($pedido->fresh()->id_conductor)->toBe($conductor->id_conductor)
        ->and($pedido->fresh()->estado)->toBe('TOMADO')
        ->and($enEntrega->fresh()->aviso_atorado_en)->toBeNull();
});

it('solo el AdminCliente mueve el interruptor; el Despachador ve el estado y la bitácora', function () {
    tenancy()->initialize($this->tenant);
    $usuarioDespachador = Usuario::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Gómez', 'email' => 'ana@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Despachador', 'estado' => 'Activo',
    ]);
    Despachador::create(['id_usuario' => $usuarioDespachador->id_usuario, 'estado' => 'Activo']);
    AutomaticoBitacora::create(['accion' => 'PAUSA', 'detalle' => 'Se pausó.']);
    tenancy()->end();

    $this->actingAs($usuarioDespachador, 'usuario')
        ->putJson('/api/v1/t/cafe-luna/configuracion/modo-automatico', ['modo_automatico' => 'Si'])
        ->assertForbidden();

    $this->actingAs($usuarioDespachador, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/configuracion')
        ->assertOk()
        ->assertJsonPath('modo_automatico', 'No');

    $this->actingAs($usuarioDespachador, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/automatico/bitacora')
        ->assertOk()
        ->assertJsonPath('data.0.accion', 'PAUSA');

    $this->actingAs($this->admin, 'usuario')
        ->putJson('/api/v1/t/cafe-luna/configuracion/modo-automatico', ['modo_automatico' => 'Pausado'])
        ->assertUnprocessable();
});

it('purga las entradas de la bitácora de más de 90 días', function () {
    tenancy()->initialize($this->tenant);
    $vieja = AutomaticoBitacora::create(['accion' => 'PAUSA', 'detalle' => 'Vieja']);
    $vieja->forceFill(['created_at' => now()->subDays(91)])->save();
    AutomaticoBitacora::create(['accion' => 'PAUSA', 'detalle' => 'Nueva']);
    tenancy()->end();

    $this->artisan('automatico:purgar-bitacora')->assertSuccessful();

    tenancy()->initialize($this->tenant);
    expect(AutomaticoBitacora::pluck('detalle')->all())->toBe(['Nueva']);
});

it('el latido no hace nada sin URL y hace un GET con ella', function () {
    Http::fake();

    config(['services.latido.url' => null]);
    (new EnviarLatido)->handle();
    Http::assertNothingSent();

    config(['services.latido.url' => 'https://vigilante.example/latido']);
    (new EnviarLatido)->handle();
    Http::assertSent(fn ($peticion) => $peticion->url() === 'https://vigilante.example/latido');
});
