<?php

use App\Models\Tenant;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Usuario;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Logo y banner de la app de clientes (spec tenant/035, RN-60 a RN-62): se suben desde la
 * Configuración, se sirven por una ruta pública con caché y salen en `/cliente/marca` y en el
 * manifiesto.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);

    $this->tenant = Tenant::create([
        'nombre_comercial' => 'Moto Celaya', 'razon_social' => 'Moto Celaya SA de CV', 'slug' => 'moto-celaya',
    ]);

    tenancy()->initialize($this->tenant);
    $this->admin = Usuario::create([
        'nombre' => 'Laura', 'apellido_paterno' => 'Torres', 'email' => 'laura@motocelaya.com',
        'password' => bcrypt('Password123!'), 'rol' => 'AdminCliente', 'estado' => 'Activo',
    ]);
    $this->despachador = Usuario::create([
        'nombre' => 'Beto', 'apellido_paterno' => 'Lara', 'email' => 'beto@motocelaya.com',
        'password' => bcrypt('Password123!'), 'rol' => 'Despachador', 'estado' => 'Activo',
    ]);
    tenancy()->end();
});

afterEach(function () {
    // Los archivos de marca quedan en el disco del tenant, no en la base: se borran a mano.
    tenancy()->initialize($this->tenant);
    Storage::disk('local')->deleteDirectory('marca');
    tenancy()->end();

    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

/**
 * @return list<string>
 */
function archivosDeMarca(Tenant $tenant): array
{
    tenancy()->initialize($tenant);
    $archivos = Storage::disk('local')->files('marca');
    tenancy()->end();

    return array_map('basename', $archivos);
}

it('only lets the AdminCliente upload the logo', function () {
    $this->actingAs($this->despachador, 'usuario')
        ->postJson('/api/v1/t/moto-celaya/configuracion/app-clientes/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 600, 600),
        ])
        ->assertForbidden();
});

it('saves the logo, generates the install icons and serves them publicly with a long cache', function () {
    $respuesta = $this->actingAs($this->admin, 'usuario')
        ->postJson('/api/v1/t/moto-celaya/configuracion/app-clientes/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 600, 600),
        ])
        ->assertOk();

    $logoUrl = $respuesta->json('logo_url');
    expect($logoUrl)->toContain('/api/v1/t/moto-celaya/cliente/marca/archivos/logo-');

    $archivos = archivosDeMarca($this->tenant);
    expect($archivos)->toHaveCount(3)
        ->and(collect($archivos)->contains(fn ($a) => str_starts_with($a, 'icono-192-')))->toBeTrue()
        ->and(collect($archivos)->contains(fn ($a) => str_starts_with($a, 'maskable-')))->toBeTrue();

    $imagen = $this->get(parse_url($logoUrl, PHP_URL_PATH))->assertOk();
    expect($imagen->headers->get('Content-Type'))->toBe('image/png')
        ->and($imagen->headers->get('Cache-Control'))->toContain('immutable')
        ->and(getimagesizefromstring($imagen->getContent())[0])->toBe(512);

    $this->getJson('/api/v1/t/moto-celaya/cliente/marca')->assertJsonPath('logo_url', $logoUrl);

    $iconos = collect($this->getJson('/api/v1/t/moto-celaya/cliente/manifest.webmanifest')->json('icons'))->pluck('src');
    expect($iconos->every(fn ($src) => str_contains($src, '/cliente/marca/archivos/')))->toBeTrue();

    tenancy()->initialize($this->tenant);
    expect(Auditoria::where('descripcion', 'Se cambió el logo de la app de clientes')->exists())->toBeTrue();
    tenancy()->end();
});

