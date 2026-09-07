<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Tenant\PedidoCanceladoParaConductor;
use App\Events\Tenant\PedidoEntregado;
use App\Events\Tenant\PedidoEstadoCambiado;
use App\Events\Tenant\PedidoYaTomado;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\VentaViajeConductor;
use Illuminate\Validation\ValidationException;

/**
 * Transición de estado de un pedido + liquidación del conductor al entregar.
 *
 * Extraído de `Tenant\PedidoController` (spec tenant/013) para que tanto el panel de
 * despachador/admin como la app de conductor (panda_express) compartan exactamente la misma
 * máquina de estados y la misma regla de liquidación, sin duplicarla.
 */
class PedidoEstadoService
{
    public const ESTADOS_FINALES = ['ENTREGADO', 'CANCELADO', 'RECHAZADO'];

    /**
     * Mapa de transiciones válidas: desde cada estado, a qué estados se puede pasar.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSICIONES = [
        'PENDIENTE' => ['PUBLICADO', 'CANCELADO'],
        // PUBLICADO -> PENDIENTE: spec tenant/020, RN-04. Se reofertó 3 veces sin que nadie
        // aceptara; vuelve al punto de partida para que el AdminCliente lo asigne a mano.
        'PUBLICADO' => ['TOMADO', 'PENDIENTE', 'RECHAZADO', 'CANCELADO'],
        'TOMADO' => ['ARRIBADO', 'CANCELADO'],
        'ARRIBADO' => ['EN_CAMINO', 'CANCELADO'],
        'EN_CAMINO' => ['ARRIBADO_A_ENTREGA', 'CANCELADO'],
        'ARRIBADO_A_ENTREGA' => ['ENTREGADO', 'CANCELADO'],
        'ENTREGADO' => [],
        'RECHAZADO' => [],
        'CANCELADO' => [],
    ];

    /**
     * Subconjunto de `TRANSICIONES` que puede disparar el conductor desde la app (spec
     * tenant/013): nunca `RECHAZADO`, y solo sobre su propio pedido activo (el llamador valida la
     * pertenencia antes de invocar este servicio).
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSICIONES_CONDUCTOR = [
        'PUBLICADO' => ['TOMADO'],
        'TOMADO' => ['ARRIBADO', 'CANCELADO'],
        'ARRIBADO' => ['EN_CAMINO', 'CANCELADO'],
        'EN_CAMINO' => ['ARRIBADO_A_ENTREGA', 'CANCELADO'],
        'ARRIBADO_A_ENTREGA' => ['ENTREGADO', 'CANCELADO'],
    ];

    public function __construct(
        private readonly OfertaPedidoService $ofertas,
        private readonly TrackingService $tracking,
        private readonly CambioEnvioService $cambios,
    ) {}

    /**
     * Aplica la transición y sus efectos (fechas, liquidación) sobre el modelo en memoria. No
     * llama a `save()` — el llamador decide cuándo persistir, para poder envolverlo en su propia
     * transacción/auditoría.
     *
     * @throws ValidationException si la transición no es válida desde el estado actual.
     */
    public function transicionar(Pedido $pedido, string $nuevoEstado): void
    {
        $estadoAnterior = $pedido->estado;
        $permitidos = self::TRANSICIONES[$estadoAnterior] ?? [];

        if (! in_array($nuevoEstado, $permitidos, true)) {
            throw ValidationException::withMessages([
                'estado' => "No se puede pasar el pedido de {$pedido->estado} a {$nuevoEstado}.",
            ]);
        }

        $pedido->estado = $nuevoEstado;

        match ($nuevoEstado) {
            'PUBLICADO' => $pedido->fecha_publicacion = now(),
            'TOMADO' => $pedido->fecha_asignacion = now(),
            'ENTREGADO' => $pedido->fecha_entrega = now(),
            'CANCELADO' => $pedido->fecha_cancelacion = now(),
            default => null,
        };

        if ($nuevoEstado === 'ENTREGADO' && $pedido->id_conductor) {
            $this->liquidarConductor($pedido);
            $this->tracking->calcularResumenRuta($pedido);
        }

        $this->notificarConductores($pedido, $nuevoEstado, $estadoAnterior);
    }

