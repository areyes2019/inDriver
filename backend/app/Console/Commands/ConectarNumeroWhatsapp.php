<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant as TenantCentral;
use App\Models\WhatsappNumero;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Conecta a mano un número de WhatsApp con una flotilla (spec tenant/031, D4). El token se pregunta
 * en lugar de recibirse como argumento para que no quede en el historial de la terminal.
 */
#[Signature('whatsapp:conectar-numero {slug : Slug de la flotilla} {phone_number_id : Identificador del número en Meta} {--telefono= : Número visible, solo como referencia}')]
#[Description('Conecta un número de WhatsApp con una flotilla')]
class ConectarNumeroWhatsapp extends Command
{
    public function handle(): int
    {
        $tenant = TenantCentral::where('slug', $this->argument('slug'))->first();

        if ($tenant === null) {
            $this->error('No existe una flotilla con ese slug.');

            return self::FAILURE;
        }

        $idTelefono = (string) $this->argument('phone_number_id');
        $existente = WhatsappNumero::where('phone_number_id', $idTelefono)->first();

        // Reasignarlo en silencio mandaría los mensajes de una flotilla a la base de otra.
        if ($existente !== null && $existente->id_tenant !== $tenant->getTenantKey()) {
            $this->error('Ese número ya está conectado a otra flotilla.');

            return self::FAILURE;
        }

        $token = (string) $this->secret('Token de acceso de Meta');

        if ($token === '') {
            $this->error('El token no puede ir vacío.');

            return self::FAILURE;
        }

        WhatsappNumero::updateOrCreate(['phone_number_id' => $idTelefono], [
            'id_tenant' => $tenant->getTenantKey(),
            'telefono' => $this->option('telefono') ?? $existente?->telefono,
            'access_token' => $token,
            'estado' => 'Activo',
        ]);

        $this->info("Número conectado a {$tenant->slug}.");

        return self::SUCCESS;
    }
}
