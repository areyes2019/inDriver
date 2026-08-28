<?php

use App\Models\AdminCentral;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Simula una petición del SPA (dominio stateful de Sanctum) para que arranque la sesión;
    // la verificación de CSRF se desactiva porque no es lo que estas pruebas verifican.
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);
});

function makeAdmin(array $overrides = []): AdminCentral
{
    return AdminCentral::create(array_merge([
        'nombre' => 'Ana',
        'apellido_paterno' => 'Pérez',
        'email' => 'ana@example.com',
        'password' => bcrypt('Password123!'),
        'estado' => 'Activo',
    ], $overrides));
}

it('logs in with valid credentials and starts a session', function () {
    makeAdmin();

    $this->postJson('/api/v1/admin/login', [
        'email' => 'ana@example.com',
        'password' => 'Password123!',
    ])->assertOk()->assertJsonPath('email', 'ana@example.com')->assertJsonMissingPath('password');

    $this->assertAuthenticated('admin');
});

it('rejects invalid credentials with a generic message', function () {
    makeAdmin();

    $this->postJson('/api/v1/admin/login', [
        'email' => 'ana@example.com',
        'password' => 'incorrecta',
    ])->assertUnprocessable();

    $this->assertGuest('admin');
});

it('rejects login for a non-active admin with the same generic message', function () {
    makeAdmin(['estado' => 'Suspendido']);

    $this->postJson('/api/v1/admin/login', [
        'email' => 'ana@example.com',
        'password' => 'Password123!',
    ])->assertUnprocessable();

    $this->assertGuest('admin');
});

it('throttles login after 5 failed attempts per minute', function () {
    makeAdmin();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/admin/login', [
            'email' => 'ana@example.com',
            'password' => 'incorrecta',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/admin/login', [
        'email' => 'ana@example.com',
        'password' => 'incorrecta',
    ])->assertStatus(429);
});

it('returns the authenticated admin on /me', function () {
    $admin = makeAdmin();

    $this->actingAs($admin, 'admin')
        ->getJson('/api/v1/admin/me')
        ->assertOk()
        ->assertJsonPath('email', $admin->email)
        ->assertJsonMissingPath('password');
});

it('rejects /me without a session', function () {
    $this->getJson('/api/v1/admin/me')->assertUnauthorized();
});

it('logs out and invalidates the session', function () {
    $admin = makeAdmin();

    $this->actingAs($admin, 'admin')
        ->postJson('/api/v1/admin/logout')
        ->assertNoContent();

    $this->getJson('/api/v1/admin/me')->assertUnauthorized();
});

it('sends a password reset notification for an existing email', function () {
    Notification::fake();
    $admin = makeAdmin();

    $this->postJson('/api/v1/admin/forgot-password', ['email' => $admin->email])
        ->assertOk();

    Notification::assertSentTo($admin, ResetPassword::class);
});

it('resets the password with a valid token and allows login with the new one', function () {
    $admin = makeAdmin();

    $token = Password::broker('admins_centrales')->createToken($admin);

    $this->postJson('/api/v1/admin/reset-password', [
        'token' => $token,
        'email' => $admin->email,
        'password' => 'NuevaPassword123!',
        'password_confirmation' => 'NuevaPassword123!',
    ])->assertOk();

    $this->postJson('/api/v1/admin/login', [
        'email' => $admin->email,
        'password' => 'NuevaPassword123!',
    ])->assertOk();
});

it('has a reserved rol column that stays unused', function () {
    $admin = makeAdmin();

    expect($admin->rol)->toBeNull();
});
