<?php

use App\Models\Tenant;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use App\Support\HoraOperacion;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:5173')
        ->withoutMiddleware(ValidateCsrfToken::class);

    gc_collect_cycles();
    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }

    config(['app.timezone_operacion' => 'America/Mexico_City']);

    $this->flotilla = Tenant::create([
        'nombre_comercial' => 'Moto Celaya',
        'razon_social' => 'Moto Celaya SA de CV',
        'slug' => 'moto-celaya',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    tenancy()->end();
    DB::purge('tenant');
    gc_collect_cycles();

    foreach (glob(database_path('delivery_tenant_*')) as $file) {
        File::delete($file);
    }
});

/** Un agendado del 5 de noviembre, de 17:00 a 18:00 hora de Celaya. */
function agendadoDeLaTarde(Tenant $flotilla): Pedido
{
    tenancy()->initialize($flotilla);

    $pedido = Pedido::create([
        'numero_pedido' => 'PED-000001',
        'nombre_solicitante' => 'Juan Pérez',
        'telefono_solicitante' => '4611234567',
        'direccion_recogida' => 'Av. Tecnológico 100',
        'direccion_entrega' => 'Centro',
        'fecha_servicio' => '2026-11-05',
        'lo_antes_posible' => false,
        'hora_desde' => '17:00:00',
        'hora_hasta' => '18:00:00',
        'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
        'importe_envio' => 50,
        'estado' => 'PENDIENTE',
    ]);

    tenancy()->end();

    return $pedido;
}

function administradorDeFlotilla(Tenant $flotilla): Usuario
{
    tenancy()->initialize($flotilla);

    $usuario = Usuario::create([
        'nombre' => 'Laura',
        'apellido_paterno' => 'Torres',
        'email' => 'laura@motocelaya.com',
        'password' => bcrypt('Password123!'),
        'rol' => 'AdminCliente',
        'estado' => 'Activo',
    ]);

    ConfiguracionTenant::establecer(ConfiguracionTenant::BANDERAZO, '40');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_INCLUIDOS, '3');
    ConfiguracionTenant::establecer(ConfiguracionTenant::KM_ADICIONAL, '7');

    tenancy()->end();

    return $usuario;
}

it('publica el agendado según la hora local', function (string $ahoraUtc, string $estadoEsperado) {
    $pedido = agendadoDeLaTarde($this->flotilla);
    Carbon::setTestNow($ahoraUtc);

    Artisan::call('pedidos:publicar-agendados');

    tenancy()->initialize($this->flotilla);
    expect($pedido->fresh()->estado)->toBe($estadoEsperado);
})->with([
    '10:45 en Celaya, faltan más de 6 horas' => ['2026-11-05 16:45:00', 'PENDIENTE'],
    '16:44 en Celaya, aún no entra en los 15 minutos' => ['2026-11-05 22:44:00', 'PENDIENTE'],
    '16:50 en Celaya, ya le toca' => ['2026-11-05 22:50:00', 'PUBLICADO'],
    '19:00 en Celaya, la ventana ya cerró' => ['2026-11-06 01:00:00', 'PENDIENTE'],
]);

it('sabe qué día es para la flotilla', function () {
    Carbon::setTestNow('2026-11-06 01:00:00');

    expect(now()->toDateString())->toBe('2026-11-06')
        ->and(HoraOperacion::hoy())->toBe('2026-11-05');
});

it('un pedido inmediato de la noche conserva la fecha local', function () {
    $usuario = administradorDeFlotilla($this->flotilla);
    Carbon::setTestNow('2026-11-06 01:00:00');

    $this->actingAs($usuario, 'usuario')
        ->postJson('/api/v1/t/moto-celaya/pedidos', [
            'nombre_solicitante' => 'Juan Pérez',
            'telefono_solicitante' => '4611234567',
            'direccion_recogida' => 'Av. Tecnológico 100',
            'direccion_entrega' => 'Centro',
            'lo_antes_posible' => true,
            'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
            'importe_envio' => 50,
        ])
        ->assertCreated()
        ->assertJsonPath('fecha_servicio', '2026-11-05');
});

it('reprogramar valida el horario en hora local', function (string $ahoraUtc, int $statusEsperado) {
    $usuario = administradorDeFlotilla($this->flotilla);
    $pedido = agendadoDeLaTarde($this->flotilla);
    Carbon::setTestNow($ahoraUtc);

    $this->actingAs($usuario, 'usuario')
        ->putJson("/api/v1/t/moto-celaya/pedidos/{$pedido->id_pedido}", [
            'nombre_solicitante' => 'Juan Pérez',
            'telefono_solicitante' => '4611234567',
            'direccion_recogida' => 'Av. Tecnológico 100',
            'direccion_entrega' => 'Centro',
            'fecha_servicio' => '2026-11-05',
            'lo_antes_posible' => false,
            'hora_desde' => '16:00',
            'hora_hasta' => '17:00',
            'modalidad_pago' => 'RECEPTOR_PAGA_ENVIO',
            'importe_envio' => 50,
        ])
        ->assertStatus($statusEsperado);
})->with([
    'a las 12:00 en Celaya, las 16:00 son futuro' => ['2026-11-05 18:00:00', 200],
    'a las 15:50 en Celaya, ya casi llegan' => ['2026-11-05 21:50:00', 422],
]);
