<?php

use App\Http\Middleware\SoloLecturaEspectador;
use App\Models\AdminCentral;
use App\Models\LogCentral;
use App\Models\Tenant;
use App\Models\Tenant\Usuario;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Modo espectador: ADMIN_CENTRAL mira el Panel real de un tenant sin poder mover nada.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

function espectadorAdmin(): AdminCentral
{
    return AdminCentral::create([
        'nombre' => 'Ana', 'apellido_paterno' => 'Pérez', 'email' => 'ana@example.com',
        'password' => bcrypt('Password123!'), 'estado' => 'Activo',
    ]);
}

function espectadorTenant(string $slug = 'cafe-luna', bool $conAdminCliente = true): Tenant
{
    $tenant = Tenant::create([
        'nombre_comercial' => 'Café Luna', 'razon_social' => 'Café Luna SA de CV', 'slug' => $slug,
    ]);

    if ($conAdminCliente) {
        tenancy()->initialize($tenant);
        Usuario::create([
            'nombre' => 'Laura', 'apellido_paterno' => 'Torres', 'email' => 'laura@cafeluna.com',
            'password' => bcrypt('Password123!'), 'rol' => 'AdminCliente', 'estado' => 'Activo',
        ]);
        tenancy()->end();
    }

    return $tenant;
}

function adminClienteDe(Tenant $tenant): Usuario
{
    tenancy()->initialize($tenant);
    $usuario = Usuario::where('rol', 'AdminCliente')->firstOrFail();
    tenancy()->end();

    return $usuario;
}

/**
 * @return array<string, array<string, int>>
 */
function sesionEspectador(Tenant $tenant, AdminCentral $admin): array
{
    return [SoloLecturaEspectador::SESION => ['id_tenant' => $tenant->id_tenant, 'id_admin' => $admin->id_admin]];
}

it('opens the tenant panel as the AdminCliente, flags the session and logs it centrally', function () {
    $admin = espectadorAdmin();
    $tenant = espectadorTenant();

    $this->actingAs($admin, 'admin')
        ->postJson("/api/v1/admin/tenants/{$tenant->id_tenant}/espectador")
        ->assertOk()
        ->assertJsonPath('slug', 'cafe-luna')
        ->assertSessionHas(SoloLecturaEspectador::SESION, [
            'id_tenant' => $tenant->id_tenant, 'id_admin' => $admin->id_admin,
        ]);

    expect(Auth::guard('usuario')->user()?->email)->toBe('laura@cafeluna.com')
        ->and(adminClienteDe($tenant)->ultimo_acceso)->toBeNull()
        ->and(LogCentral::where('accion', 'ESPECTADOR')->exists())->toBeTrue();
});

it('refuses to open the panel when the tenant has no active AdminCliente', function () {
    $admin = espectadorAdmin();
    $tenant = espectadorTenant(conAdminCliente: false);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/v1/admin/tenants/{$tenant->id_tenant}/espectador")
        ->assertUnprocessable();

    expect(Auth::guard('usuario')->check())->toBeFalse();
});

it('lets the spectator read the panel but blocks every write', function () {
    $admin = espectadorAdmin();
    $tenant = espectadorTenant();

    $this->actingAs($admin, 'admin')->actingAs(adminClienteDe($tenant), 'usuario')
        ->withSession(sesionEspectador($tenant, $admin))
        ->getJson('/api/v1/t/cafe-luna/me')
        ->assertOk()
        ->assertJsonPath('espectador', true);

    $this->getJson('/api/v1/t/cafe-luna/conductores')->assertOk();

    $this->postJson('/api/v1/t/cafe-luna/pedidos', [])
        ->assertForbidden()
        ->assertJsonPath('code', SoloLecturaEspectador::CODIGO);

    $this->putJson('/api/v1/t/cafe-luna/configuracion/modo-automatico', ['modo_automatico' => 'Si'])
        ->assertForbidden();
});

it('recognizes the central admin from the session, looking it up in the central database', function () {
    // Como en producción: el admin no está precargado (sin `actingAs`), solo su id en la sesión.
    // El guard lo busca en la base central aunque la petición ya tenga la tenencia inicializada.
    $admin = espectadorAdmin();
    $tenant = espectadorTenant();

    $this->actingAs(adminClienteDe($tenant), 'usuario')
        ->withSession([
            Auth::guard('admin')->getName() => $admin->id_admin,
            ...sesionEspectador($tenant, $admin),
        ])
        ->getJson('/api/v1/t/cafe-luna/me')
        ->assertOk()
        ->assertJsonPath('espectador', true);
});

it('ends the spectator session when the central admin is no longer logged in', function () {
    $admin = espectadorAdmin();
    $tenant = espectadorTenant();

    $this->actingAs(adminClienteDe($tenant), 'usuario')
        ->withSession(sesionEspectador($tenant, $admin))
        ->getJson('/api/v1/t/cafe-luna/conductores')
        ->assertUnauthorized();
});

it('ends the spectator session on another tenant panel', function () {
    $admin = espectadorAdmin();
    $tenant = espectadorTenant();
    $otro = espectadorTenant('otro-negocio');

    $this->actingAs($admin, 'admin')->actingAs(adminClienteDe($otro), 'usuario')
        ->withSession(sesionEspectador($tenant, $admin))
        ->getJson('/api/v1/t/otro-negocio/conductores')
        ->assertUnauthorized();
});

it('logs the spectator out of the panel without logging the admin out of central', function () {
    $admin = espectadorAdmin();
    $tenant = espectadorTenant();

    $this->actingAs($admin, 'admin')->actingAs(adminClienteDe($tenant), 'usuario')
        ->withSession(sesionEspectador($tenant, $admin))
        ->postJson('/api/v1/t/cafe-luna/logout')
        ->assertNoContent();

    $this->getJson('/api/v1/admin/me')->assertOk();
    expect(session()->has(SoloLecturaEspectador::SESION))->toBeFalse();
});

it('leaves a regular tenant session untouched', function () {
    $tenant = espectadorTenant();

    $this->actingAs(adminClienteDe($tenant), 'usuario')
        ->getJson('/api/v1/t/cafe-luna/me')
        ->assertOk()
        ->assertJsonPath('espectador', false);
});
