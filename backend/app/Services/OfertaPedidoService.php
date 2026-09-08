<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Tenant\ConductorColaReactivada;
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
     * Un conductor acaba de quedar libre —entregó, le cancelaron, o se conectó— y hay pedidos
     * esperando: se le ofrecen todos de golpe (spec tenant/026, RN-01 a RN-09).
     *
     * Sin esto su bandeja queda vacía después de entregar: mientras estuvo ocupado no era elegible
     * (RN-06 de tenant/020), así que la cola se ofreció a otros o agotó sus rondas, y nadie vuelve
     * a mirarlo cuando se libera.
     *
     * @return int cuántos pedidos se le reactivaron
     */
    public function reactivarColaPara(Conductor $conductor): int
    {
        if (! $this->puedeRecibirOfertas($conductor)) {
            return 0;
        }

        $pedidos = $this->pedidosEnCola();

        if ($pedidos->isEmpty()) {
            return 0;
        }

        // RN-06: lo que rechazó explícitamente no se le vuelve a ofrecer. `PERDIDA` y `EXPIRADA`
        // sí, porque ninguna de las dos fue una decisión suya.
        $rechazados = PedidoOferta::whereIn('id_pedido', $pedidos->pluck('id_pedido'))
            ->where('id_conductor', $conductor->id_conductor)
            ->where('estado', 'RECHAZADA')
            ->pluck('id_pedido')
            ->all();

        $reactivados = 0;

        foreach ($pedidos as $pedido) {
            if (in_array($pedido->id_pedido, $rechazados, true)) {
                continue;
            }

            if ($pedido->estado === 'PENDIENTE') {
                $this->rescatarDeAsignacionManual($pedido);
            } else {
                $this->ofertarSoloA($pedido, $conductor);
            }

            $reactivados++;
        }

        // RN-09: un solo aviso con el total. Tres pedidos en cola no pueden ser tres sonidos, tres
        // vibraciones y tres notificaciones del navegador para el mismo hecho.
        if ($reactivados > 0 && $slug = tenant()?->slug) {
            ConductorColaReactivada::dispatch($conductor->id_conductor, $reactivados, $slug);
        }

        return $reactivados;
    }

    /**
     * RN-07: solo trabaja quien está en línea, sin pedido activo y con saldo. Se relee de base
     * porque el llamador típico es el observador de `pedidos`, que trae el conductor tal como
     * estaba antes de la entrega.
     */
    private function puedeRecibirOfertas(Conductor $conductor): bool
    {
        $conductor->refresh();

        return $conductor->disponibilidad === 'DISPONIBLE'
            && ! $conductor->tienePedidoActivo()
            && app(DisponibilidadService::class)->tieneSaldoDisponible($conductor);
    }

    /**
     * Lo que está esperando conductor (RN-03): lo `PUBLICADO` sin dueño, más lo que cayó a
     * `PENDIENTE` por agotar sus rondas. Un `PENDIENTE` que nunca se publicó queda fuera: es un
     * borrador del despachador, y publicarlo solo porque apareció un conductor sería decidir por él
     * —de ahí el `whereNotNull('fecha_publicacion')`—.
     *
     * Orden por antigüedad del pedido (RN-08): el que lleva más tiempo esperando, primero.
     *
     * @return Collection<int, Pedido>
     */
    private function pedidosEnCola(): Collection
    {
        return Pedido::whereNull('id_conductor')
            ->where(function ($q) {
                $q->where('estado', 'PUBLICADO')
                    ->orWhere(fn ($q2) => $q2->where('estado', 'PENDIENTE')->whereNotNull('fecha_publicacion'));
            })
            ->orderBy('created_at')
            ->get();
    }

    /**
     * RN-04: vuelve a `PUBLICADO` con el contador de rondas en cero. `veces_ofertado` mide rondas
     * fallidas *habiendo conductores disponibles*; las que se gastaron con el tenant vacío no
     * cuentan, y sin reiniciarlo el pedido volvería a caer a asignación manual en la ronda
     * siguiente.
     *
     * Se persiste el cero ANTES de transicionar porque `ofertar()` —que corre dentro de la
     * transición— incrementa el contador con un UPDATE directo: dejarlo solo en memoria haría que
     * el incremento partiera del valor viejo de la base.
     */
    private function rescatarDeAsignacionManual(Pedido $pedido): void
    {
        $pedido->update(['veces_ofertado' => 0]);

        app(PedidoEstadoService::class)->transicionar($pedido, 'PUBLICADO');
        $pedido->save();
    }

    /**
     * Abre la oferta de un pedido ya publicado para un solo conductor, con su propia ventana de 45s
     * (RN-05).
     *
     * No se reusa `ofertar()` a propósito: eso sería una ronda nueva para todos —reiniciaría la
     * ventana de quienes ya la tenían corriendo y quemaría uno de los 3 intentos del pedido—
     * cuando lo que pasó es mucho menor: un conductor se sumó tarde a la ronda en curso.
     */
    private function ofertarSoloA(Pedido $pedido, Conductor $conductor): void
    {
        $ahora = now();
        $expiraEn = $ahora->copy()->addSeconds(self::VENTANA_SEGUNDOS);

        PedidoOferta::updateOrCreate(
            ['id_pedido' => $pedido->id_pedido, 'id_conductor' => $conductor->id_conductor],
            ['estado' => 'PENDIENTE', 'ofrecida_en' => $ahora, 'expira_en' => $expiraEn, 'respondida_en' => null],
        );

        if (tenant()) {
            ExpirarOfertaPedido::dispatch(tenant(), $pedido->id_pedido)->delay($expiraEn);
        }
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
