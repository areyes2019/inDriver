<?php

use App\Models\Tenant;
use App\Models\Tenant\Usuario;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);

    // Defensivo: si un archivo sqlite de un tenant de prueba de OTRO archivo de test quedó sin
    // borrar (Windows a veces tarda en soltar el handle de SQLite tras el purge/GC del test
    // anterior), no debe tumbar el primer test de este archivo con un "la base ya existe".
    gc_collect_cycles();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        if (! File::delete($file)) {
            usleep(50000);
            File::delete($file);
        }
    }
});

afterEach(function () {
    // Las peticiones HTTP a /t/{slug}/... dejan la tenencia activa (tenant.slug no llama
    // tenancy()->end(), ver su comentario); en producción cada petición arranca el framework
    // desde cero, pero aquí todo el test suite comparte un mismo proceso PHP, así que hay que
    // devolver la conexión por defecto a la central antes del siguiente test.
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

function makeTestTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function makeTenantUsuario(Tenant $tenant, array $overrides = []): Usuario
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

it('404s on login with a slug that does not exist', function () {
    $this->postJson('/api/v1/t/no-existe/login', [
        'email' => 'laura@cafeluna.com',
        'password' => 'Password123!',
    ])->assertNotFound();
});

it('logs in with valid credentials and starts a usuario session', function () {
    $tenant = makeTestTenant();
    makeTenantUsuario($tenant);

    $this->postJson('/api/v1/t/cafe-luna/login', [
        'email' => 'laura@cafeluna.com',
        'password' => 'Password123!',
    ])->assertOk()->assertJsonPath('email', 'laura@cafeluna.com')->assertJsonMissingPath('password');

    $this->assertAuthenticated('usuario');
});

it('rejects invalid credentials with a generic message', function () {
    $tenant = makeTestTenant();
    makeTenantUsuario($tenant);

    $this->postJson('/api/v1/t/cafe-luna/login', [
        'email' => 'laura@cafeluna.com',
        'password' => 'incorrecta',
    ])->assertUnprocessable();

    $this->assertGuest('usuario');
});

it('rejects login for a non-active usuario', function () {
    $tenant = makeTestTenant();
    makeTenantUsuario($tenant, ['estado' => 'Suspendido']);

    $this->postJson('/api/v1/t/cafe-luna/login', [
        'email' => 'laura@cafeluna.com',
        'password' => 'Password123!',
    ])->assertUnprocessable();

    $this->assertGuest('usuario');
});

it('does not authenticate an email from a different tenant', function () {
    $tenantA = makeTestTenant(['nombre_comercial' => 'Café Luna', 'slug' => 'cafe-luna']);
    makeTenantUsuario($tenantA);
    makeTestTenant(['nombre_comercial' => 'Tacos El Sol', 'slug' => 'tacos-el-sol']);

    $this->postJson('/api/v1/t/tacos-el-sol/login', [
        'email' => 'laura@cafeluna.com',
        'password' => 'Password123!',
    ])->assertUnprocessable();
});

it('throttles login after 5 failed attempts per minute', function () {
    $tenant = makeTestTenant();
    makeTenantUsuario($tenant);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/t/cafe-luna/login', [
            'email' => 'laura@cafeluna.com',
            'password' => 'incorrecta',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/t/cafe-luna/login', [
        'email' => 'laura@cafeluna.com',
        'password' => 'incorrecta',
    ])->assertStatus(429);
});

it('returns the authenticated usuario on /me', function () {
    $tenant = makeTestTenant();
    $usuario = makeTenantUsuario($tenant);

    $this->actingAs($usuario, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/me')
        ->assertOk()
        ->assertJsonPath('email', $usuario->email)
        ->assertJsonMissingPath('password');
});

it('rejects /me without a session', function () {
    makeTestTenant();

    $this->getJson('/api/v1/t/cafe-luna/me')->assertUnauthorized();
});

it('logs out and invalidates the session', function () {
    $tenant = makeTestTenant();
    $usuario = makeTenantUsuario($tenant);

    $this->actingAs($usuario, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/logout')
        ->assertNoContent();

    $this->getJson('/api/v1/t/cafe-luna/me')->assertUnauthorized();
});

it('sends a password reset notification for an existing email', function () {
    Notification::fake();
    $tenant = makeTestTenant();
    $usuario = makeTenantUsuario($tenant);

    $this->postJson('/api/v1/t/cafe-luna/forgot-password', ['email' => $usuario->email])
        ->assertOk();

    Notification::assertSentTo($usuario, ResetPassword::class);
});

it('resets the password with a valid token and allows login with the new one', function () {
    $tenant = makeTestTenant();
    $usuario = makeTenantUsuario($tenant);

    tenancy()->initialize($tenant);
    $token = Password::broker('usuarios')->createToken($usuario);
    tenancy()->end();

    $this->postJson('/api/v1/t/cafe-luna/reset-password', [
        'token' => $token,
        'email' => $usuario->email,
        'password' => 'NuevaPassword123!',
        'password_confirmation' => 'NuevaPassword123!',
    ])->assertOk();

    $this->postJson('/api/v1/t/cafe-luna/login', [
        'email' => $usuario->email,
        'password' => 'NuevaPassword123!',
    ])->assertOk();
});

it('rejects /cambiar-password without a session', function () {
    makeTestTenant();

    $this->postJson('/api/v1/t/cafe-luna/cambiar-password', [
        'password_actual' => 'Password123!',
        'password' => 'NuevaPassword123!',
        'password_confirmation' => 'NuevaPassword123!',
    ])->assertUnauthorized();
});

it('rejects changing the password with an incorrect current password', function () {
    $tenant = makeTestTenant();
    $usuario = makeTenantUsuario($tenant);

    $this->actingAs($usuario, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/cambiar-password', [
            'password_actual' => 'incorrecta',
            'password' => 'NuevaPassword123!',
            'password_confirmation' => 'NuevaPassword123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('password_actual');

    $this->postJson('/api/v1/t/cafe-luna/login', [
        'email' => $usuario->email,
        'password' => 'Password123!',
    ])->assertOk();
});

it('changes the own password, keeps the session, and allows login with the new password', function () {
    $tenant = makeTestTenant();
    $usuario = makeTenantUsuario($tenant);

    $this->actingAs($usuario, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/cambiar-password', [
            'password_actual' => 'Password123!',
            'password' => 'NuevaPassword123!',
            'password_confirmation' => 'NuevaPassword123!',
        ])->assertOk();

    $this->assertAuthenticated('usuario');

    $this->postJson('/api/v1/t/cafe-luna/login', [
        'email' => $usuario->email,
        'password' => 'NuevaPassword123!',
    ])->assertOk();
});

it('applies to a Despachador the same as an AdminCliente', function () {
    $tenant = makeTestTenant();
    $usuario = makeTenantUsuario($tenant, ['rol' => 'Despachador', 'email' => 'despachador@cafeluna.com']);

    $this->actingAs($usuario, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/cambiar-password', [
            'password_actual' => 'Password123!',
            'password' => 'NuevaPassword123!',
            'password_confirmation' => 'NuevaPassword123!',
        ])->assertOk();

    $this->postJson('/api/v1/t/cafe-luna/login', [
        'email' => $usuario->email,
        'password' => 'NuevaPassword123!',
    ])->assertOk();
});
