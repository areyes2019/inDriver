<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\AutomaticoBitacora;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Modo automático (spec tenant/034): reglas fijas que atienden la flotilla sin nadie en el Panel.
 * Nada de aquí llama a la IA (D1) ni le avisa al dueño (D7): lo que hace queda en la bitácora.
 */
class ModoAutomaticoService
{
    public const NO = 'No';

    public const SI = 'Si';

    public const PAUSADO = 'Pausado';

    /** RN-09. */
    public const MINUTOS_ENTRE_REOFERTAS = 5;

    /** RN-08. */
    public const MINUTOS_PARA_CANCELAR = 30;

    /** RN-24: cancelaciones del sistema en una hora que pausan el modo. */
    public const LIMITE_CANCELACIONES_POR_HORA = 5;

    /** RN-15: minutos sin avanzar, por fase. */
    public const MINUTOS_ATORADO_RECOGIDA = 30;

    public const MINUTOS_ATORADO_ENTREGA = 60;

    public const ESTADOS_EN_ENTREGA = ['EN_CAMINO', 'ARRIBADO_A_ENTREGA'];

    /** RN-27. */
    public const DIAS_BITACORA = 90;

    public const MOTIVO_SIN_CONDUCTOR = 'Sin conductor disponible';

    public function __construct(
        private readonly PedidoEstadoService $estados,
        private readonly OfertaPedidoService $ofertas,
    ) {}

    public function estado(): string
    {
        return ConfiguracionTenant::obtener(ConfiguracionTenant::MODO_AUTOMATICO, self::NO);
    }

    /**
     * RN-02: prendido **y** la flotilla en LIVE. Con la flotilla en TEST el interruptor se guarda,
     * pero no hace nada.
     */
    public function activo(): bool
    {
        return $this->estado() === self::SI
            && ConfiguracionTenant::obtener(ConfiguracionTenant::AMBIENTE, Pedido::AMBIENTE_LIVE) === Pedido::AMBIENTE_LIVE;
    }

    /**
     * Prender (también desde `Pausado`, RN-25) reinicia el momento desde el que cuentan las reglas.
     */
    public function cambiar(string $nuevo): void
    {
        if ($nuevo === self::SI && $this->estado() !== self::SI) {
            ConfiguracionTenant::establecer(ConfiguracionTenant::MODO_AUTOMATICO_DESDE, now()->toIso8601String());
        }

        ConfiguracionTenant::establecer(ConfiguracionTenant::MODO_AUTOMATICO, $nuevo);
    }

    public function anotar(string $accion, string $detalle, ?Pedido $pedido = null): void
    {
        AutomaticoBitacora::create([
            'id_pedido' => $pedido?->id_pedido,
            'accion' => $accion,
            'detalle' => mb_substr($detalle, 0, 255),
        ]);
    }

    /**
     * Una vuelta del comando de cada minuto (RN-07, RN-15). Cada pedido va protegido: uno que
     * falle no puede dejar sin atender a los demás.
     */
    public function atender(): void
    {
        if (! $this->activo()) {
            return;
        }

        foreach ($this->pedidosSinConductor() as $pedido) {
            if (! $this->activo()) {
                return;
            }

            $this->protegido($pedido, fn () => $this->atenderSinConductor($pedido));
        }

        foreach ($this->pedidosConConductor() as $pedido) {
            $this->protegido($pedido, fn () => $this->revisarAtorado($pedido));
        }
    }

    /**
     * RN-08 a RN-11.
     */
    private function atenderSinConductor(Pedido $pedido): void
    {
        $ahora = now();
        $inicio = $this->momentoDeInicio($pedido);

        if ($inicio->copy()->addMinutes(self::MINUTOS_PARA_CANCELAR)->lessThanOrEqualTo($ahora)) {
            $this->cancelarSinConductor($pedido);

            return;
        }

        $base = $pedido->ultima_reoferta_automatica !== null && $pedido->ultima_reoferta_automatica->greaterThan($inicio)
            ? $pedido->ultima_reoferta_automatica
            : $inicio;

        if ($base->copy()->addMinutes(self::MINUTOS_ENTRE_REOFERTAS)->lessThanOrEqualTo($ahora)) {
            $this->reofrecer($pedido);
        }
    }

