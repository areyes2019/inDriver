<?php

use App\Console\Commands\ApagarConductoresInactivos;
use App\Events\Tenant\ConductorDisponibilidadCambiada;
use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use App\Services\SaldoService;
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

function disponibilidadTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

/**
 * @return array{usuario: Usuario, conductor: Conductor, password: string}
 */
function disponibilidadCrearConductor(Tenant $tenant, array $conductorOverrides = []): array
{
    tenancy()->initialize($tenant);

    $password = 'Password123!';
    $usuario = Usuario::create([
        'nombre' => 'Beto', 'apellido_paterno' => 'Salgado', 'email' => 'beto'.uniqid().'@cafeluna.com',
        'password' => bcrypt($password), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);

    $conductor = Conductor::create(array_merge([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'LIC-'.$usuario->id_usuario,
        'estado' => 'ACTIVO',
        'disponibilidad' => 'FUERA_DE_SERVICIO',
    ], $conductorOverrides));

    tenancy()->end();

    return ['usuario' => $usuario, 'conductor' => $conductor, 'password' => $password];
}

function disponibilidadToken(string $email, string $password): string
{
    $response = test()->postJson('/api/v1/t/cafe-luna/conductor/login', [
        'email' => $email,
        'password' => $password,
    ])->assertOk();

    return $response->json('token');
}

it('rejects going online without balance under Comision modality', function () {
    $tenant = disponibilidadTenant();
    $datos = disponibilidadCrearConductor($tenant);

    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, 'Comision');
    tenancy()->end();

    $token = disponibilidadToken($datos['usuario']->email, $datos['password']);

    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/estado', ['estado' => 'ONLINE'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['estado']);

    tenancy()->initialize($tenant);
    expect(Conductor::find($datos['conductor']->id_conductor)->disponibilidad)->toBe('FUERA_DE_SERVICIO');
    tenancy()->end();
});

it('allows going online under Comision modality once money balance is positive', function () {
    $tenant = disponibilidadTenant();
    $datos = disponibilidadCrearConductor($tenant);

    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::MODALIDAD, 'Comision');
    app(SaldoService::class)->registrar($datos['conductor'], 'CREDITO', 50.0);
    tenancy()->end();

    $token = disponibilidadToken($datos['usuario']->email, $datos['password']);

    Event::fake([ConductorDisponibilidadCambiada::class]);

    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/estado', ['estado' => 'ONLINE'])
        ->assertOk()
        ->assertJsonPath('estado', 'ONLINE');

    Event::assertDispatched(ConductorDisponibilidadCambiada::class, fn ($event) => $event->idConductor === $datos['conductor']->id_conductor
        && $event->disponibilidad === 'DISPONIBLE');
});

it('rejects going online without prepaid viajes under Prepago modality', function () {
    $tenant = disponibilidadTenant();
    $datos = disponibilidadCrearConductor($tenant);

    $token = disponibilidadToken($datos['usuario']->email, $datos['password']);

    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/estado', ['estado' => 'ONLINE'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['estado']);
});

it('rejects going offline with an active delivery', function () {
    $tenant = disponibilidadTenant();
    $datos = disponibilidadCrearConductor($tenant, ['disponibilidad' => 'DISPONIBLE']);

    tenancy()->initialize($tenant);
    Pedido::create([
        'numero_pedido' => 'PED-'.random_int(100000, 999999),
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'id_conductor' => $datos['conductor']->id_conductor,
        'estado' => 'TOMADO',
        'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4326, 'longitud_recogida' => -99.1332,
        'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.4200, 'longitud_entrega' => -99.1600,
        'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true, 'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 80,
    ]);
    ConductorEstado::create(['id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'ONLINE', 'ultima_conexion' => now()]);
    tenancy()->end();

    $token = disponibilidadToken($datos['usuario']->email, $datos['password']);

    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/estado', ['estado' => 'OFFLINE'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['estado']);
});

it('forces the conductor offline on logout even with an active delivery', function () {
    $tenant = disponibilidadTenant();
    $datos = disponibilidadCrearConductor($tenant, ['disponibilidad' => 'DISPONIBLE']);

    tenancy()->initialize($tenant);
    Pedido::create([
        'numero_pedido' => 'PED-'.random_int(100000, 999999),
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'id_conductor' => $datos['conductor']->id_conductor,
        'estado' => 'TOMADO',
        'direccion_recogida' => 'Av. Reforma 100', 'latitud_recogida' => 19.4326, 'longitud_recogida' => -99.1332,
        'direccion_entrega' => 'Av. Insurgentes 200', 'latitud_entrega' => 19.4200, 'longitud_entrega' => -99.1600,
        'fecha_servicio' => now()->toDateString(), 'lo_antes_posible' => true, 'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 80,
    ]);
    ConductorEstado::create(['id_conductor' => $datos['conductor']->id_conductor, 'estado' => 'ONLINE', 'ultima_conexion' => now()]);
    tenancy()->end();

    $token = disponibilidadToken($datos['usuario']->email, $datos['password']);

    $this->withToken($token)
        ->postJson('/api/v1/t/cafe-luna/conductor/logout')
        ->assertNoContent();

    tenancy()->initialize($tenant);
    expect(Conductor::find($datos['conductor']->id_conductor)->disponibilidad)->toBe('FUERA_DE_SERVICIO');
    tenancy()->end();
});

it('turns off conductores inactive for more than 10 minutes', function () {
    $tenant = disponibilidadTenant();
    $datos = disponibilidadCrearConductor($tenant, ['disponibilidad' => 'DISPONIBLE']);

    tenancy()->initialize($tenant);
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'estado' => 'ONLINE',
        'ultima_conexion' => now()->subMinutes(20),
        'ultima_actualizacion' => now()->subMinutes(11),
    ]);
    tenancy()->end();

    $this->artisan(ApagarConductoresInactivos::class)->assertExitCode(0);

    tenancy()->initialize($tenant);
    expect(Conductor::find($datos['conductor']->id_conductor)->disponibilidad)->toBe('FUERA_DE_SERVICIO');
    expect(ConductorEstado::first()->estado)->toBe('OFFLINE');
    tenancy()->end();
});

it('leaves recently active conductores online', function () {
    $tenant = disponibilidadTenant();
    $datos = disponibilidadCrearConductor($tenant, ['disponibilidad' => 'DISPONIBLE']);

    tenancy()->initialize($tenant);
    ConductorEstado::create([
        'id_conductor' => $datos['conductor']->id_conductor,
        'estado' => 'ONLINE',
        'ultima_conexion' => now()->subMinutes(20),
        'ultima_actualizacion' => now()->subMinutes(2),
    ]);
    tenancy()->end();

    $this->artisan(ApagarConductoresInactivos::class)->assertExitCode(0);

    tenancy()->initialize($tenant);
    expect(Conductor::find($datos['conductor']->id_conductor)->disponibilidad)->toBe('DISPONIBLE');
    tenancy()->end();
});
