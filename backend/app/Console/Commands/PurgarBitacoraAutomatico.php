<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant as TenantCentral;
use App\Models\Tenant\AutomaticoBitacora;
use App\Services\ModoAutomaticoService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Borra las entradas de la bitácora del modo automático con más de 90 días (spec tenant/034, RN-27).
 */
#[Signature('automatico:purgar-bitacora')]
#[Description('Borra las entradas de la bitácora del modo automático con más de 90 días')]
class PurgarBitacoraAutomatico extends Command
{
    public function handle(): int
    {
        $limite = now()->subDays(ModoAutomaticoService::DIAS_BITACORA);

        TenantCentral::all()->each(function (TenantCentral $tenant) use ($limite) {
            tenancy()->initialize($tenant);

            AutomaticoBitacora::where('created_at', '<', $limite)->delete();

            tenancy()->end();
        });

        return self::SUCCESS;
    }
}
