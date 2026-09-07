<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant as TenantModel;
use App\Models\Tenant\Pedido;
use App\Services\PedidoEstadoService;
use App\Services\TrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * El "conductor virtual" del modo TEST (spec "PWA agnóstica a LIVE/TEST", ampliada por spec
 * tenant/025): avanza un punto del tramo ya calculado por `SimuladorRutaService`, se reprograma a sí
 * mismo, y al escribir el último punto transiciona el pedido a `$estadoAlLlegar` — que es lo que
 * hace caer los hitos de llegada (`ARRIBADO`, `ARRIBADO_A_ENTREGA`).
 *
 * Se detiene solo en cuanto el pedido ya no está en `$estadoEsperado`. Eso cubre las tres formas de
 * quedarse sin trabajo: el conductor adelantó el hito con el botón, canceló, o el viaje terminó
 * antes. Es también lo que garantiza que nunca haya dos recorridos vivos sobre el mismo pedido
 * (RN-04): el botón mueve el estado, el recorrido viejo se apaga y la cadena sigue desde el nuevo.
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
        private readonly string $estadoEsperado,
        private readonly string $estadoAlLlegar,
    ) {}

    public function handle(TrackingService $tracking, PedidoEstadoService $estados): void
    {
        $yaInicializado = tenancy()->initialized && tenancy()->tenant?->getTenantKey() === $this->tenant->getTenantKey();

        if (! $yaInicializado) {
            tenancy()->initialize($this->tenant);
        }

        try {
            $pedido = Pedido::find($this->idPedido);

            if (! $pedido || ! $pedido->es_prueba || $pedido->estado !== $this->estadoEsperado) {
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
                self::dispatch(
                    $this->tenant,
                    $this->idPedido,
                    $this->puntos,
                    $siguiente,
                    $this->segundosEntrePuntos,
                    $this->estadoEsperado,
                    $this->estadoAlLlegar,
                )->delay(now()->addSeconds($this->segundosEntrePuntos));

                return;
            }

            // Último punto del tramo: el conductor virtual llegó. `transicionar()` no persiste, y de
            // paso encadena el siguiente paso simulado desde el estado nuevo.
            $estados->transicionar($pedido, $this->estadoAlLlegar);
            $pedido->save();
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
