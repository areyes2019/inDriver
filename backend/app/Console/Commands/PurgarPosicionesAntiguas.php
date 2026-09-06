<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant as TenantCentral;
use App\Models\Tenant\ConductorPosicion;
use App\Services\TrackingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Borra el detalle de `conductor_posiciones` de envíos concluidos hace más de 7 días (spec
 * tenant/021, RN-08): lo que sobrevive es `pedidos.resumen_ruta`, ya calculado al entregar. Corre
 * una vez al día (ver `routes/console.php`) sobre todos los tenants activos.
 */
#[Signature('conductor:purgar-posiciones-antiguas')]
#[Description('Borra las posiciones de conductores de envíos concluidos hace más de 7 días')]
class PurgarPosicionesAntiguas extends Command
{
    public function handle(): int
    {
        $limite = now()->subDays(TrackingService::RETENCION_DIAS);

        TenantCentral::where('estado', 'Activo')->get()->each(function (TenantCentral $tenant) use ($limite) {
            tenancy()->initialize($tenant);

            ConductorPosicion::whereIn('id_pedido', function ($query) use ($limite) {
                $query->select('id_pedido')
                    ->from('pedidos')
                    ->where(function ($query) use ($limite) {
                        $query->where('estado', 'ENTREGADO')->where('fecha_entrega', '<', $limite);
                    })
                    ->orWhere(function ($query) use ($limite) {
                        $query->where('estado', 'CANCELADO')->where('fecha_cancelacion', '<', $limite);
                    });
            })->delete();

            tenancy()->end();
        });

        return self::SUCCESS;
    }
}
