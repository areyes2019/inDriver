<?php

use App\Models\Tenant;
use App\Models\Tenant\Despachador;
use App\Models\Tenant\Usuario;
use App\Notifications\CredencialesUsuarioTenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

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
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

function usuarioTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ], $overrides));
}

function usuarioEnTenant(Tenant $tenant, array $overrides = []): Usuario
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

it('rejects listing usuarios without a session', function () {
    usuarioTenant();

    $this->getJson('/api/v1/t/cafe-luna/usuarios')->assertUnauthorized();
});

it('rejects usuarios management for non-AdminCliente roles', function () {
    $tenant = usuarioTenant();
    $conductor = usuarioEnTenant($tenant, ['email' => 'conductor@cafeluna.com', 'rol' => 'Conductor']);

    $this->actingAs($conductor, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/usuarios')
        ->assertForbidden();
});

it('lists usuarios and filters by search', function () {
    $tenant = usuarioTenant();
    $admin = usuarioEnTenant($tenant);
    usuarioEnTenant($tenant, ['nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com', 'rol' => 'Despachador']);

    $this->actingAs($admin, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/usuarios?search=Pedro')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'pedro@cafeluna.com');
});

it('creates a usuario with a generated password and sends the credentials email', function () {
    Notification::fake();
    $tenant = usuarioTenant();
    $admin = usuarioEnTenant($tenant);

    $response = $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/usuarios', [
            'nombre' => 'Pedro',
            'apellido_paterno' => 'Ruiz',
            'email' => 'pedro@cafeluna.com',
            'rol' => 'Despachador',
        ])
        ->assertCreated()
        ->assertJsonMissingPath('password');

    expect($response->json('rol'))->toBe('Despachador');
    expect($response->json('estado'))->toBe('Activo');

    Notification::assertSentOnDemand(
        CredencialesUsuarioTenant::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'pedro@cafeluna.com',
    );
});

it('rejects creating a usuario with a duplicate email', function () {
    $tenant = usuarioTenant();
    $admin = usuarioEnTenant($tenant);

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/usuarios', [
            'nombre' => 'Otra',
            'apellido_paterno' => 'Persona',
            'email' => 'laura@cafeluna.com',
            'rol' => 'Conductor',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('updates a usuario', function () {
    $tenant = usuarioTenant();
    $admin = usuarioEnTenant($tenant);
    $otro = usuarioEnTenant($tenant, ['nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com', 'rol' => 'Despachador']);

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/usuarios/{$otro->id_usuario}", [
            'nombre' => 'Pedro',
            'apellido_paterno' => 'Ruiz Nuevo',
            'email' => 'pedro@cafeluna.com',
            'rol' => 'Despachador',
            'estado' => 'Suspendido',
        ])
        ->assertOk()
        ->assertJsonPath('apellido_paterno', 'Ruiz Nuevo')
        ->assertJsonPath('estado', 'Suspendido');
});

it('rejects changing your own rol', function () {
    $tenant = usuarioTenant();
    $admin = usuarioEnTenant($tenant);

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/usuarios/{$admin->id_usuario}", [
            'nombre' => $admin->nombre,
            'apellido_paterno' => $admin->apellido_paterno,
            'email' => $admin->email,
            'rol' => 'Despachador',
            'estado' => 'Activo',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rol']);
});

