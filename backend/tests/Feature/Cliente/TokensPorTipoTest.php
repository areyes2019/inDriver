<?php

use App\Models\Tenant;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Usuario;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
| spec tenant/035, RN-14 y D4: el token de cada tipo de usuario solo sirve en sus rutas, y el de una
| flotilla no sirve en otra.
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
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

function tokensTenant(string $slug): Tenant
{
    $tenant = Tenant::create([
        'nombre_comercial' => ucfirst($slug),
        'razon_social' => ucfirst($slug).' SA de CV',
        'slug' => $slug,
    ]);

    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::APP_CLIENTES, 'Si');
    tenancy()->end();

    return $tenant;
}

/** @param  list<string>  $habilidades */
function tokensCliente(Tenant $tenant, array $habilidades = [Cliente::HABILIDAD_APP]): string
{
    tenancy()->initialize($tenant);
    $cliente = Cliente::create(['nombre' => 'Ana Torres', 'telefono' => '4617778899', 'estado' => 'Activo']);
    $cliente->forceFill(['email_acceso' => 'ana@ejemplo.com'])->save();
    $token = $cliente->createToken('pide', $habilidades)->plainTextToken;
    tenancy()->end();

    return $token;
}

function tokensConductor(Tenant $tenant): string
{
    tenancy()->initialize($tenant);
    $usuario = Usuario::create([
        'nombre' => 'Beto',
        'apellido_paterno' => 'Salgado',
        'email' => 'beto@ejemplo.com',
        'password' => bcrypt('Password123!'),
        'rol' => 'Conductor',
        'estado' => 'Activo',
    ]);
    $token = $usuario->createToken('panda-express')->plainTextToken;
    tenancy()->end();

    return $token;
}

it('accepts a client token on client routes', function () {
    $tenant = tokensTenant('cafe-luna');

    $this->withToken(tokensCliente($tenant))
        ->getJson('/api/v1/t/cafe-luna/cliente/perfil')
        ->assertOk();
});

it('rejects a driver token on client routes', function () {
    $tenant = tokensTenant('cafe-luna');

    $this->withToken(tokensConductor($tenant))
        ->getJson('/api/v1/t/cafe-luna/cliente/perfil')
        ->assertUnauthorized();
});

it('rejects a client token on driver routes', function () {
    $tenant = tokensTenant('cafe-luna');

    $this->withToken(tokensCliente($tenant))
        ->getJson('/api/v1/t/cafe-luna/conductor/me')
        ->assertUnauthorized();
});

it('rejects a client token from another fleet', function () {
    $cafe = tokensTenant('cafe-luna');
    tokensTenant('pan-sol');

    $this->withToken(tokensCliente($cafe))
        ->getJson('/api/v1/t/pan-sol/cliente/perfil')
        ->assertUnauthorized();
});

it('rejects a client token without the app ability', function () {
    $tenant = tokensTenant('cafe-luna');

    $this->withToken(tokensCliente($tenant, ['otra-cosa']))
        ->getJson('/api/v1/t/cafe-luna/cliente/perfil')
        ->assertUnauthorized();
});

it('closes the app to valid tokens once the fleet turns it off (RN-01)', function () {
    $tenant = tokensTenant('cafe-luna');
    $token = tokensCliente($tenant);

    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::APP_CLIENTES, 'No');
    tenancy()->end();

    $this->withToken($token)
        ->getJson('/api/v1/t/cafe-luna/cliente/perfil')
        ->assertForbidden()
        ->assertJsonPath('motivo', 'NO_DISPONIBLE');
});
