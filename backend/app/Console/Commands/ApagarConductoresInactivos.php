<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant as TenantCentral;
use App\Models\Tenant\ConductorEstado;
use App\Services\DisponibilidadService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Apaga el modo en línea de quien lleva más de 10 minutos sin dar señales de vida (spec
 * tenant/019, RN-04): ni un `LOCATION_UPDATE` ni una llamada a `/sync`. Corre cada minuto (ver
 * `routes/console.php`) sobre todos los tenants activos.
 */
#[Signature('conductor:apagar-inactivos')]
#[Description('Apaga el modo en línea de los conductores sin actividad en los últimos 10 minutos')]
class ApagarConductoresInactivos extends Command
{
    public function handle(DisponibilidadService $disponibilidad): int
    {
        $limite = now()->subMinutes(10);

        TenantCentral::where('estado', 'Activo')->get()->each(function (TenantCentral $tenant) use ($disponibilidad, $limite) {
            tenancy()->initialize($tenant);

            ConductorEstado::query()
                ->where('estado', 'ONLINE')
                ->where(function ($query) use ($limite) {
                    $query->where('ultima_actualizacion', '<', $limite)
                        ->orWhere(function ($query) use ($limite) {
                            $query->whereNull('ultima_actualizacion')->where('ultima_conexion', '<', $limite);
                        });
                })
                ->with('conductor')
                ->get()
                ->each(function (ConductorEstado $estado) use ($disponibilidad) {
                    if ($estado->conductor) {
                        $disponibilidad->desconectar($estado->conductor, forzar: true);
                    }
                });

            tenancy()->end();
        });

        return self::SUCCESS;
    }
}
