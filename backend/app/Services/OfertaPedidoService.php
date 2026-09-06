<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Tenant\PedidoDisponible;
use App\Events\Tenant\PedidoRequiereAsignacionManual;
use App\Jobs\ExpirarOfertaPedido;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\PedidoOferta;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Oferta de un pedido a los conductores elegibles y resolución de la carrera (spec tenant/020,
 * SPEC-020). Único lugar que crea y cierra filas de `pedido_ofertas`.
 */
class OfertaPedidoService
{
    public const VENTANA_SEGUNDOS = 45;

    public const MAX_INTENTOS = 3;

    /**
     * Crea una oferta por cada conductor elegible, con su propia ventana de 45s, y programa el
     * job que la cierra si nadie responde (RN-01, RN-03).
     */
    public function ofertar(Pedido $pedido): void
    {
        // Se cuenta el intento pase lo que pase, incluso sin nadie elegible ahora mismo: si no, un
        // tenant sin conductores en línea nunca llegaría a los 3 intentos de RN-04 y el pedido se
        // quedaría en PUBLICADO para siempre en vez de caer a asignación manual.
        $pedido->increment('veces_ofertado');

        $conductores = $this->conductoresElegibles($pedido);
        $ahora = now();
        $expiraEn = $ahora->copy()->addSeconds(self::VENTANA_SEGUNDOS);

        foreach ($conductores as $conductor) {
            // `updateOrCreate`, no `create`: por el único (id_pedido, id_conductor), reofertarle a
            // quien ya tuvo una fila en una ronda anterior (p. ej. EXPIRADA) reabre esa misma fila
            // en vez de chocar con la restricción única.
            PedidoOferta::updateOrCreate(
                ['id_pedido' => $pedido->id_pedido, 'id_conductor' => $conductor->id_conductor],
                ['estado' => 'PENDIENTE', 'ofrecida_en' => $ahora, 'expira_en' => $expiraEn, 'respondida_en' => null],
            );
        }

        if ($slug = tenant()?->slug) {
            if ($conductores->isNotEmpty()) {
                PedidoDisponible::dispatch($pedido, $slug);
            }

            ExpirarOfertaPedido::dispatch(tenant(), $pedido->id_pedido)->delay($expiraEn);
        }
    }

    /**
     * Cierra la ventana vencida (RN-03): lo que seguía PENDIENTE pasa a EXPIRADA. Si ya se aceptó o
     * canceló mientras tanto, no hace nada. Si no se agotaron los 3 intentos (RN-04), reoferta; si
     * sí, el pedido vuelve a PENDIENTE para asignación manual.
     */
    public function expirar(Pedido $pedido): void
    {
        PedidoOferta::where('id_pedido', $pedido->id_pedido)
            ->where('estado', 'PENDIENTE')
            ->update(['estado' => 'EXPIRADA', 'respondida_en' => now()]);

        $pedido->refresh();

        if ($pedido->estado !== 'PUBLICADO') {
            return;
        }

        if ($pedido->veces_ofertado >= self::MAX_INTENTOS) {
            app(PedidoEstadoService::class)->transicionar($pedido, 'PENDIENTE');
            $pedido->save();

            if ($slug = tenant()?->slug) {
                PedidoRequiereAsignacionManual::dispatch($pedido->id_pedido, $slug);
            }

            return;
        }

        $this->ofertar($pedido);
    }

    /**
     * @throws ValidationException con `OFFER_NOT_FOUND` si no tiene una oferta pendiente de ese
     *                             pedido (ya la resolvió, o nunca se la ofrecieron).
     */
    public function rechazar(Pedido $pedido, Conductor $conductor): void
    {
        $oferta = PedidoOferta::where('id_pedido', $pedido->id_pedido)
            ->where('id_conductor', $conductor->id_conductor)
            ->where('estado', 'PENDIENTE')
            ->first();

        if (! $oferta) {
            throw ValidationException::withMessages([
                'estado' => ['OFFER_NOT_FOUND'],
            ]);
        }

        $oferta->update(['estado' => 'RECHAZADA', 'respondida_en' => now()]);
    }

    /**
     * El conductor ganador se queda con el pedido: su oferta pasa a ACEPTADA y las demás
     * PENDIENTE de ese pedido se cierran como PERDIDA (RN-08). No hace falta avisar aparte a los
     * que perdieron: `PedidoYaTomado` ya les llega por el canal compartido y refrescan su pool.
     */
    public function cerrarPorAceptacion(Pedido $pedido, Conductor $ganador): void
    {
        PedidoOferta::where('id_pedido', $pedido->id_pedido)
            ->where('id_conductor', $ganador->id_conductor)
            ->update(['estado' => 'ACEPTADA', 'respondida_en' => now()]);

        PedidoOferta::where('id_pedido', $pedido->id_pedido)
            ->where('estado', 'PENDIENTE')
            ->update(['estado' => 'PERDIDA', 'respondida_en' => now()]);
    }

    /**
     * DISPONIBLE y sin pedido activo (RN-01, RN-06), sin contar ya una oferta pendiente para este
     * mismo pedido. Excluye a quien ya rechazó o perdió este pedido (RN-05), salvo que no quede
     * nadie más y sea el intento final: ahí sí se le vuelve a ofrecer.
     *
     * @return Collection<int, Conductor>
     */
    private function conductoresElegibles(Pedido $pedido): Collection
    {
        $base = Conductor::where('disponibilidad', 'DISPONIBLE')
            ->whereDoesntHave('pedidos', fn ($q) => $q->whereNotIn('estado', PedidoEstadoService::ESTADOS_FINALES));

        $idsDescartados = PedidoOferta::where('id_pedido', $pedido->id_pedido)
            ->whereIn('estado', ['RECHAZADA', 'PERDIDA'])
            ->pluck('id_conductor');

        if ($idsDescartados->isEmpty()) {
            return $base->get();
        }

        $elegibles = (clone $base)->whereNotIn('id_conductor', $idsDescartados)->get();

        // `veces_ofertado` ya se incrementó para esta ronda (ver `ofertar`): si es la 3a, es la
        // última oportunidad antes de caer a asignación manual.
        $esUltimoIntento = $pedido->veces_ofertado >= self::MAX_INTENTOS;

        return ($elegibles->isEmpty() && $esUltimoIntento) ? $base->get() : $elegibles;
    }
}
