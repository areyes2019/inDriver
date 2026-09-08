<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant as TenantCentral;
use App\Services\SimulacionEnvioService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Mueve los envíos TEST por su ruta (spec tenant/025).
 *
 * Corre cada minuto desde el `schedule` y vive ~55 segundos, escribiendo una tanda de puntos cada
 * 2 segundos para que el marcador se deslice en el mapa en vez de dar saltos. Es un comando de
 * `schedule:run` y no una cadena de jobs por la misma razón que `pedidos:publicar-agendados`
 * (spec tenant/024): no hay ningún `queue:work` supervisado, y un eslabón perdido dejaría el viaje
 * congelado para siempre.
 *
 * Nada de esto es crítico si falla: como el avance se calcula por reloj (RN-13), una corrida
 * perdida se recupera sola en la siguiente.
 */
#[Signature('simulacion:avanzar {--segundos=55 : Cuánto vive esta corrida}')]
#[Description('Avanza los envíos TEST por su ruta simulada a 60 km/h')]
class AvanzarSimulaciones extends Command
{
    public function handle(SimulacionEnvioService $simulaciones): int
    {
        $vive = max(0, (int) $this->option('segundos'));
        $hasta = microtime(true) + $vive;

        do {
            $this->avanzarTodosLosTenants($simulaciones);

            if (microtime(true) + SimulacionEnvioService::pasoSegundos() > $hasta) {
                break;
            }

            sleep(SimulacionEnvioService::pasoSegundos());
        } while (microtime(true) < $hasta);

        return self::SUCCESS;
    }

    /**
     * Un tenant con la ruta rota o la base caída no puede dejar sin avanzar a los demás de la misma
     * corrida — mismo criterio que `PublicarPedidosAgendados`.
     */
    private function avanzarTodosLosTenants(SimulacionEnvioService $simulaciones): void
    {
        TenantCentral::where('estado', 'Activo')->get()->each(function (TenantCentral $tenant) use ($simulaciones) {
            try {
                tenancy()->initialize($tenant);
                $simulaciones->avanzarPendientes();
            } catch (\Throwable $e) {
                Log::warning('Falló el avance de simulaciones del tenant', [
                    'tenant' => $tenant->slug,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                tenancy()->end();
            }
        });
    }
}
