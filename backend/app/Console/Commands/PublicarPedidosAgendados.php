<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant as TenantCentral;
use App\Models\Tenant\Pedido;
use App\Services\PedidoEstadoService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Publica los pedidos agendados 15 minutos antes de su horario (spec tenant/024). Los "lo antes
 * posible" no pasan por aquí: esos los publica `Tenant\PedidoController@store` en el acto.
 *
 * Corre cada minuto (ver `routes/console.php`) sobre todos los tenants activos, igual que
 * `conductor:apagar-inactivos`. Es un comando de `schedule:run` y no un job con `delay()` a
 * propósito: no hay ningún `queue:work` supervisado en este sistema (spec tenant/018, RN-08), así
 * que un job programado se quedaría esperando para siempre en la tabla `jobs` del tenant.
 */
#[Signature('pedidos:publicar-agendados')]
#[Description('Publica los pedidos agendados 15 minutos antes de su horario de servicio')]
class PublicarPedidosAgendados extends Command
{
    /**
     * Cuánto antes del inicio de la ventana de servicio se le ofrece el pedido a los conductores.
     */
    public const ANTICIPACION_MINUTOS = 15;

    public function handle(PedidoEstadoService $estados): int
    {
        TenantCentral::where('estado', 'Activo')->get()->each(function (TenantCentral $tenant) use ($estados) {
            tenancy()->initialize($tenant);

            $ahora = now();
            $limite = $ahora->copy()->addMinutes(self::ANTICIPACION_MINUTOS);

            Pedido::query()
                ->where('estado', 'PENDIENTE')
                ->where('lo_antes_posible', false)
                ->whereNotNull('hora_desde')
                ->whereNotNull('hora_hasta')
                // Filtro grueso en la base (portable entre MySQL y el SQLite de los tests): la
                // comparación fina con la hora se hace abajo, en PHP, sobre un puñado de filas.
                ->whereDate('fecha_servicio', '<=', $limite->toDateString())
                ->get()
                ->each(function (Pedido $pedido) use ($estados, $ahora, $limite) {
                    if ($this->leTocaPublicarse($pedido, $ahora, $limite)) {
                        $this->publicar($estados, $pedido);
                    }
                });

            tenancy()->end();
        });

        return self::SUCCESS;
    }

    /**
     * Ya entró en la anticipación de 15 minutos y su ventana de servicio todavía no cierra.
     *
     * El tope por `hora_hasta` es lo que evita que este comando resucite pedidos viejos: uno de
     * hace tres días que nunca se publicó (porque el sistema no lo publicaba, que es justo el
     * problema que arregla la spec tenant/024) no tiene por qué aparecerle hoy a un conductor como
     * si fuera un viaje del momento. Se queda PENDIENTE, a la vista en el panel, para que alguien
     * decida qué hacer con él.
     */
    private function leTocaPublicarse(Pedido $pedido, Carbon $ahora, Carbon $limite): bool
    {
        $inicio = $this->momento($pedido, (string) $pedido->hora_desde);
        $fin = $this->momento($pedido, (string) $pedido->hora_hasta);

        if ($inicio === null || $fin === null) {
            return false;
        }

        return $inicio->lessThanOrEqualTo($limite) && $fin->greaterThanOrEqualTo($ahora);
    }

    /**
     * `fecha_servicio` es un `date` y `hora_desde`/`hora_hasta` son `time` ('HH:MM:SS'): el
     * instante real solo existe combinándolos.
     */
    private function momento(Pedido $pedido, string $hora): ?Carbon
    {
        if ($pedido->fecha_servicio === null || $hora === '') {
            return null;
        }

        try {
            return Carbon::parse($pedido->fecha_servicio->toDateString().' '.$hora);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Mismo trato que en `Tenant\PedidoController@publicar` (spec tenant/018, RN-08): el aviso no
     * puede tumbar la publicación. Aquí además un tenant con Reverb caído no puede dejar sin
     * publicar a los demás tenants de la misma corrida.
     */
    private function publicar(PedidoEstadoService $estados, Pedido $pedido): void
    {
        try {
            $estados->transicionar($pedido, 'PUBLICADO');
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar de la publicación del pedido agendado', [
                'id_pedido' => $pedido->id_pedido,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($pedido->estado === 'PUBLICADO') {
                $pedido->save();
            }
        }
    }
}
