<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Señal de vida hacia un vigilante externo (spec tenant/034, RN-28 a RN-30). Va por la cola para
 * que deje de llegar tanto si se detiene `schedule:run` como si se detiene el worker.
 */
class EnviarLatido implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        $url = config('services.latido.url');

        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            Http::timeout(10)->get($url);
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el latido al vigilante externo.', ['error' => $e->getMessage()]);
        }
    }
}
