<?php

use App\Models\AdminCentral;
use App\Models\LogCentral;
use App\Models\PaqueteViaje;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);
});

function makePaqueteAdmin(): AdminCentral
{
    return AdminCentral::create([
        'nombre' => 'Ana',
        'apellido_paterno' => 'Pérez',
        'email' => 'ana@example.com',
        'password' => bcrypt('Password123!'),
        'estado' => 'Activo',
    ]);
}

it('rejects paquete creation without an admin session', function () {
    $this->postJson('/api/v1/admin/paquetes-viajes', [
        'codigo_paquete' => 'PKG-100',
        'nombre' => '100 viajes',
        'cantidad_viajes' => 100,
        'precio' => 500,
    ])->assertUnauthorized();
});

it('rejects paquete creation with missing or invalid fields', function () {
    $admin = makePaqueteAdmin();

    $this->actingAs($admin, 'admin')
        ->postJson('/api/v1/admin/paquetes-viajes', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['codigo_paquete', 'nombre', 'cantidad_viajes', 'precio']);
});

it('rejects a duplicate codigo_paquete, including one already soft-deleted', function () {
    $admin = makePaqueteAdmin();
    $paquete = PaqueteViaje::create([
        'codigo_paquete' => 'PKG-100',
        'nombre' => '100 viajes',
        'cantidad_viajes' => 100,
        'precio' => 500,
    ]);
    $paquete->delete();

    $this->actingAs($admin, 'admin')
        ->postJson('/api/v1/admin/paquetes-viajes', [
            'codigo_paquete' => 'PKG-100',
            'nombre' => 'Otro paquete',
            'cantidad_viajes' => 50,
            'precio' => 300,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['codigo_paquete']);
});

it('creates a paquete and logs the action', function () {
    $admin = makePaqueteAdmin();

    $response = $this->actingAs($admin, 'admin')
        ->postJson('/api/v1/admin/paquetes-viajes', [
            'codigo_paquete' => 'PKG-100',
            'nombre' => '100 viajes',
            'descripcion' => 'Paquete básico',
            'cantidad_viajes' => 100,
            'precio' => 500,
        ])
        ->assertCreated()
        ->assertJsonPath('estado', 'Activo');

    $paquete = PaqueteViaje::find($response->json('id_paquete'));

    expect($paquete)->not->toBeNull();

    expect(LogCentral::whereNull('id_tenant')
        ->where('id_admin', $admin->id_admin)
        ->where('tipo', 'PAQUETE')
        ->where('accion', 'ALTA')
        ->exists())->toBeTrue();
});

it('rejects listing paquetes without an admin session', function () {
    $this->getJson('/api/v1/admin/paquetes-viajes')->assertUnauthorized();
});

it('lists paquetes ordered by newest first, excludes soft-deleted, and filters by nombre', function () {
    $admin = makePaqueteAdmin();
    $viejo = PaqueteViaje::create([
        'codigo_paquete' => 'PKG-050',
        'nombre' => 'Paquete chico',
        'cantidad_viajes' => 50,
        'precio' => 300,
    ]);
    $nuevo = PaqueteViaje::create([
        'codigo_paquete' => 'PKG-100',
        'nombre' => 'Paquete grande',
        'cantidad_viajes' => 100,
        'precio' => 500,
    ]);
    $eliminado = PaqueteViaje::create([
        'codigo_paquete' => 'PKG-200',
        'nombre' => 'Paquete viejo',
        'cantidad_viajes' => 200,
        'precio' => 900,
    ]);
    $eliminado->delete();

    $response = $this->actingAs($admin, 'admin')
        ->getJson('/api/v1/admin/paquetes-viajes')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($response->json('data.0.id_paquete'))->toBe($nuevo->id_paquete);
    expect($response->json('data.1.id_paquete'))->toBe($viejo->id_paquete);

    $this->actingAs($admin, 'admin')
        ->getJson('/api/v1/admin/paquetes-viajes?search=chico')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id_paquete', $viejo->id_paquete);
});

it('updates a paquete, allows keeping its own codigo_paquete, and logs the action', function () {
    $admin = makePaqueteAdmin();
    $paquete = PaqueteViaje::create([
        'codigo_paquete' => 'PKG-100',
        'nombre' => '100 viajes',
        'cantidad_viajes' => 100,
        'precio' => 500,
    ]);

    $this->actingAs($admin, 'admin')
        ->putJson("/api/v1/admin/paquetes-viajes/{$paquete->id_paquete}", [
            'codigo_paquete' => 'PKG-100',
            'nombre' => '100 viajes (promo)',
            'cantidad_viajes' => 100,
            'precio' => 450,
        ])
        ->assertOk()
        ->assertJsonPath('nombre', '100 viajes (promo)');

    expect(LogCentral::where('tipo', 'PAQUETE')
        ->where('accion', 'EDICION')
        ->exists())->toBeTrue();
});

it('rejects updating a paquete with a codigo_paquete already used by another paquete', function () {
    $admin = makePaqueteAdmin();
    PaqueteViaje::create([
        'codigo_paquete' => 'PKG-100',
        'nombre' => '100 viajes',
        'cantidad_viajes' => 100,
        'precio' => 500,
    ]);
    $paquete = PaqueteViaje::create([
        'codigo_paquete' => 'PKG-050',
        'nombre' => '50 viajes',
        'cantidad_viajes' => 50,
        'precio' => 300,
    ]);

    $this->actingAs($admin, 'admin')
        ->putJson("/api/v1/admin/paquetes-viajes/{$paquete->id_paquete}", [
            'codigo_paquete' => 'PKG-100',
            'nombre' => '50 viajes',
            'cantidad_viajes' => 50,
            'precio' => 300,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['codigo_paquete']);
});

it('toggles a paquete estado between Activo and Inactivo, and logs it', function () {
    $admin = makePaqueteAdmin();
    $paquete = PaqueteViaje::create([
        'codigo_paquete' => 'PKG-100',
        'nombre' => '100 viajes',
        'cantidad_viajes' => 100,
        'precio' => 500,
    ]);

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/v1/admin/paquetes-viajes/{$paquete->id_paquete}/estado")
        ->assertOk()
        ->assertJsonPath('estado', 'Inactivo');

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/v1/admin/paquetes-viajes/{$paquete->id_paquete}/estado")
        ->assertOk()
        ->assertJsonPath('estado', 'Activo');

    expect(LogCentral::where('tipo', 'PAQUETE')
        ->where('accion', 'CAMBIO_ESTADO')
        ->count())->toBe(2);
});

it('soft-deletes a paquete, hides it from listing, and logs it', function () {
    $admin = makePaqueteAdmin();
    $paquete = PaqueteViaje::create([
        'codigo_paquete' => 'PKG-100',
        'nombre' => '100 viajes',
        'cantidad_viajes' => 100,
        'precio' => 500,
    ]);

    $this->actingAs($admin, 'admin')
        ->deleteJson("/api/v1/admin/paquetes-viajes/{$paquete->id_paquete}")
        ->assertNoContent();

    expect(PaqueteViaje::withTrashed()->find($paquete->id_paquete)->trashed())->toBeTrue();

    $this->actingAs($admin, 'admin')
        ->getJson('/api/v1/admin/paquetes-viajes')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect(LogCentral::where('tipo', 'PAQUETE')
        ->where('accion', 'BAJA')
        ->exists())->toBeTrue();
});
