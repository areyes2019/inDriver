<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant as TenantCentral;
use App\Services\Cliente\ConciliacionClientesService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * spec tenant/035, RN-16 y RN-17. Por defecto solo informa; con `--aplicar` llena `telefono_e164` en
 * los teléfonos únicos y manda los repetidos a revisión. Se corre antes de prender la app en una
 * flotilla.
 */
#[Signature('clientes:normalizar-telefonos {--tenant=* : Slug de la flotilla; sin esto, todas} {--aplicar : Guarda los cambios}')]
#[Description('Normaliza los teléfonos de los clientes y detecta los repetidos (spec tenant/035)')]
class NormalizarTelefonosClientes extends Command
{
    public function handle(ConciliacionClientesService $conciliacion): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $slugs = (array) $this->option('tenant');

        $tenants = TenantCentral::query()
            ->when($slugs !== [], fn ($q) => $q->whereIn('slug', $slugs))
            ->get();

        if ($tenants->isEmpty()) {
            $this->error('No hay flotillas con ese slug.');

            return self::FAILURE;
        }

        $this->line($aplicar ? 'Aplicando cambios.' : 'Solo reporte: no se cambia nada. Usa --aplicar para guardar.');

        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);

            $reporte = $conciliacion->normalizarTodos($aplicar);

            $this->newLine();
            $this->info("{$tenant->nombre_comercial} ({$tenant->slug})");
            $this->line("  Teléfonos únicos: {$reporte['unicos']}");
            $this->line('  Teléfonos repetidos: '.count($reporte['repetidos']));
            $this->line("  Teléfonos inválidos: {$reporte['invalidos']}");

            foreach ($reporte['repetidos'] as $e164 => $ids) {
                $this->line("    {$e164}: clientes ".implode(', ', $ids));
            }

            tenancy()->end();
        }

        return self::SUCCESS;
    }
}
