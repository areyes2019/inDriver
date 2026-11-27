<?php

use App\Mail\CodigoAccesoMail;
use App\Models\Tenant;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\TelefonoEnRevision;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/*
| spec tenant/035, Fase 3: registro y entrada a la app de clientes con un código por correo
| (RN-01, RN-03, RN-04, RN-10 a RN-15, RN-57).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    Mail::fake();

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

function pideTenant(string $slug = 'cafe-luna', bool $appPrendida = true): Tenant
{
    $tenant = Tenant::create([
        'nombre_comercial' => 'Café Luna',
        'razon_social' => 'Café Luna SA de CV',
        'slug' => $slug,
        'telefono' => '4611002000',
    ]);

    tenancy()->initialize($tenant);
    ConfiguracionTenant::establecer(ConfiguracionTenant::APP_CLIENTES, $appPrendida ? 'Si' : 'No');
    tenancy()->end();

    return $tenant;
}

/** El último código que salió por correo. */
function pideUltimoCodigo(): string
{
    $correo = Mail::sent(CodigoAccesoMail::class)->last();
    expect($correo)->not->toBeNull();

    return $correo->codigo;
}

/** Registro completo; devuelve la respuesta de `verificar`. */
function pideRegistrar($test, string $email = 'ana@ejemplo.com', string $telefono = '461 777 8899', string $slug = 'cafe-luna')
{
    $test->postJson("/api/v1/t/{$slug}/cliente/codigo", ['email' => $email, 'modo' => 'registro'])->assertOk();

    return $test->postJson("/api/v1/t/{$slug}/cliente/verificar", [
        'email' => $email,
        'codigo' => pideUltimoCodigo(),
        'nombre' => 'Ana Torres',
        'telefono' => $telefono,
    ]);
}

it('does not send codes or let anyone in while the app is off (RN-01)', function () {
    pideTenant(appPrendida: false);

    $this->getJson('/api/v1/t/cafe-luna/cliente/marca')
        ->assertOk()
        ->assertJsonPath('disponible', false)
        ->assertJsonPath('nombre', 'Café Luna');

    $this->postJson('/api/v1/t/cafe-luna/cliente/codigo', ['email' => 'ana@ejemplo.com', 'modo' => 'registro'])
        ->assertForbidden()
        ->assertJsonPath('motivo', 'NO_DISPONIBLE');

    Mail::assertNothingSent();
});

it('registers a new client with the emailed code (RN-15)', function () {
    $tenant = pideTenant();

    $this->postJson('/api/v1/t/cafe-luna/cliente/codigo', ['email' => '  Ana@Ejemplo.com ', 'modo' => 'registro'])
        ->assertOk();

    Mail::assertSent(CodigoAccesoMail::class, function (CodigoAccesoMail $mail) {
        return $mail->hasTo('ana@ejemplo.com')
            && $mail->nombreFlotilla === 'Café Luna'
            && ! str_contains($mail->envelope()->subject, $mail->codigo);
    });

    $this->postJson('/api/v1/t/cafe-luna/cliente/verificar', [
        'email' => 'ana@ejemplo.com',
        'codigo' => pideUltimoCodigo(),
        'nombre' => 'Ana Torres',
        'telefono' => '+52 1 461 777 8899',
    ])
        ->assertOk()
        ->assertJsonPath('nuevo', true)
        ->assertJsonPath('cliente.email', 'ana@ejemplo.com')
        ->assertJsonPath('cliente.telefono', '4617778899')
        ->assertJsonStructure(['token']);

    tenancy()->initialize($tenant);
    $cliente = Cliente::where('email_acceso', 'ana@ejemplo.com')->sole();
    expect($cliente->origen)->toBe(Cliente::ORIGEN_APP)
        ->and($cliente->telefono_e164)->toBe('+524617778899')
        ->and($cliente->email_verificado_en)->not->toBeNull()
        ->and($cliente->estado)->toBe('Activo');
});

it('logs an existing client back in without creating another one', function () {
    $tenant = pideTenant();
    pideRegistrar($this)->assertOk();

    $this->travel(2)->minutes();
    $this->postJson('/api/v1/t/cafe-luna/cliente/codigo', ['email' => 'ana@ejemplo.com', 'modo' => 'entrar'])
        ->assertOk();

    $this->postJson('/api/v1/t/cafe-luna/cliente/verificar', ['email' => 'ana@ejemplo.com', 'codigo' => pideUltimoCodigo()])
        ->assertOk()
        ->assertJsonPath('nuevo', false)
        ->assertJsonPath('cliente.nombre', 'Ana Torres');

    tenancy()->initialize($tenant);
    expect(Cliente::count())->toBe(1);
});

