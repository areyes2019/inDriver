<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant as TenantModel;
use App\Models\Tenant\Pedido;
use App\Services\TrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * El "conductor virtual" del modo TEST (spec "PWA agnóstica a LIVE/TEST"): avanza un punto de la
 * ruta ya calculada por `SimuladorRutaService` y se reprograma a sí mismo, mismo patrón que
 * `ExpirarOfertaPedido`. Se detiene solo si el pedido ya no está `EN_CAMINO` — llegó a
 * `ARRIBADO_A_ENTREGA` (el conductor ya tocó "Llegué"), se canceló, o se entregó antes de terminar
 * el recorrido — en cualquier caso, seguir moviendo el punto ya no tiene sentido.
 *
 * @param  array<int, array{lat: float, lng: float}>  $puntos
 */
class SimularSiguientePunto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly TenantModel $tenant,
        private readonly int $idPedido,
        private readonly array $puntos,
        private readonly int $indice,
        private readonly int $segundosEntrePuntos,
    ) {}

    public function handle(TrackingService $tracking): void
    {
        $yaInicializado = tenancy()->initialized && tenancy()->tenant?->getTenantKey() === $this->tenant->getTenantKey();

        if (! $yaInicializado) {
            tenancy()->initialize($this->tenant);
        }

        try {
            $pedido = Pedido::find($this->idPedido);

            if (! $pedido || ! $pedido->es_prueba || $pedido->estado !== 'EN_CAMINO') {
                return;
            }

            $conductor = $pedido->conductor;

            if (! $conductor) {
                return;
            }

            $punto = $this->puntos[$this->indice];
            $tracking->registrarPosicion($conductor, ['latitud' => $punto['lat'], 'longitud' => $punto['lng']], desdeSimulador: true);

            $siguiente = $this->indice + 1;

            if ($siguiente < count($this->puntos)) {
                self::dispatch($this->tenant, $this->idPedido, $this->puntos, $siguiente, $this->segundosEntrePuntos)
                    ->delay(now()->addSeconds($this->segundosEntrePuntos));
            }
        } catch (\Throwable $e) {
            Log::error('Fallo el generador de ubicación simulada', [
                'id_pedido' => $this->idPedido,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if (! $yaInicializado) {
                tenancy()->end();
            }
        }
    }
}
