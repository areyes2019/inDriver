<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Tenant\Conductor;
use App\Models\Tenant\Pedido;
use App\Services\OfertaPedidoService;
use App\Services\PedidoEstadoService;
use Illuminate\Support\Facades\Log;

/**
 * Lo que tiene que pasar cuando un pedido ya quedó escrito: soltar los avisos de tiempo real que
 * `PedidoEstadoService` dejó en cola (spec tenant/027) y reactivar la cola del conductor en cuanto
 * su envío se cierra (spec tenant/026, RN-01).
 *
 * Vive en un observador y no dentro de `PedidoEstadoService::transicionar()` por una razón
 * concreta: `transicionar()` no guarda —deja que el llamador decida cuándo—, así que ahí el pedido
 * todavía figura como activo en la base y `tienePedidoActivo()` diría que el conductor sigue
 * ocupado. Aquí el estado final ya está escrito.
 */
class PedidoObserver
{
    /**
     * RN-02: la reoferta corre después de confirmar la transición, fuera de su transacción. Con
     * esto Laravel retiene el evento hasta el commit cuando el llamador abrió una (el `aceptar()`
     * de la app del conductor, por ejemplo) y lo dispara enseguida cuando no.
     */
    public bool $afterCommit = true;

    public function saved(Pedido $pedido): void
    {
        $this->soltarAvisosDiferidos($pedido);

        if (! $pedido->wasChanged('estado') || $pedido->id_conductor === null) {
            return;
        }

        if (! in_array($pedido->estado, PedidoEstadoService::ESTADOS_FINALES, true)) {
            return;
        }

        $conductor = Conductor::find($pedido->id_conductor);

        if (! $conductor) {
            return;
        }

        try {
            app(OfertaPedidoService::class)->reactivarColaPara($conductor);
        } catch (\Throwable $e) {
            // RN-02: que la reoferta falle no puede revertir una entrega ya cerrada. El sondeo de
            // 10s de panda_express sigue siendo la red de seguridad.
            Log::warning('No se pudo reactivar la cola tras cerrar el envío.', [
                'id_pedido' => $pedido->id_pedido,
                'id_conductor' => $pedido->id_conductor,
                'estado' => $pedido->estado,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Manda los avisos que `PedidoEstadoService::transicionar()` dejó en cola (spec tenant/027).
     *
     * Sus cargas se arman releyendo el pedido de la base, y `transicionar()` no guarda —deja que el
     * llamador decida cuándo—, así que salir desde ahí significaba mandar el estado anterior. Aquí
     * la fila ya está escrita y, por `$afterCommit`, también confirmada.
     *
     * La cola se vacía **antes** de invocarla: un aviso que falle no puede dejar los demás dentro
     * del modelo para que un `save()` posterior los mande por segunda vez. Que un evento no salga
     * no puede tumbar la transición que ya se guardó —mismo criterio que la reoferta de RN-02—;
     * el sondeo de la app y la reconciliación del Panel son la red de seguridad.
     */
    private function soltarAvisosDiferidos(Pedido $pedido): void
    {
        if ($pedido->avisosDiferidos === []) {
            return;
        }

        $avisos = $pedido->avisosDiferidos;
        $pedido->avisosDiferidos = [];

        foreach ($avisos as $aviso) {
            try {
                $aviso();
            } catch (\Throwable $e) {
                Log::warning('No se pudo emitir un aviso de tiempo real del pedido.', [
                    'id_pedido' => $pedido->id_pedido,
                    'estado' => $pedido->estado,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
