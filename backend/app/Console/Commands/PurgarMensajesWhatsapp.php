<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant as TenantCentral;
use App\Models\Tenant\WhatsappMensaje;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Borra el texto de los mensajes con más de 90 días (spec tenant/031, RN-30). La fila se conserva:
 * el id de Meta sigue evitando duplicados.
 */
#[Signature('whatsapp:purgar-mensajes')]
#[Description('Borra el texto de los mensajes de WhatsApp con más de 90 días')]
class PurgarMensajesWhatsapp extends Command
{
    public const RETENCION_DIAS = 90;

    public function handle(): int
    {
        $limite = now()->subDays(self::RETENCION_DIAS);

        TenantCentral::all()->each(function (TenantCentral $tenant) use ($limite) {
            tenancy()->initialize($tenant);

            WhatsappMensaje::where('created_at', '<', $limite)
                ->whereNotNull('texto')
                ->update(['texto' => null]);

            tenancy()->end();
        });

        return self::SUCCESS;
    }
}
