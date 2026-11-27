<?php

declare(strict_types=1);

namespace App\Services\Cliente;

use Illuminate\Support\Facades\Log;

/**
 * Solo desarrollo local (spec tenant/035, RN-13): escribe el código en el log en lugar de mandarlo.
 * `AppServiceProvider` impide usarlo en cualquier ambiente que no sea `local` o `testing`.
 */
class CanalCodigoLog implements CanalCodigo
{
    public function enviar(string $email, string $codigo, string $nombreFlotilla): void
    {
        Log::info("[pide] Código de acceso a {$nombreFlotilla} para {$email}: {$codigo}");
    }
}