it('does not send a code to log in with an unknown email (RN-15)', function () {
    pideTenant();

    $this->postJson('/api/v1/t/cafe-luna/cliente/codigo', ['email' => 'nadie@ejemplo.com', 'modo' => 'entrar'])
        ->assertNotFound()
        ->assertJsonPath('motivo', 'NO_ENCONTRADO');

    Mail::assertNothingSent();
});

it('rejects a wrong code and invalidates it after five failures (RN-11, RN-12)', function () {
    pideTenant();
    $this->postJson('/api/v1/t/cafe-luna/cliente/codigo', ['email' => 'ana@ejemplo.com', 'modo' => 'registro']);
    $correcto = pideUltimoCodigo();
    $incorrecto = $correcto === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $intento) {
        $this->postJson('/api/v1/t/cafe-luna/cliente/verificar', ['email' => 'ana@ejemplo.com', 'codigo' => $incorrecto])
            ->assertUnprocessable()
            ->assertJsonPath('motivo', 'CODIGO_INCORRECTO');
    }

    $this->postJson('/api/v1/t/cafe-luna/cliente/verificar', [
        'email' => 'ana@ejemplo.com',
        'codigo' => $correcto,
        'nombre' => 'Ana Torres',
        'telefono' => '4617778899',
    ])->assertUnprocessable()->assertJsonPath('motivo', 'CODIGO_VENCIDO');
});

it('expires the code after ten minutes and uses it only once (RN-11)', function () {
    pideTenant();
    $this->postJson('/api/v1/t/cafe-luna/cliente/codigo', ['email' => 'ana@ejemplo.com', 'modo' => 'registro']);
    $codigo = pideUltimoCodigo();
    $datos = ['email' => 'ana@ejemplo.com', 'codigo' => $codigo, 'nombre' => 'Ana Torres', 'telefono' => '4617778899'];

    $this->travel(11)->minutes();
    $this->postJson('/api/v1/t/cafe-luna/cliente/verificar', $datos)
        ->assertUnprocessable()
        ->assertJsonPath('motivo', 'CODIGO_VENCIDO');

    $this->postJson('/api/v1/t/cafe-luna/cliente/codigo', ['email' => 'ana@ejemplo.com', 'modo' => 'registro']);
    $datos['codigo'] = pideUltimoCodigo();

    $this->postJson('/api/v1/t/cafe-luna/cliente/verificar', $datos)->assertOk();
    $this->postJson('/api/v1/t/cafe-luna/cliente/verificar', $datos)
        ->assertUnprocessable()
        ->assertJsonPath('motivo', 'CODIGO_INCORRECTO');
});

