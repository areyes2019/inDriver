<?php

use App\Jobs\EnviarLatido;
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

// spec tenant/031: WhatsApp tiene su propio worker, para que una respuesta lenta de Meta o de la IA
// no retrase `ExpirarOfertaPedido`.
// Desde la etapa 5 un mensaje puede esperar a la IA, a dos geocodificaciones y a la ruta: 90 s de
// techo, y el candado dura más que eso para que no arranquen dos workers a la vez.
Schedule::command('queue:work --queue=whatsapp --max-time=55 --sleep=1 --tries=3 --timeout=90')
    ->everyMinute()
    ->withoutOverlapping(3)
    ->runInBackground();

// spec tenant/031, RN-30.
Schedule::command('whatsapp:purgar-mensajes')->daily();

// spec tenant/034, RN-07: reoferta, cancelación sin conductor y pedidos atorados del modo automático.
Schedule::command('automatico:atender-sin-conductor')->everyMinute()->withoutOverlapping(2);

// spec tenant/034, RN-27.
Schedule::command('automatico:purgar-bitacora')->daily();

// spec tenant/035, sección 7: códigos de acceso (24 h) y llaves de pedido (48 h) de la app de clientes.
Schedule::command('clientes:purgar-codigos')->daily();

// spec tenant/034, RN-28: sin `LATIDO_URL` el job no hace nada.
Schedule::job(new EnviarLatido)->everyMinute();
