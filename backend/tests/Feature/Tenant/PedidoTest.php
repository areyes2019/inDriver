<?php

use App\Models\Tenant;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Pedido;
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

function pedidoTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function pedidoUsuario(Tenant $tenant, array $overrides = []): Usuario
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

function pedidoDatosValidos(array $overrides = []): array
{
    return array_merge([
        'nombre_solicitante' => 'Mario Sánchez',
        'telefono_solicitante' => '5511223344',
        'direccion_recogida' => 'Av. Reforma 100',
        'direccion_entrega' => 'Av. Insurgentes 200',
        'fecha_servicio' => now()->addDay()->toDateString(),
        'lo_antes_posible' => true,
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 80,
    ], $overrides);
}

it('rejects listing pedidos without a session', function () {
    $tenant = pedidoTenant();

    $this->getJson('/api/v1/t/cafe-luna/pedidos')->assertUnauthorized();
});

it('rejects pedidos access for a Conductor role', function () {
    $tenant = pedidoTenant();
    $conductor = pedidoUsuario($tenant, ['email' => 'c@cafeluna.com', 'rol' => 'Conductor']);

    $this->actingAs($conductor, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/pedidos')
        ->assertForbidden();
});

it('allows a Despachador to list pedidos', function () {
    $tenant = pedidoTenant();
    $despachador = pedidoUsuario($tenant, ['email' => 'd@cafeluna.com', 'rol' => 'Despachador']);

    $this->actingAs($despachador, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/pedidos')
        ->assertOk();
});

it('rejects creating a pedido for an AdminCliente role', function () {
    $tenant = pedidoTenant();
    $admin = pedidoUsuario($tenant);

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', pedidoDatosValidos())
        ->assertForbidden();
});

it('rejects creating a pedido without required fields', function () {
    $tenant = pedidoTenant();
    $despachador = pedidoUsuario($tenant, ['email' => 'd@cafeluna.com', 'rol' => 'Despachador']);

    $this->actingAs($despachador, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'nombre_solicitante', 'telefono_solicitante', 'direccion_recogida', 'direccion_entrega',
            'modalidad_pago',
        ]);
});

it('rejects creating a pedido with a fixed horario but no fecha_servicio', function () {
    $tenant = pedidoTenant();
    $despachador = pedidoUsuario($tenant, ['email' => 'd@cafeluna.com', 'rol' => 'Despachador']);

    $this->actingAs($despachador, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', pedidoDatosValidos([
            'lo_antes_posible' => false,
            'fecha_servicio' => null,
            'hora_desde' => '09:00',
            'hora_hasta' => '11:00',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fecha_servicio']);
});

it('rejects creating a pedido with a fixed horario but no hora_desde/hora_hasta', function () {
    $tenant = pedidoTenant();
    $despachador = pedidoUsuario($tenant, ['email' => 'd@cafeluna.com', 'rol' => 'Despachador']);

    $this->actingAs($despachador, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', pedidoDatosValidos(['lo_antes_posible' => false]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['hora_desde']);
});

it('creates a pedido without fecha_servicio when lo_antes_posible is true, defaulting to today', function () {
    $tenant = pedidoTenant();
    $despachador = pedidoUsuario($tenant, ['email' => 'd@cafeluna.com', 'rol' => 'Despachador']);

    $response = $this->actingAs($despachador, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', pedidoDatosValidos(['fecha_servicio' => null]))
        ->assertCreated();

    expect($response->json('fecha_servicio'))->toBe(now()->toDateString());
});

it('rejects creating a pedido without importe_envio', function () {
    $tenant = pedidoTenant();
    $despachador = pedidoUsuario($tenant, ['email' => 'd@cafeluna.com', 'rol' => 'Despachador']);

    $this->actingAs($despachador, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', pedidoDatosValidos(['importe_envio' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['importe_envio']);
});

it('forces importe_cobro to zero when modalidad_pago does not involve producto', function () {
    $tenant = pedidoTenant();
    $despachador = pedidoUsuario($tenant, ['email' => 'd@cafeluna.com', 'rol' => 'Despachador']);

    $response = $this->actingAs($despachador, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', pedidoDatosValidos([
            'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
            'importe_cobro' => 500,
        ]))
        ->assertCreated();

    expect((float) $response->json('importe_cobro'))->toBe(0.0);
});

it('keeps importe_cobro when modalidad_pago is RECEPTOR_PAGA_ENVIO_PRODUCTOS', function () {
    $tenant = pedidoTenant();
    $despachador = pedidoUsuario($tenant, ['email' => 'd@cafeluna.com', 'rol' => 'Despachador']);

    $response = $this->actingAs($despachador, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', pedidoDatosValidos([
            'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO_PRODUCTOS',
            'importe_cobro' => 500,
        ]))
        ->assertCreated();

    expect((float) $response->json('importe_cobro'))->toBe(500.0);
});

it('allows a Despachador to read clientes and configuracion, but not manage them', function () {
    $tenant = pedidoTenant();
    $despachador = pedidoUsuario($tenant, ['email' => 'd@cafeluna.com', 'rol' => 'Despachador']);

    $this->actingAs($despachador, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/clientes')
        ->assertOk();

    $this->actingAs($despachador, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/configuracion')
        ->assertOk();

    $this->actingAs($despachador, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/clientes', ['nombre' => 'Cliente nuevo'])
        ->assertForbidden();

    $this->actingAs($despachador, 'usuario')
        ->putJson('/api/v1/t/cafe-luna/configuracion', ['tarifa_banderazo' => 10])
        ->assertForbidden();
});

it('creates a pedido with an autogenerated numero_pedido, PENDIENTE estado, and logs it', function () {
    $tenant = pedidoTenant();
    $despachador = pedidoUsuario($tenant, ['email' => 'd@cafeluna.com', 'rol' => 'Despachador']);

    $response = $this->actingAs($despachador, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', pedidoDatosValidos())
        ->assertCreated()
        ->assertJsonPath('estado', 'PENDIENTE');

    expect($response->json('numero_pedido'))->toStartWith('PED-');

    tenancy()->initialize($tenant);
    expect(Auditoria::where('tabla_afectada', 'pedidos')->where('accion', 'ALTA')->exists())->toBeTrue();
    tenancy()->end();
});

it('updates a pedido and logs it', function () {
    $tenant = pedidoTenant();
    $admin = pedidoUsuario($tenant);
    $despachador = pedidoUsuario($tenant, ['email' => 'd@cafeluna.com', 'rol' => 'Despachador']);

    $creado = $this->actingAs($despachador, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', pedidoDatosValidos())
        ->json();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/pedidos/{$creado['id_pedido']}", pedidoDatosValidos(['nombre_solicitante' => 'Mario Actualizado']))
        ->assertOk()
        ->assertJsonPath('nombre_solicitante', 'Mario Actualizado');

    tenancy()->initialize($tenant);
    expect(Auditoria::where('tabla_afectada', 'pedidos')->where('accion', 'EDICION')->exists())->toBeTrue();
    tenancy()->end();
});

it('rejects updating a pedido that is already in a final estado', function () {
    $tenant = pedidoTenant();
    $admin = pedidoUsuario($tenant);

    tenancy()->initialize($tenant);
    $pedido = Pedido::create([...pedidoDatosValidos(), 'numero_pedido' => 'PED-000001', 'estado' => 'CANCELADO']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/pedidos/{$pedido->id_pedido}", pedidoDatosValidos())
        ->assertUnprocessable();
});

it('advances a pedido through a valid transition and stamps the matching fecha', function () {
    $tenant = pedidoTenant();
    $admin = pedidoUsuario($tenant);
    $despachador = pedidoUsuario($tenant, ['email' => 'd@cafeluna.com', 'rol' => 'Despachador']);

    $creado = $this->actingAs($despachador, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', pedidoDatosValidos())
        ->json();

    $response = $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$creado['id_pedido']}/estado", ['estado' => 'PUBLICADO'])
        ->assertOk()
        ->assertJsonPath('estado', 'PUBLICADO');

    expect($response->json('fecha_publicacion'))->not->toBeNull();

    tenancy()->initialize($tenant);
    expect(Auditoria::where('tabla_afectada', 'pedidos')->where('accion', 'CAMBIO_ESTADO')->exists())->toBeTrue();
    tenancy()->end();
});

it('rejects an invalid estado transition', function () {
    $tenant = pedidoTenant();
    $admin = pedidoUsuario($tenant);
    $despachador = pedidoUsuario($tenant, ['email' => 'd@cafeluna.com', 'rol' => 'Despachador']);

    $creado = $this->actingAs($despachador, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', pedidoDatosValidos())
        ->json();

    $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$creado['id_pedido']}/estado", ['estado' => 'ENTREGADO'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['estado']);
});

it('cancels a pedido and stamps fecha_cancelacion', function () {
    $tenant = pedidoTenant();
    $admin = pedidoUsuario($tenant);
    $despachador = pedidoUsuario($tenant, ['email' => 'd@cafeluna.com', 'rol' => 'Despachador']);

    $creado = $this->actingAs($despachador, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/pedidos', pedidoDatosValidos())
        ->json();

    $response = $this->actingAs($admin, 'usuario')
        ->patchJson("/api/v1/t/cafe-luna/pedidos/{$creado['id_pedido']}/estado", ['estado' => 'CANCELADO'])
        ->assertOk()
        ->assertJsonPath('estado', 'CANCELADO');

    expect($response->json('fecha_cancelacion'))->not->toBeNull();
});
