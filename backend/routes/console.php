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