it('invalidates the previous code when a new one is requested (RN-11)', function () {
    pideTenant();
    $this->postJson('/api/v1/t/cafe-luna/cliente/codigo', ['email' => 'ana@ejemplo.com', 'modo' => 'registro']);
    $primero = pideUltimoCodigo();

    $this->travel(61)->seconds();
    $this->postJson('/api/v1/t/cafe-luna/cliente/codigo', ['email' => 'ana@ejemplo.com', 'modo' => 'registro']);
    $segundo = pideUltimoCodigo();

    if ($primero !== $segundo) {
        $this->postJson('/api/v1/t/cafe-luna/cliente/verificar', [
            'email' => 'ana@ejemplo.com',
            'codigo' => $primero,
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/t/cafe-luna/cliente/verificar', [
        'email' => 'ana@ejemplo.com',
        'codigo' => $segundo,
        'nombre' => 'Ana Torres',
        'telefono' => '4617778899',
    ])->assertOk();
});

it('limits codes per email per minute and per hour (RN-12)', function () {
    pideTenant();
    $pedir = fn () => $this->postJson('/api/v1/t/cafe-luna/cliente/codigo', ['email' => 'ana@ejemplo.com', 'modo' => 'registro']);

    $pedir()->assertOk();
    $pedir()->assertTooManyRequests()->assertJsonPath('motivo', 'LIMITE');

    foreach (range(2, 5) as $vez) {
        $this->travel(61)->seconds();
        $pedir()->assertOk();
    }

    $this->travel(61)->seconds();
    $pedir()->assertTooManyRequests();

    Mail::assertSent(CodigoAccesoMail::class, 5);
});

it('stops at the global daily cap shared by every fleet (RN-12)', function () {
    config(['pide.codigo.limites.por_dia_global' => 2]);
    pideTenant('cafe-luna');
    pideTenant('pan-sol');

    $this->postJson('/api/v1/t/cafe-luna/cliente/codigo', ['email' => 'a@ejemplo.com', 'modo' => 'registro'])->assertOk();
    $this->postJson('/api/v1/t/pan-sol/cliente/codigo', ['email' => 'b@ejemplo.com', 'modo' => 'registro'])->assertOk();
    $this->postJson('/api/v1/t/pan-sol/cliente/codigo', ['email' => 'c@ejemplo.com', 'modo' => 'registro'])
        ->assertTooManyRequests();
});

it('never writes the code to the log (RN-13)', function () {
    pideTenant();
    Log::spy();

    pideRegistrar($this)->assertOk();
    $codigo = Mail::sent(CodigoAccesoMail::class)->last()->codigo;

    Log::shouldNotHaveReceived('info', fn (string $mensaje) => str_contains($mensaje, $codigo));
    Log::shouldNotHaveReceived('error', fn (string $mensaje) => str_contains($mensaje, $codigo));
});

it('creates a separate account when the phone belongs to another client and sends it to review (RN-15, D23)', function () {
    $tenant = pideTenant();

    tenancy()->initialize($tenant);
    $deWhatsapp = Cliente::create(['nombre' => 'Ana (WhatsApp)', 'telefono' => '4617778899', 'estado' => 'Activo']);
    $deWhatsapp->forceFill(['telefono_e164' => '+524617778899'])->save();
    tenancy()->end();

    $respuesta = pideRegistrar($this, telefono: '4617778899')->assertOk()->assertJsonPath('nuevo', true);

    tenancy()->initialize($tenant);
    $nuevo = Cliente::where('email_acceso', 'ana@ejemplo.com')->sole();
    expect($nuevo->id_cliente)->not->toBe($deWhatsapp->id_cliente)
        ->and($respuesta->json('cliente.id_cliente'))->toBe($nuevo->id_cliente)
        ->and($nuevo->telefono_e164)->toBeNull()
        ->and($deWhatsapp->fresh()->telefono_e164)->toBeNull();

    $revision = TelefonoEnRevision::find('+524617778899');
    expect($revision)->not->toBeNull()
        ->and($revision->clientes)->toEqualCanonicalizing([$deWhatsapp->id_cliente, $nuevo->id_cliente]);
});

it('refuses an inactive client (RN-04)', function () {
    $tenant = pideTenant();
    pideRegistrar($this)->assertOk();

    tenancy()->initialize($tenant);
    Cliente::where('email_acceso', 'ana@ejemplo.com')->update(['estado' => 'Inactivo']);
    tenancy()->end();

    $this->travel(2)->minutes();
    $this->postJson('/api/v1/t/cafe-luna/cliente/codigo', ['email' => 'ana@ejemplo.com', 'modo' => 'entrar'])->assertOk();
    $this->postJson('/api/v1/t/cafe-luna/cliente/verificar', ['email' => 'ana@ejemplo.com', 'codigo' => pideUltimoCodigo()])
        ->assertForbidden()
        ->assertJsonPath('motivo', 'CLIENTE_INACTIVO');
});

it('lets the client read and edit name and phone, and log out (RN-03, RN-57)', function () {
    pideTenant();
    $token = pideRegistrar($this)->json('token');
    $conToken = fn () => $this->withToken($token);

    $conToken()->getJson('/api/v1/t/cafe-luna/cliente/perfil')
        ->assertOk()
        ->assertJsonPath('email', 'ana@ejemplo.com');

    $conToken()->putJson('/api/v1/t/cafe-luna/cliente/perfil', ['nombre' => 'Ana T.', 'telefono' => '461 555 1212'])
        ->assertOk()
        ->assertJsonPath('telefono', '4615551212')
        ->assertJsonPath('email', 'ana@ejemplo.com');

    $conToken()->postJson('/api/v1/t/cafe-luna/cliente/salir')->assertNoContent();

    app('auth')->forgetGuards();
    $conToken()->getJson('/api/v1/t/cafe-luna/cliente/perfil')->assertUnauthorized();
});
