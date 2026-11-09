<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant as TenantCentral;
use App\Models\WhatsappNumero;
use App\Services\Whatsapp\PlantillasWhatsapp;
use App\Services\Whatsapp\WhatsappClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;

/**
 * Da de alta en Meta las plantillas de los avisos y muestra si ya están aprobadas (spec tenant/031,
 * RN-53 y D20). Se puede correr las veces que haga falta: una plantilla que ya existe no se vuelve
 * a crear, solo se informa su estado.
 */
#[Signature('whatsapp:crear-plantillas {slug : Slug de la flotilla} {--waba= : Identificador de la cuenta de WhatsApp Business; se guarda para las siguientes veces}')]
#[Description('Crea en Meta las plantillas de avisos de pedidos y muestra su estado')]
class CrearPlantillasWhatsapp extends Command
{
    public function handle(WhatsappClient $cliente): int
    {
        $tenant = TenantCentral::where('slug', $this->argument('slug'))->first();
        $numero = $tenant !== null
            ? WhatsappNumero::where('id_tenant', $tenant->getTenantKey())->where('estado', 'Activo')->oldest('id_numero')->first()
            : null;

        if ($numero === null) {
            $this->error('Esa flotilla no tiene un número de WhatsApp conectado.');

            return self::FAILURE;
        }

        if ($this->option('waba') !== null) {
            $numero->update(['waba_id' => (string) $this->option('waba')]);
        }

        if ($numero->waba_id === null) {
            $this->error('Falta el identificador de la cuenta de WhatsApp Business. Pásalo con --waba=');

            return self::FAILURE;
        }

        try {
            $existentes = $cliente->estadoPlantillas($numero);

            foreach (PlantillasWhatsapp::PLANTILLAS as $nombre => $plantilla) {
                if (! isset($existentes[$nombre])) {
                    $cliente->crearPlantilla($numero, $nombre, $plantilla['texto'], $plantilla['ejemplo']);
                    $this->info("Plantilla {$nombre} enviada a revisión.");
                }
            }

            $estados = $cliente->estadoPlantillas($numero);
        } catch (RequestException $e) {
            $this->error('Meta rechazó la petición: '.($e->response->json('error.message') ?? $e->getMessage()));

            return self::FAILURE;
        }

        $this->table(['Plantilla', 'Estado en Meta'], collect(PlantillasWhatsapp::PLANTILLAS)
            ->keys()
            ->map(fn (string $nombre) => [$nombre, $estados[$nombre] ?? 'NO ENCONTRADA'])
            ->all());

        $this->line('Los avisos con plantilla solo salen cuando el estado es APPROVED. La revisión tarda hasta 24 horas.');

        return self::SUCCESS;
    }
}