it('rejects deleting your own usuario', function () {
    $tenant = usuarioTenant();
    $admin = usuarioEnTenant($tenant);

    $this->actingAs($admin, 'usuario')
        ->deleteJson("/api/v1/t/cafe-luna/usuarios/{$admin->id_usuario}", ['password' => 'Password123!'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

it('rejects deleting a usuario with the wrong password, without deleting it', function () {
    $tenant = usuarioTenant();
    $admin = usuarioEnTenant($tenant);
    $otro = usuarioEnTenant($tenant, ['nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com', 'rol' => 'Despachador']);

    $this->actingAs($admin, 'usuario')
        ->deleteJson("/api/v1/t/cafe-luna/usuarios/{$otro->id_usuario}", ['password' => 'incorrecta'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    tenancy()->initialize($tenant);
    expect(Usuario::find($otro->id_usuario))->not->toBeNull();
    tenancy()->end();
});

it('deletes a usuario when the password is correct', function () {
    $tenant = usuarioTenant();
    $admin = usuarioEnTenant($tenant);
    $otro = usuarioEnTenant($tenant, ['nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com', 'rol' => 'Despachador']);

    $this->actingAs($admin, 'usuario')
        ->deleteJson("/api/v1/t/cafe-luna/usuarios/{$otro->id_usuario}", ['password' => 'Password123!'])
        ->assertOk();

    tenancy()->initialize($tenant);
    expect(Usuario::find($otro->id_usuario))->toBeNull();
    tenancy()->end();
});

it('creates a despachador profile when a usuario is created with rol Despachador', function () {
    Notification::fake();
    $tenant = usuarioTenant();
    $admin = usuarioEnTenant($tenant);

    $response = $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/usuarios', [
            'nombre' => 'Pedro',
            'apellido_paterno' => 'Ruiz',
            'email' => 'pedro@cafeluna.com',
            'rol' => 'Despachador',
        ])
        ->assertCreated();

    tenancy()->initialize($tenant);
    $despachador = Despachador::where('id_usuario', $response->json('id_usuario'))->first();
    expect($despachador)->not->toBeNull();
    expect($despachador->estado)->toBe('Activo');
    tenancy()->end();
});

it('does not create a despachador profile for other roles', function () {
    Notification::fake();
    $tenant = usuarioTenant();
    $admin = usuarioEnTenant($tenant);

    $response = $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/usuarios', [
            'nombre' => 'Pedro',
            'apellido_paterno' => 'Ruiz',
            'email' => 'pedro@cafeluna.com',
            'rol' => 'Conductor',
        ])
        ->assertCreated();

    tenancy()->initialize($tenant);
    expect(Despachador::where('id_usuario', $response->json('id_usuario'))->exists())->toBeFalse();
    tenancy()->end();
});

it('creates a despachador profile when a usuario rol changes to Despachador', function () {
    $tenant = usuarioTenant();
    $admin = usuarioEnTenant($tenant);
    $otro = usuarioEnTenant($tenant, ['nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com', 'rol' => 'Conductor']);

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/usuarios/{$otro->id_usuario}", [
            'nombre' => 'Pedro',
            'apellido_paterno' => 'Ruiz',
            'email' => 'pedro@cafeluna.com',
            'rol' => 'Despachador',
            'estado' => 'Activo',
        ])
        ->assertOk();

    tenancy()->initialize($tenant);
    expect(Despachador::where('id_usuario', $otro->id_usuario)->exists())->toBeTrue();
    tenancy()->end();
});

it('removes the despachador profile when a usuario rol changes away from Despachador', function () {
    $tenant = usuarioTenant();
    $admin = usuarioEnTenant($tenant);
    $otro = usuarioEnTenant($tenant, ['nombre' => 'Pedro', 'apellido_paterno' => 'Ruiz', 'email' => 'pedro@cafeluna.com', 'rol' => 'Despachador']);

    tenancy()->initialize($tenant);
    Despachador::create(['id_usuario' => $otro->id_usuario, 'estado' => 'Activo']);
    tenancy()->end();

    $this->actingAs($admin, 'usuario')
        ->putJson("/api/v1/t/cafe-luna/usuarios/{$otro->id_usuario}", [
            'nombre' => 'Pedro',
            'apellido_paterno' => 'Ruiz',
            'email' => 'pedro@cafeluna.com',
            'rol' => 'Conductor',
            'estado' => 'Activo',
        ])
        ->assertOk();

    tenancy()->initialize($tenant);
    expect(Despachador::where('id_usuario', $otro->id_usuario)->exists())->toBeFalse();
    tenancy()->end();
});