    /**
     * RN-11: se relee con candado y solo sigue si todavía espera conductor. Si una persona o la
     * cola de la spec 026 ya lo movieron, no hace nada (D9).
     */
    private function reofrecer(Pedido $pedido): void
    {
        $reofrecido = DB::transaction(function () use ($pedido): ?Pedido {
            $bloqueado = $this->releerSiSigueSinConductor($pedido);

            if ($bloqueado === null) {
                return null;
            }

            $bloqueado->update(['ultima_reoferta_automatica' => now()]);

            return $bloqueado;
        });

        if ($reofrecido === null) {
            return;
        }

        $this->ofertas->rescatarDeAsignacionManual($reofrecido);

        $this->anotar(AutomaticoBitacora::REOFERTA, "Se volvió a ofrecer el envío {$reofrecido->numero_pedido} a los repartidores.", $reofrecido);
    }

    /**
     * RN-10: la misma transición que usa el Panel, así que salen los mismos avisos que hoy.
     */
    private function cancelarSinConductor(Pedido $pedido): void
    {
        $cancelado = DB::transaction(function () use ($pedido): ?Pedido {
            $bloqueado = $this->releerSiSigueSinConductor($pedido);

            if ($bloqueado === null) {
                return null;
            }

            $bloqueado->cancelado_por = 'SISTEMA';
            $bloqueado->motivo_cancelacion = self::MOTIVO_SIN_CONDUCTOR;
            $this->estados->transicionar($bloqueado, 'CANCELADO');
            $bloqueado->save();

            return $bloqueado;
        });

        if ($cancelado === null) {
            return;
        }

        $this->anotar(
            AutomaticoBitacora::CANCELACION,
            "Se canceló el envío {$cancelado->numero_pedido}: ningún repartidor lo tomó en ".self::MINUTOS_PARA_CANCELAR.' minutos.',
            $cancelado,
        );

        $this->pausarSiHaceFalta();
    }

    /**
     * RN-24: a la quinta cancelación del sistema en la última hora, desde la última vez que se
     * prendió, el modo se pausa solo.
     */
    private function pausarSiHaceFalta(): void
    {
        $desde = $this->prendidoDesde();
        $haceUnaHora = now()->subHour();
        $limiteInferior = $desde !== null && $desde->greaterThan($haceUnaHora) ? $desde : $haceUnaHora;

        $cancelaciones = Pedido::withoutGlobalScopes()
            ->where('cancelado_por', 'SISTEMA')
            ->where('fecha_cancelacion', '>=', $limiteInferior)
            ->count();

        if ($cancelaciones < self::LIMITE_CANCELACIONES_POR_HORA) {
            return;
        }

        ConfiguracionTenant::establecer(ConfiguracionTenant::MODO_AUTOMATICO, self::PAUSADO);

        $this->anotar(
            AutomaticoBitacora::PAUSA,
            "El modo automático se pausó: se cancelaron {$cancelaciones} envíos en la última hora por falta de repartidor. Vuelve a prenderlo cuando lo revises.",
        );
    }

