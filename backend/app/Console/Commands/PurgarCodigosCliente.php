<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant as TenantCentral;
use App\Models\Tenant\ClienteCodigo;
use App\Models\Tenant\ClienteSolicitudPedido;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Purga diaria de la app de clientes (spec tenant/035, sección 7): los códigos de acceso con más de
 * 24 horas y las llaves anti-duplicados con más de 48. Borrar una llave no toca su pedido.
 */
#[Signature('clientes:purgar-codigos')]
#[Description('Borra los códigos de acceso (24 h) y las llaves de pedido (48 h) de la app de clientes')]
class PurgarCodigosCliente extends Command
{
    public function handle(): int
    {
        $codigos = now()->subDay();
        $solicitudes = now()->subHours(48);

        TenantCentral::all()->each(function (TenantCentral $tenant) use ($codigos, $solicitudes) {
            tenancy()->initialize($tenant);

            ClienteCodigo::where('created_at', '<', $codigos)->delete();
            ClienteSolicitudPedido::where('created_at', '<', $solicitudes)->delete();

            tenancy()->end();
        });

        return self::SUCCESS;
    }
}