it('rejects a logo that is not square, too small or too heavy', function (UploadedFile $archivo) {
    $this->actingAs($this->admin, 'usuario')
        ->postJson('/api/v1/t/moto-celaya/configuracion/app-clientes/logo', ['logo' => $archivo])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logo']);

    expect(archivosDeMarca($this->tenant))->toBe([]);
})->with([
    'no cuadrado' => fn () => UploadedFile::fake()->image('logo.png', 800, 600),
    'muy chico' => fn () => UploadedFile::fake()->image('logo.png', 300, 300),
    'muy pesado' => fn () => UploadedFile::fake()->image('logo.png', 600, 600)->size(1500),
    'no es imagen' => fn () => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
]);

it('replaces the previous logo files instead of piling them up', function () {
    foreach ([1, 2] as $_) {
        $this->actingAs($this->admin, 'usuario')
            ->postJson('/api/v1/t/moto-celaya/configuracion/app-clientes/logo', [
                'logo' => UploadedFile::fake()->image('logo.png', 600, 600),
            ])
            ->assertOk();
    }

    expect(archivosDeMarca($this->tenant))->toHaveCount(3);
});

it('saves a horizontal banner and rejects a vertical one', function () {
    $this->actingAs($this->admin, 'usuario')
        ->postJson('/api/v1/t/moto-celaya/configuracion/app-clientes/banner', [
            'banner' => UploadedFile::fake()->image('banner.jpg', 600, 900),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['banner']);

    $bannerUrl = $this->actingAs($this->admin, 'usuario')
        ->postJson('/api/v1/t/moto-celaya/configuracion/app-clientes/banner', [
            'banner' => UploadedFile::fake()->image('banner.jpg', 2400, 1080),
        ])
        ->assertOk()
        ->json('banner_url');

    $imagen = $this->get(parse_url($bannerUrl, PHP_URL_PATH))->assertOk();
    // Se guarda a lo más de 1600 de ancho, conservando la proporción.
    expect(getimagesizefromstring($imagen->getContent())[0])->toBe(1600)
        ->and($imagen->headers->get('Content-Type'))->toBe('image/jpeg');
});

it('removes the logo and the banner, and the app goes back to the MotoPanda ones', function () {
    $this->actingAs($this->admin, 'usuario')
        ->postJson('/api/v1/t/moto-celaya/configuracion/app-clientes/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 600, 600),
        ]);
    $this->actingAs($this->admin, 'usuario')
        ->postJson('/api/v1/t/moto-celaya/configuracion/app-clientes/banner', [
            'banner' => UploadedFile::fake()->image('banner.png', 1200, 540),
        ]);

    $this->actingAs($this->admin, 'usuario')->deleteJson('/api/v1/t/moto-celaya/configuracion/app-clientes/logo')
        ->assertOk()->assertJsonPath('logo_url', null);
    $this->actingAs($this->admin, 'usuario')->deleteJson('/api/v1/t/moto-celaya/configuracion/app-clientes/banner')
        ->assertOk()->assertJsonPath('banner_url', null);

    expect(archivosDeMarca($this->tenant))->toBe([]);

    $this->getJson('/api/v1/t/moto-celaya/cliente/marca')
        ->assertJsonPath('logo_url', null)
        ->assertJsonPath('banner_url', null);

    $iconos = collect($this->getJson('/api/v1/t/moto-celaya/cliente/manifest.webmanifest')->json('icons'))->pluck('src');
    expect($iconos->every(fn ($src) => str_contains($src, '/pide/iconos/')))->toBeTrue();

    tenancy()->initialize($this->tenant);
    expect(ConfiguracionTenant::obtener(ConfiguracionTenant::APP_LOGO))->toBeNull();
    tenancy()->end();
});

it('serves only brand file names and 404s anything else', function () {
    $this->get('/api/v1/t/moto-celaya/cliente/marca/archivos/logo-abcdefghijkl.png')->assertNotFound();
    $this->get('/api/v1/t/moto-celaya/cliente/marca/archivos/..%2F..%2F.env')->assertNotFound();
    $this->get('/api/v1/t/moto-celaya/cliente/marca/archivos/otro.png')->assertNotFound();
});
