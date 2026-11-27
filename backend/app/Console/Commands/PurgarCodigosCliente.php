<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant as TenantCentral;
use App\Models\Tenant\ClienteCodigo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Borra los códigos de acceso de la app de clientes con más de 24 horas (spec tenant/035, sección 7). */
#[Signature('clientes:purgar-codigos')]
#[Description('Borra los códigos de acceso de la app de clientes con más de 24 horas')]
class PurgarCodigosCliente extends Command
{
    public function handle(): int
    {
        $limite = now()->subDay();

        TenantCentral::all()->each(function (TenantCentral $tenant) use ($limite) {
            tenancy()->initialize($tenant);

            ClienteCodigo::where('created_at', '<', $limite)->delete();

            tenancy()->end();
        });

        return self::SUCCESS;
    }
}
