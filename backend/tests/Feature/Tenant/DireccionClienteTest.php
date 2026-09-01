<?php

use App\Models\Tenant;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\DireccionCliente;
use App\Models\Tenant\Usuario;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

function direccionTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function direccionAdminUsuario(Tenant $tenant, array $overrides = []): Usuario
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

function direccionCliente(Tenant $tenant, array $overrides = []): Cliente
{
    tenancy()->initialize($tenant);
    $cliente = Cliente::create(array_merge(['nombre' => 'Juan Pérez', 'estado' => 'Activo'], $overrides));
    tenancy()->end();

    return $cliente;
}

it('rejects listing direcciones without a session', function () {
    $tenant = direccionTenant();
    $cliente = direccionCliente($tenant);

    $this->getJson("/api/v1/t/cafe-luna/clientes/{$cliente->id_cliente}/direcciones")->assertUnauthorized();
});

it('rejects direcciones access for a Conductor role', function () {
    $tenant = direccionTenant();
    $conductor = direccionAdminUsuario($tenant, ['email' => 'x@cafeluna.com', 'rol' => 'Conductor']);
    $cliente = direccionCliente($tenant);

    $this->actingAs($conductor, 'usuario')
        ->getJson("/api/v1/t/cafe-luna/clientes/{$cliente->id_cliente}/direcciones")
        ->assertForbidden();
});

it('allows a Despachador to list direcciones but not manage them', function () {
    $tenant = direccionTenant();
    $despachador = direccionAdminUsuario($tenant, ['email' => 'x@cafeluna.com', 'rol' => 'Despachador']);
    $cliente = direccionCliente($tenant);

    $this->actingAs($despachador, 'usuario')
        ->getJson("/api/v1/t/cafe-luna/clientes/{$cliente->id_cliente}/direcciones")
        ->assertOk();

    $this->actingAs($despachador, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/clientes/{$cliente->id_cliente}/direcciones", ['calle' => 'Av. Tecnológico'])
        ->assertForbidden();
});

it('rejects creating a direccion without calle', function () {
    $tenant = direccionTenant();
    $admin = direccionAdminUsuario($tenant);
    $cliente = direccionCliente($tenant);

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/clientes/{$cliente->id_cliente}/direcciones", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['calle']);
});

it('rejects creating a direccion with an out-of-range latitud', function () {
    $tenant = direccionTenant();
    $admin = direccionAdminUsuario($tenant);
    $cliente = direccionCliente($tenant);

    $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/clientes/{$cliente->id_cliente}/direcciones", [
            'calle' => 'Av. Tecnológico',
            'latitud' => 200,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitud']);
});

it('creates a direccion fixing id_cliente from the route, and logs it', function () {
    $tenant = direccionTenant();
    $admin = direccionAdminUsuario($tenant);
    $cliente = direccionCliente($tenant);
    $otroCliente = direccionCliente($tenant, ['nombre' => 'Ana López']);

    $response = $this->actingAs($admin, 'usuario')
        ->postJson("/api/v1/t/cafe-luna/clientes/{$cliente->id_cliente}/direcciones", [
            'alias' => 'Casa',
            'calle' => 'Av. Tecnológico',
            'numero' => '500',
            'id_cliente' => $otroCliente->id_cliente,
        ])
        ->assertCreated()
        ->assertJsonPath('alias', 'Casa');

    expect($response->json('id_cliente'))->toBe($cliente->id_cliente);

    tenancy()->initialize($tenant);
    expect(Auditoria::where('tabla_afectada', 'direcciones_clientes')->where('accion', 'ALTA')->exists())->toBeTrue();
    tenancy()->end();
});

it('lists only the direcciones belonging to the given cliente', function () {
    $tenant = direccionTenant();
    $admin = direccionAdminUsuario($tenant);
    $cliente = direccionCliente($tenant);
    $otroCliente = direccionCliente($tenant, ['nombre' => 'Ana López']);

    tenancy()->initialize($tenant);
    DireccionCliente::create(['id_cliente' => $cliente->id_cliente, 'alias' => 'Casa', 'calle' => 'Calle 1']);
    DireccionCliente::create(['id_cliente' => $cliente->id_cliente, 'alias' => 'Trabajo', 'calle' => 'Calle 2']);
    DireccionCliente::create(['id_cliente' => $otroCliente->id_cliente, 'alias' => 'Casa', 'calle' => 'Calle 3']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->getJson("/api/v1/t/cafe-luna/clientes/{$cliente->id_cliente}/direcciones")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('updates a direccion and logs it', function () {
    $tenant = direccionTenant();
    $admin = direccionAdminUsuario($tenant);
    $cliente = direccionCliente($tenant);

    tenancy()->initialize($tenant);
    $direccion = DireccionCliente::create(['id_cliente' => $cliente->id_cliente, 'alias' => 'Casa', 'calle' => 'Calle 1']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/clientes/{$cliente->id_cliente}/direcciones/{$direccion->id_direccion}", [
            'alias' => 'Casa nueva',
            'calle' => 'Calle 1 actualizada',
        ])
        ->assertOk()
        ->assertJsonPath('alias', 'Casa nueva');

    tenancy()->initialize($tenant);
    expect(Auditoria::where('tabla_afectada', 'direcciones_clientes')->where('accion', 'EDICION')->exists())->toBeTrue();
    tenancy()->end();
});

it('rejects operating on a direccion that does not belong to the given cliente', function () {
    $tenant = direccionTenant();
    $admin = direccionAdminUsuario($tenant);
    $cliente = direccionCliente($tenant);
    $otroCliente = direccionCliente($tenant, ['nombre' => 'Ana López']);

    tenancy()->initialize($tenant);
    $direccion = DireccionCliente::create(['id_cliente' => $otroCliente->id_cliente, 'alias' => 'Casa', 'calle' => 'Calle 1']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/clientes/{$cliente->id_cliente}/direcciones/{$direccion->id_direccion}", [
            'calle' => 'Intento',
        ])
        ->assertUnprocessable();
});

it('deletes a direccion physically and logs it', function () {
    $tenant = direccionTenant();
    $admin = direccionAdminUsuario($tenant);
    $cliente = direccionCliente($tenant);

    tenancy()->initialize($tenant);
    $direccion = DireccionCliente::create(['id_cliente' => $cliente->id_cliente, 'alias' => 'Casa', 'calle' => 'Calle 1']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->deleteJson("/api/v1/t/cafe-luna/clientes/{$cliente->id_cliente}/direcciones/{$direccion->id_direccion}")
        ->assertOk();

    tenancy()->initialize($tenant);
    expect(DireccionCliente::find($direccion->id_direccion))->toBeNull();
    expect(Auditoria::where('tabla_afectada', 'direcciones_clientes')->where('accion', 'BAJA')->exists())->toBeTrue();
    tenancy()->end();
});
