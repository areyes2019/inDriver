<?php

use App\Models\Tenant;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\DireccionCliente;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
| spec tenant/035, RN-56: direcciones favoritas del cliente, en la misma tabla del Panel. Cada cliente
| solo ve y cambia las suyas.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    gc_collect_cycles();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        if (! File::delete($file)) {
            usleep(50000);
            File::delete($file);
        }
    }

    $this->tenant = Tenant::create(['nombre_comercial' => 'Moto Celaya', 'razon_social' => 'Moto Celaya SA', 'slug' => 'moto-celaya']);
    tenancy()->initialize($this->tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::APP_CLIENTES, 'Si');
    $this->ana = Cliente::create(['nombre' => 'Ana Torres', 'telefono' => '4617778899', 'estado' => 'Activo']);
    $this->beto = Cliente::create(['nombre' => 'Beto Ruiz', 'telefono' => '4611112222', 'estado' => 'Activo']);
    $this->tokenAna = $this->ana->createToken('pide', [Cliente::HABILIDAD_APP])->plainTextToken;
    tenancy()->end();
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

function direccionCasa(array $cambios = []): array
{
    return array_merge([
        'alias' => 'Casa',
        'direccion' => 'Av. Tecnológico 1205, Alameda, Celaya, Gto.',
        'lat' => 20.5398,
        'lng' => -100.8166,
        'referencias' => 'Portón negro',
    ], $cambios);
}

it('saves, lists, edits and deletes the client\'s own addresses', function () {
    $api = fn () => $this->withToken($this->tokenAna);

    $id = $api()->postJson('/api/v1/t/moto-celaya/cliente/direcciones', direccionCasa())
        ->assertCreated()
        ->assertJsonPath('alias', 'Casa')
        ->assertJsonPath('referencias', 'Portón negro')
        ->json('id');

    $api()->getJson('/api/v1/t/moto-celaya/cliente/direcciones')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.lat', 20.5398);

    $api()->putJson("/api/v1/t/moto-celaya/cliente/direcciones/{$id}", direccionCasa(['referencias' => 'Timbre 2']))
        ->assertOk()
        ->assertJsonPath('referencias', 'Timbre 2');

    // La ve el Panel: es la misma tabla.
    tenancy()->initialize($this->tenant);
    expect(DireccionCliente::find($id)->calle)->toBe('Av. Tecnológico 1205, Alameda, Celaya, Gto.');
    tenancy()->end();

    $api()->deleteJson("/api/v1/t/moto-celaya/cliente/direcciones/{$id}")->assertNoContent();
    $api()->getJson('/api/v1/t/moto-celaya/cliente/direcciones')->assertJsonCount(0, 'data');
});

it('answers 404 for another client\'s address', function () {
    tenancy()->initialize($this->tenant);
    $deBeto = $this->beto->direcciones()->create(['alias' => 'Casa', 'calle' => 'Otra', 'latitud' => 20.5, 'longitud' => -100.8]);
    tenancy()->end();

    $this->withToken($this->tokenAna)
        ->putJson("/api/v1/t/moto-celaya/cliente/direcciones/{$deBeto->id_direccion}", direccionCasa())
        ->assertNotFound();
    $this->withToken($this->tokenAna)
        ->deleteJson("/api/v1/t/moto-celaya/cliente/direcciones/{$deBeto->id_direccion}")
        ->assertNotFound();
});

it('keeps at most 10 addresses per client (RN-56)', function () {
    tenancy()->initialize($this->tenant);
    foreach (range(1, 10) as $n) {
        $this->ana->direcciones()->create(['alias' => "Lugar {$n}", 'calle' => "Calle {$n}", 'latitud' => 20.5, 'longitud' => -100.8]);
    }
    tenancy()->end();

    $this->withToken($this->tokenAna)
        ->postJson('/api/v1/t/moto-celaya/cliente/direcciones', direccionCasa())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alias']);
});
