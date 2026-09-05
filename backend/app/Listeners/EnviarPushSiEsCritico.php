<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Tenant\PedidoCanceladoParaConductor;
use App\Events\Tenant\PedidoDisponible;
use App\Events\Tenant\PedidoReprogramado;
use App\Events\Tenant\SaldoAcreditado;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorDispositivo;
use App\Services\FcmSender;

/**
 * Respaldo de push nativo para los eventos que un conductor no puede perderse (spec tenant/018,
 * RN-04): se dispara junto con el broadcast por socket, nunca en su lugar. Centraliza aquí quién
 * debe recibir cada aviso, para que ningún controlador necesite conocer la existencia de FCM.
 * Corre de forma síncrona, en la misma petición, para no perder el contexto de tenencia.
 */
class EnviarPushSiEsCritico
{
    public function __construct(private readonly FcmSender $fcm) {}

    public function handle(PedidoDisponible|PedidoCanceladoParaConductor|PedidoReprogramado|SaldoAcreditado $event): void
    {
        [$idsConductor, $titulo, $cuerpo, $datos] = match (true) {
            $event instanceof PedidoDisponible => [
                Conductor::where('disponibilidad', 'DISPONIBLE')->pluck('id_conductor')->all(),
                'Nuevo pedido disponible',
                "Pedido {$event->pedido->numero_pedido} listo para tomar.",
                ['event_id' => $event->eventId, 'tipo' => 'pedido.disponible'],
            ],
            $event instanceof PedidoCanceladoParaConductor => [
                [$event->idConductor],
                'Pedido cancelado',
                'Uno de tus pedidos fue cancelado.',
                ['event_id' => $event->eventId, 'tipo' => 'pedido.cancelado'],
            ],
            $event instanceof PedidoReprogramado => [
                [$event->idConductor],
                'Pedido reprogramado',
                'La fecha de uno de tus pedidos cambió.',
                ['event_id' => $event->eventId, 'tipo' => 'pedido.reprogramado'],
            ],
            $event instanceof SaldoAcreditado => [
                [$event->idConductor],
                'Saldo acreditado',
                "Se acreditaron {$event->viajesAcreditados} viaje(s) a tu saldo.",
                ['event_id' => $event->eventId, 'tipo' => 'saldo.acreditado'],
            ],
        };

        $tokens = ConductorDispositivo::whereIn('id_conductor', $idsConductor)->pluck('fcm_token');

        foreach ($tokens as $token) {
            $this->fcm->enviar($token, $titulo, $cuerpo, $datos);
        }
    }
}