    /**
     * Dispara los eventos de tiempo real (spec tenant/013) para que la app de conductor se entere
     * al instante, sin esperar su sondeo de 10s. `tenant()` resuelve al tenant ya inicializado por
     * `IdentificarTenantPorSlug` para esta petición. Se usa el slug (no el id numérico) para
     * nombrar el canal porque panda_express ya lo conoce en build-time (spec tenant/013, "un solo
     * tenant por build") — evita agregar `id_tenant` a las respuestas de la API solo para esto.
     */
    private function notificarConductores(Pedido $pedido, string $nuevoEstado, string $estadoAnterior): void
    {
        // PUBLICADO no dispara un solo aviso general: spec tenant/020 crea una oferta individual
        // por conductor elegible, con su propia ventana de 45s — ver `OfertaPedidoService`.
        if ($nuevoEstado === 'PUBLICADO') {
            $this->ofertas->ofertar($pedido);

            return;
        }

        if ($nuevoEstado === 'CANCELADO' && $pedido->id_conductor !== null) {
            $this->notificarCancelacion($pedido, $estadoAnterior);

            return;
        }

        $slug = tenant()?->slug;

        if ($slug === null) {
            return;
        }

        // La app solo conoce el estado que ella misma provoca, así que sin este aviso un cambio
        // hecho del lado del servidor (desde el Panel) sería invisible para el conductor.
        if ($pedido->id_conductor !== null) {
            PedidoEstadoCambiado::dispatch($pedido->id_pedido, $pedido->id_conductor, $nuevoEstado, $slug);
        }

        match (true) {
            $nuevoEstado === 'TOMADO' => PedidoYaTomado::dispatch($pedido->id_pedido, $slug),
            // spec tenant/023: el Panel calcula "Disponible"/"Ocupado" por pedido asignado, así que
            // necesita enterarse del cierre igual que se entera de la toma y de la cancelación.
            $nuevoEstado === 'ENTREGADO' => PedidoEntregado::dispatch($pedido->id_pedido, $slug),
            default => null,
        };
    }

    /**
     * spec tenant/022: solo se avisa por evento si el envío tiene conductor (RN-01) — cancelar uno
     * sin conductor asignado ya quedó cubierto arriba, sin más trámite. `EN_CAMINO` y
     * `ARRIBADO_A_ENTREGA` son los únicos estados donde el conductor ya trae el paquete encima
     * (RN-04, RN-10 de tenant/021): de ahí sale la compensación y la instrucción de qué hacer con
     * el paquete.
     */
    private function notificarCancelacion(Pedido $pedido, string $estadoAnterior): void
    {
        $slug = tenant()?->slug;

        if ($slug === null) {
            return;
        }

        $llevaPaquete = in_array($estadoAnterior, ['EN_CAMINO', 'ARRIBADO_A_ENTREGA'], true);

        $this->cambios->registrarCambio(
            $pedido,
            'CANCELADO',
            ['estado' => $estadoAnterior],
            ['estado' => 'CANCELADO', 'motivo' => $pedido->motivo_cancelacion],
            $pedido->cancelado_por === 'CLIENTE' ? 'CLIENTE' : 'ADMIN',
            null,
        );

        PedidoCanceladoParaConductor::dispatch(
            $pedido->id_pedido,
            $slug,
            $pedido->id_conductor,
            $pedido->cancelado_por,
            $pedido->motivo_cancelacion,
            $llevaPaquete,
            $llevaPaquete ? 'RETURN_TO_PICKUP' : 'STOP',
        );

        $this->cambios->requiereConfirmacion($pedido);
    }

    /**
     * Descuenta 1 viaje del saldo prepagado del conductor, o calcula la comisión del pedido, según
     * la modalidad de cobro configurada para el tenant (spec 015).
     */
    private function liquidarConductor(Pedido $pedido): void
    {
        $modalidad = ConfiguracionTenant::obtener(ConfiguracionTenant::MODALIDAD, 'Prepago');

        if ($modalidad === 'Comision') {
            $porcentaje = (float) ConfiguracionTenant::obtener(ConfiguracionTenant::COMISION_PORCENTAJE, '0');
            $pedido->comision_calculada = round((float) $pedido->importe_cobro * $porcentaje / 100, 2);

            return;
        }

        $vendidos = VentaViajeConductor::where('id_conductor', $pedido->id_conductor)->sum('cantidad_viajes');
        $consumidos = Pedido::where('id_conductor', $pedido->id_conductor)
            ->where('prepago_descontado', true)
            ->count();

        if (($vendidos - $consumidos) > 0) {
            $pedido->prepago_descontado = true;
        }
    }
}
