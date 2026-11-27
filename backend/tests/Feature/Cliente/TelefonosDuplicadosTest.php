<?php

use App\Models\Tenant;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\DireccionCliente;
use App\Models\Tenant\TelefonoEnRevision;
use App\Models\Tenant\Usuario;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
| spec tenant/035, D20 y RN-16 a RN-19: teléfonos repetidos y su conciliación desde el Panel.
*/

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

    $this->tenant = Tenant::create([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => 'cafe-luna',
    ]);
    tenancy()->initialize($this->tenant);
});

afterEach(function () {
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

function duplicadosUsuario(string $rol = 'AdminCliente'): Usuario
{
    return Usuario::create([
        'nombre' => 'Laura',
        'apellido_paterno' => 'Torres',
        'email' => strtolower($rol).'@cafeluna.com',
        'password' => bcrypt('Password123!'),
        'rol' => $rol,
        'estado' => 'Activo',
    ]);
}

function duplicadosCliente(string $nombre, ?string $telefono, string $estado = 'Activo'): Cliente
{
    return Cliente::create(['nombre' => $nombre, 'telefono' => $telefono, 'estado' => $estado]);
}

it('only reports by default and applies with --aplicar (RN-16)', function () {
    $unico = duplicadosCliente('Único', '461 100 0001');
    $a = duplicadosCliente('Ana', '4617778899');
    $b = duplicadosCliente('Ana WhatsApp', '+52 1 461 777 8899');
    duplicadosCliente('Inactivo', '4617778899', 'Inactivo');
    duplicadosCliente('Sin número', 'no sé');

    $this->artisan('clientes:normalizar-telefonos')
        ->expectsOutputToContain('Teléfonos repetidos: 1')
        ->expectsOutputToContain('Teléfonos inválidos: 1')
        ->assertSuccessful();

    tenancy()->initialize($this->tenant);
    expect($unico->fresh()->telefono_e164)->toBeNull()
        ->and(TelefonoEnRevision::count())->toBe(0);

    $this->artisan('clientes:normalizar-telefonos', ['--aplicar' => true])->assertSuccessful();

    tenancy()->initialize($this->tenant);
    expect($unico->fresh()->telefono_e164)->toBe('+524611000001')
        ->and($a->fresh()->telefono_e164)->toBeNull()
        ->and($b->fresh()->telefono_e164)->toBeNull()
        ->and(TelefonoEnRevision::find('+524617778899')->clientes)->toEqualCanonicalizing([$a->id_cliente, $b->id_cliente]);
});

it('keeps the Panel response the same and sends a repeated phone to review (RN-19)', function () {
    $existente = duplicadosCliente('Ana', '4617778899');
    $existente->forceFill(['telefono_e164' => '+524617778899'])->save();

    $this->actingAs(duplicadosUsuario(), 'usuario')
        ->postJson('/api/v1/t/cafe-luna/clientes', ['nombre' => 'Ana Torres', 'telefono' => '461-777-8899'])
        ->assertCreated()
        ->assertJsonPath('estado', 'Activo');

    $nuevo = Cliente::where('nombre', 'Ana Torres')->sole();
    expect($nuevo->origen)->toBe(Cliente::ORIGEN_PANEL)
        ->and($nuevo->telefono_e164)->toBeNull()
        ->and($existente->fresh()->telefono_e164)->toBeNull()
        ->and(TelefonoEnRevision::whereKey('+524617778899')->exists())->toBeTrue();
});

it('gives a unique phone its E.164 when the Panel saves it', function () {
    $this->actingAs(duplicadosUsuario(), 'usuario')
        ->postJson('/api/v1/t/cafe-luna/clientes', ['nombre' => 'Beto', 'telefono' => '461 111 2222'])
        ->assertCreated();

    expect(Cliente::where('nombre', 'Beto')->sole()->telefono_e164)->toBe('+524611112222');
});

it('lists phones to review and closes the ones that are no longer repeated', function () {
    $a = duplicadosCliente('Ana', '4617778899');
    $b = duplicadosCliente('Ana WhatsApp', '4617778899');
    duplicadosCliente('Carlos', '4613334444');
    duplicadosCliente('Carlos 2', '4613334444');
    $this->artisan('clientes:normalizar-telefonos', ['--aplicar' => true]);
    tenancy()->initialize($this->tenant);

    // Carlos 2 cambió de número: ese teléfono ya no está repetido.
    Cliente::where('nombre', 'Carlos 2')->update(['telefono' => '4619990000']);

    $this->actingAs(duplicadosUsuario(), 'usuario')
        ->getJson('/api/v1/t/cafe-luna/clientes/por-revisar')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.telefono_e164', '+524617778899')
        ->assertJsonCount(2, 'data.0.clientes');

    expect(TelefonoEnRevision::whereKey('+524613334444')->exists())->toBeFalse()
        ->and(Cliente::where('nombre', 'Carlos')->sole()->telefono_e164)->toBe('+524613334444');
});

it('unifies clients: moves history, keeps the app email and audits it (RN-18)', function () {
    $whatsapp = duplicadosCliente('Ana WhatsApp', '4617778899');
    $app = duplicadosCliente('Ana Torres', '4617778899');
    $app->forceFill(['email_acceso' => 'ana@ejemplo.com', 'email_verificado_en' => now()])->save();
    $token = $app->createToken('pide', [Cliente::HABILIDAD_APP]);
    DireccionCliente::create(['id_cliente' => $app->id_cliente, 'alias' => 'Casa', 'calle' => 'Hidalgo 10']);
    $this->artisan('clientes:normalizar-telefonos', ['--aplicar' => true]);
    tenancy()->initialize($this->tenant);

    $this->actingAs(duplicadosUsuario(), 'usuario')
        ->postJson('/api/v1/t/cafe-luna/clientes/unificar', [
            'telefono_e164' => '+524617778899',
            'id_principal' => $whatsapp->id_cliente,
        ])
        ->assertOk()
        ->assertJsonPath('id_cliente', $whatsapp->id_cliente);

    $principal = $whatsapp->fresh();
    expect($principal->email_acceso)->toBe('ana@ejemplo.com')
        ->and($principal->telefono_e164)->toBe('+524617778899')
        ->and($principal->direcciones()->count())->toBe(1)
        ->and($app->fresh()->estado)->toBe('Inactivo')
        ->and($app->fresh()->email_acceso)->toBeNull()
        ->and($app->tokens()->whereKey($token->accessToken->id)->exists())->toBeFalse()
        ->and(TelefonoEnRevision::count())->toBe(0)
        ->and(Auditoria::where('accion', 'UNIFICACION')->exists())->toBeTrue();
});

it('asks which app email to keep when two clients have one (RN-18)', function () {
    $a = duplicadosCliente('Ana', '4617778899');
    $a->forceFill(['email_acceso' => 'ana@ejemplo.com'])->save();
    $b = duplicadosCliente('Ana 2', '4617778899');
    $b->forceFill(['email_acceso' => 'ana2@ejemplo.com'])->save();
    $this->artisan('clientes:normalizar-telefonos', ['--aplicar' => true]);
    tenancy()->initialize($this->tenant);
    $admin = duplicadosUsuario();

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/clientes/unificar', ['telefono_e164' => '+524617778899', 'id_principal' => $a->id_cliente])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id_correo']);

    $this->actingAs($admin, 'usuario')
        ->postJson('/api/v1/t/cafe-luna/clientes/unificar', [
            'telefono_e164' => '+524617778899',
            'id_principal' => $a->id_cliente,
            'id_correo' => $b->id_cliente,
        ])
        ->assertOk();

    expect($a->fresh()->email_acceso)->toBe('ana2@ejemplo.com');
});

it('does not let a Despachador review or unify', function () {
    $despachador = duplicadosUsuario('Despachador');

    $this->actingAs($despachador, 'usuario')
        ->getJson('/api/v1/t/cafe-luna/clientes/por-revisar')
        ->assertForbidden();
});
