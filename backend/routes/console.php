<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// spec tenant/019, RN-04.
Schedule::command('conductor:apagar-inactivos')->everyMinute();

// spec tenant/021, RN-08.
Schedule::command('conductor:purgar-posiciones-antiguas')->daily();

// spec tenant/024: los pedidos agendados se publican 15 minutos antes de su horario.
Schedule::command('pedidos:publicar-agendados')->everyMinute();

// spec tenant/024: el worker de colas, sin supervisor. Los jobs con `delay()` del sistema
// (ExpirarOfertaPedido, AvisarSinConfirmar) no tienen otra forma de correr en producción, donde no
// hay ningún proceso permanente vigilado — solo este `schedule:run`.
//
// Sin `--stop-when-empty` a propósito: ambos son jobs diferidos, y un worker que se apaga en
// cuanto la cola está vacía se iría justo antes de que el job venza. Se queda los 55s sondeando.
// `runInBackground` es obligatorio: en primer plano bloquearía al resto de tareas del minuto.
Schedule::command('queue:work --max-time=55 --sleep=1 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();
