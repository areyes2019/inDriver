<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
| spec tenant/035, RN-05: cada flotilla tiene su propio manifiesto, así que el teléfono instala dos
| flotillas como dos apps distintas.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.frontend_url' => 'https://motopanda.mx']);

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

function manifiestoTenant(string $slug, string $nombre): Tenant
{
    return Tenant::create(['nombre_comercial' => $nombre, 'razon_social' => "{$nombre} SA", 'slug' => $slug]);
}

it('serves a manifest with the fleet name, its own id and scope, and the icons', function () {
    manifiestoTenant('moto-celaya', 'Moto Celaya');

    $this->get('/api/v1/t/moto-celaya/cliente/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('name', 'Moto Celaya')
        ->assertJsonPath('id', 'https://motopanda.mx/pide/moto-celaya/')
        ->assertJsonPath('start_url', 'https://motopanda.mx/pide/moto-celaya/')
        ->assertJsonPath('scope', 'https://motopanda.mx/pide/moto-celaya/')
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('icons.2.purpose', 'maskable');
});

it('gives two fleets two different apps', function () {
    manifiestoTenant('moto-celaya', 'Moto Celaya');
    manifiestoTenant('envios-celaya', 'Envíos Celaya');

    $uno = $this->get('/api/v1/t/moto-celaya/cliente/manifest.webmanifest')->json();
    $dos = $this->get('/api/v1/t/envios-celaya/cliente/manifest.webmanifest')->json();

    expect($uno['id'])->not->toBe($dos['id'])
        ->and($uno['scope'])->not->toBe($dos['scope'])
        ->and($dos['name'])->toBe('Envíos Celaya');
});

it('serves the manifest even with the app turned off, so the app can show "Muy pronto"', function () {
    manifiestoTenant('moto-celaya', 'Moto Celaya');

    $this->get('/api/v1/t/moto-celaya/cliente/manifest.webmanifest')->assertOk();
});

it('answers 404 for an unknown fleet', function () {
    $this->get('/api/v1/t/no-existe/cliente/manifest.webmanifest')->assertNotFound();
});
