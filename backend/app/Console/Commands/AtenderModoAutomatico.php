<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant as TenantCentral;
use App\Services\ModoAutomaticoService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reofrece o cancela los pedidos sin conductor y anota los atorados de cada flotilla con el modo
 * automático activo (spec tenant/034, RN-07 y RN-15). Corre cada minuto, igual que
 * `pedidos:publicar-agendados`.
 */
#[Signature('automatico:atender-sin-conductor')]
#[Description('Atiende los pedidos sin conductor y los atorados de las flotillas en modo automático')]
class AtenderModoAutomatico extends Command
{
    public function handle(): int
    {
        TenantCentral::where('estado', 'Activo')->get()->each(function (TenantCentral $tenant) {
            tenancy()->initialize($tenant);

            try {
                app(ModoAutomaticoService::class)->atender();
            } catch (\Throwable $e) {
                Log::warning('El modo automático falló en una flotilla.', [
                    'tenant' => $tenant->slug,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                tenancy()->end();
            }
        });

        return self::SUCCESS;
    }
}
