<?php

use App\Services\Gps\BuzonGps;
use Illuminate\Support\Facades\Redis;

/**
 * spec tenant/028, §6.3 y §6.4 — el buzón contra un Redis de verdad.
 *
 * El resto de pruebas GPS sustituyen `BuzonGps` por un doble, así que nada comprobaba que los
 * comandos que manda existan y que la respuesta se sepa leer. Aquí sí se habla con Redis.
 *
 * Se salta sola si no hay Redis a mano: en producción y en el VPS lo hay, en una máquina de paso
 * puede que no, y el resto de la suite no debe depender de eso.
 */
beforeEach(function () {
    config([
        'gps.url' => 'https://delivery.prosello.com.mx',
        'gps.secreto' => 'secreto-de-pruebas',
        'gps.streams.hacia_servicio' => 'gps:prueba:hacia-servicio',
        'gps.streams.hacia_laravel' => 'gps:prueba:hacia-laravel',
        'gps.grupo' => 'prueba',
    ]);

    try {
        Redis::connection(config('gps.conexion'))->ping();
    } catch (Throwable $e) {
        test()->markTestSkipped('Sin Redis en '.config('database.redis.gps.host').': '.$e->getMessage());
    }

    Redis::connection(config('gps.conexion'))->del(
        'gps:prueba:hacia-servicio',
        'gps:prueba:hacia-laravel',
    );
});

afterEach(function () {
    try {
        Redis::connection(config('gps.conexion'))->del(
            'gps:prueba:hacia-servicio',
            'gps:prueba:hacia-laravel',
        );
    } catch (Throwable) {
        // Sin Redis no hay nada que limpiar.
    }
});

/**
 * Los dos streams son distintos en producción —uno por sentido—, así que para probar el ciclo
 * completo se apuntan los dos al mismo: se publica y se lee lo publicado.
 */
function buzonEnUnSoloStream(): BuzonGps
{
    config([
        'gps.streams.hacia_servicio' => 'gps:prueba:hacia-laravel',
        'gps.streams.hacia_laravel' => 'gps:prueba:hacia-laravel',
    ]);

    return app(BuzonGps::class);
}

it('publica un aviso y lo vuelve a leer con sus datos intactos', function () {
    $buzon = buzonEnUnSoloStream();
    $buzon->asegurarGrupo();

    $buzon->envioIniciado('cafe-luna', 41, 500);

    $avisos = $buzon->leer('consumidor-de-prueba', 10, 200);

    expect($avisos)->toHaveCount(1)
        ->and($avisos[0]['tipo'])->toBe(BuzonGps::ENVIO_INICIADO)
        ->and($avisos[0]['datos'])->toBe([
            'tenant' => 'cafe-luna',
            'id_conductor' => 41,
            'id_pedido' => 500,
        ]);
});

it('no vuelve a entregar un aviso ya confirmado', function () {
    $buzon = buzonEnUnSoloStream();
    $buzon->asegurarGrupo();

    $buzon->permisoCancelado('cafe-luna', 41, 1788862451);

    $avisos = $buzon->leer('consumidor-de-prueba', 10, 200);
    expect($avisos)->toHaveCount(1);

    $buzon->confirmar($avisos[0]['id']);

    expect($buzon->leer('consumidor-de-prueba', 10, 200))->toBeEmpty();
});

it('conserva el aviso sin confirmar para que otro consumidor lo recoja', function () {
    $buzon = buzonEnUnSoloStream();
    $buzon->asegurarGrupo();

    $buzon->envioTerminado('cafe-luna', 41, 500);

    // Se lee y no se confirma: es lo que pasa cuando el worker muere a media faena (RN-17).
    expect($buzon->leer('worker-que-se-muere', 10, 200))->toHaveCount(1);

    $pendientes = Redis::connection(config('gps.conexion'))
        ->client()
        ->executeRaw(['XPENDING', 'gps:prueba:hacia-laravel', 'prueba']);

    expect((int) $pendientes[0])->toBe(1);
});

it('devuelve vacío cuando no hay nada que leer', function () {
    $buzon = buzonEnUnSoloStream();
    $buzon->asegurarGrupo();

    // 50 ms de espera: lo justo para comprobar que un buzón vacío no es un error.
    expect($buzon->leer('consumidor-de-prueba', 10, 50))->toBeEmpty();
});

it('crea el grupo sin quejarse si ya existía', function () {
    $buzon = buzonEnUnSoloStream();

    $buzon->asegurarGrupo();
    $buzon->asegurarGrupo();

    $buzon->envioIniciado('cafe-luna', 41, 500);

    expect($buzon->leer('consumidor-de-prueba', 10, 200))->toHaveCount(1);
});

it('no escribe nada si la integración está apagada', function () {
    config(['gps.url' => '']);

    $buzon = buzonEnUnSoloStream();
    $buzon->envioIniciado('cafe-luna', 41, 500);

    expect(Redis::connection(config('gps.conexion'))->exists('gps:prueba:hacia-laravel'))->toBe(0);
});
