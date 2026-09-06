<?php

use App\Models\Tenant;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\MovimientoSaldo;
use App\Models\Tenant\Usuario;
use App\Services\SaldoService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

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

function saldoTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function saldoAdminUsuario(Tenant $tenant, array $overrides = []): Usuario
{
    tenancy()->initialize($tenant);

    $usuario = Usuario::create(array_merge([
        'nombre' => 'Laura',
        'apellido_paterno' => 'Torres',
        'email' => 'laura@cafeluna.com',
        'password' => bcrypt('Password123!'),
        'rol' => 'AdminCliente',
        'estado' => 'Activo',
    ], $overrides));

    tenancy()->end();

    return $usuario;
}

function saldoCrearConductor(Tenant $tenant, array $overrides = []): Conductor
{
    tenancy()->initialize($tenant);

    $usuario = Usuario::create([
        'nombre' => 'Beto', 'apellido_paterno' => 'Salgado', 'email' => 'beto'.uniqid().'@cafeluna.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Conductor', 'estado' => 'Activo',
    ]);

    $conductor = Conductor::create(array_merge([
        'id_usuario' => $usuario->id_usuario,
        'numero_licencia' => 'ABC'.uniqid(),
        'estado' => 'ACTIVO',
        'disponibilidad' => 'FUERA_DE_SERVICIO',
    ], $overrides));

    tenancy()->end();

    return $conductor;
}

function saldoToken(string $email, string $password): string
{
    $response = test()->postJson('/api/v1/t/cafe-luna/conductor/login', [
        'email' => $email,
        'password' => $password,
    ])->assertOk();

    return $response->json('token');
}

it('credits money balance from the panel and updates conductores.saldo', function () {
    $tenant = saldoTenant();
    $admin = saldoAdminUsuario($tenant);
    $conductor = saldoCrearConductor($tenant);

    $response = $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}/saldo", [
            'tipo' => 'CREDITO',
            'monto' => 200,
            'referencia' => 'Depósito OXXO',
        ])
        ->assertCreated()
        ->assertJsonPath('saldo_resultante', 200);

    tenancy()->initialize($tenant);
    expect((float) Conductor::find($conductor->id_conductor)->saldo)->toBe(200.0);
    expect(MovimientoSaldo::count())->toBe(1);
    tenancy()->end();
});

it('two simultaneous credits of 100 over a zero balance leave the balance at 200, never 100', function () {
    $tenant = saldoTenant();
    $conductor = saldoCrearConductor($tenant);

    tenancy()->initialize($tenant);
    $service = app(SaldoService::class);
    $service->registrar($conductor, 'CREDITO', 100.0);
    $service->registrar($conductor->fresh(), 'CREDITO', 100.0);

    expect((float) Conductor::find($conductor->id_conductor)->saldo)->toBe(200.0);
    tenancy()->end();
});

it('rejects a zero amount with INVALID_AMOUNT', function () {
    $tenant = saldoTenant();
    $admin = saldoAdminUsuario($tenant);
    $conductor = saldoCrearConductor($tenant);

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}/saldo", [
            'tipo' => 'CREDITO',
            'monto' => 0,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['monto']);
});

it('rejects a negative amount for CREDITO', function () {
    $tenant = saldoTenant();
    $conductor = saldoCrearConductor($tenant);

    tenancy()->initialize($tenant);
    $service = app(SaldoService::class);

    expect(fn () => $service->registrar($conductor, 'CREDITO', -50.0))
        ->toThrow(ValidationException::class);
    tenancy()->end();
});

it('lets the conductor read their money balance and movement history from the app', function () {
    $tenant = saldoTenant();
    $conductor = saldoCrearConductor($tenant, ['disponibilidad' => 'DISPONIBLE']);

    tenancy()->initialize($tenant);
    $usuario = Usuario::find($conductor->id_usuario);
    $usuario->forceFill(['password' => bcrypt('Password123!')])->save();
    app(SaldoService::class)->registrar($conductor, 'CREDITO', 340.50);
    tenancy()->end();

    $token = saldoToken($usuario->email, 'Password123!');

    $this->withToken($token)
        ->getJson('/api/v1/t/cafe-luna/conductor/saldo')
        ->assertOk()
        ->assertJsonPath('saldo', 340.5);

    $this->withToken($token)
        ->getJson('/api/v1/t/cafe-luna/conductor/movimientos-saldo')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('rejects a despachador from accessing the panel balance endpoint', function () {
    $tenant = saldoTenant();
    $despachador = saldoAdminUsuario($tenant, [
        'email' => 'pedro@cafeluna.com',
        'rol' => 'Despachador',
    ]);
    $conductor = saldoCrearConductor($tenant);

    $this->actingAs($despachador, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/conductores/{$conductor->id_conductor}/saldo", [
            'tipo' => 'CREDITO',
            'monto' => 100,
        ])
        ->assertForbidden();
});