    /**
     * RN-15 y RN-16: solo se anota, una vez por fase. No cambia el estado ni el conductor (D12).
     */
    private function revisarAtorado(Pedido $pedido): void
    {
        if ($pedido->aviso_atorado_en !== null) {
            return;
        }

        $minutosLimite = in_array($pedido->estado, PedidoEstadoService::ESTADOS_EN_RECOGIDA, true)
            ? self::MINUTOS_ATORADO_RECOGIDA
            : self::MINUTOS_ATORADO_ENTREGA;

        $desde = $pedido->estado_cambiado_en ?? $pedido->fecha_asignacion ?? $pedido->updated_at;
        $prendido = $this->prendidoDesde();

        // RN-04: lo que ya estaba atorado al prender empieza a contar desde ese momento.
        if ($prendido !== null && $desde !== null && $prendido->greaterThan($desde)) {
            $desde = $prendido;
        }

        if ($desde === null || $desde->copy()->addMinutes($minutosLimite)->greaterThan(now())) {
            return;
        }

        $minutos = (int) $desde->diffInMinutes(now());
        $fase = in_array($pedido->estado, PedidoEstadoService::ESTADOS_EN_RECOGIDA, true) ? 'recogida' : 'entrega';
        $usuario = $pedido->conductor?->usuario;
        $repartidor = $usuario !== null ? trim("{$usuario->nombre} {$usuario->apellido_paterno}") : 'sin nombre';

        Pedido::withoutGlobalScopes()->whereKey($pedido->id_pedido)->update(['aviso_atorado_en' => now()]);

        $this->anotar(
            AutomaticoBitacora::ATORADO,
            "El envío {$pedido->numero_pedido} lleva {$minutos} minutos en {$fase} ({$pedido->estado}) sin avanzar. Repartidor: {$repartidor}.",
            $pedido,
        );
    }

    /**
     * RN-07: LIVE (RN-03), en `PENDIENTE`, sin conductor, publicado alguna vez y que ya agotó sus
     * rondas. Un borrador del despachador nunca se publicó y queda fuera.
     *
     * @return Collection<int, Pedido>
     */
    private function pedidosSinConductor()
    {
        return Pedido::withoutGlobalScopes()
            ->where('ambiente', Pedido::AMBIENTE_LIVE)
            ->where('estado', 'PENDIENTE')
            ->whereNull('id_conductor')
            ->whereNotNull('fecha_publicacion')
            ->whereNotNull('sin_conductor_desde')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return Collection<int, Pedido>
     */
    private function pedidosConConductor()
    {
        return Pedido::withoutGlobalScopes()
            ->with('conductor.usuario')
            ->where('ambiente', Pedido::AMBIENTE_LIVE)
            ->whereIn('estado', [...PedidoEstadoService::ESTADOS_EN_RECOGIDA, ...self::ESTADOS_EN_ENTREGA])
            ->whereNotNull('id_conductor')
            ->whereNull('aviso_atorado_en')
            ->get();
    }

    private function releerSiSigueSinConductor(Pedido $pedido): ?Pedido
    {
        /** @var Pedido|null $bloqueado */
        $bloqueado = Pedido::withoutGlobalScopes()->lockForUpdate()->find($pedido->id_pedido);

        if ($bloqueado === null
            || $bloqueado->estado !== 'PENDIENTE'
            || $bloqueado->id_conductor !== null
            || $bloqueado->sin_conductor_desde === null) {
            return null;
        }

        return $bloqueado;
    }

    /**
     * RN-07 y RN-04: lo más reciente entre que cayó sin conductor y que se prendió el modo.
     */
    private function momentoDeInicio(Pedido $pedido): Carbon
    {
        $inicio = $pedido->sin_conductor_desde->copy();
        $prendido = $this->prendidoDesde();

        return $prendido !== null && $prendido->greaterThan($inicio) ? $prendido : $inicio;
    }

    private function prendidoDesde(): ?Carbon
    {
        $valor = ConfiguracionTenant::obtener(ConfiguracionTenant::MODO_AUTOMATICO_DESDE);

        return $valor !== null ? Carbon::parse($valor) : null;
    }

    private function protegido(Pedido $pedido, \Closure $accion): void
    {
        try {
            $accion();
        } catch (\Throwable $e) {
            Log::warning('El modo automático no pudo atender un pedido.', [
                'id_pedido' => $pedido->id_pedido,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
