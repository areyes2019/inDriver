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
use App\Services\Gps\BuzonGps;
use Illuminate\Support\Facades\Log;
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
     * Los envíos vivos: lo que el Panel llama "Viajes en turno". Es el complemento exacto de
     * `ESTADOS_FINALES`.
     *
     * Vive aquí desde la spec tenant/027 porque el filtro pasó al servidor: antes lo repetía
     * `ServiciosEnTurno.vue` con su propio `Set`, después de traerse el historial completo del
     * tenant y descartarlo en el navegador.
     */
    public const ESTADOS_EN_TURNO = [
        'PENDIENTE',
        'PUBLICADO',
        'TOMADO',
        'ARRIBADO',
        'EN_CAMINO',
        'ARRIBADO_A_ENTREGA',
    ];

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
        private readonly BuzonGps $gps,
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

        $this->abrirTramoSimulado($pedido, $nuevoEstado);

        $this->avisarServicioGps($pedido, $nuevoEstado);

        $this->notificarConductores($pedido, $nuevoEstado, $estadoAnterior);
    }

    /**
     * Le dice al microservicio GPS cuándo empieza y cuándo termina un envío (spec tenant/028,
     * §6.3). Es la única forma que tiene el servicio de saber si debe guardar la posición de un
     * conductor (RN-02): no pregunta a Laravel en cada ping, se lo dijimos aquí.
     *
     * Va por `diferir()` como el resto de avisos (spec tenant/027): `transicionar()` no guarda, y
     * mandar el aviso antes de que la fila exista abriría el rastreo de un envío que todavía puede
     * no llegar a persistirse.
     *
     * En TEST no se avisa nunca (spec tenant/025, RN-11; spec tenant/028, RN-13): el simulador ya
     * escribe la posición por `TrackingService::registrarPosicion()`, y si el servicio GPS también
     * queda habilitado para este conductor, el GPS real del teléfono —sin señal confiable al probar
     * fuera de sitio— compite con el simulador por `conductor_estado` y puede dejarlo clavado en una
     * lectura de red/IP muy lejos de la ruta simulada, sin que nada lo corrija después (RN-03 la
     * toma como línea base y descarta cualquier lectura real posterior por "salto imposible").
     * `abrirTramoSimulado()` ya hace esta misma comprobación; aquí faltaba.
     */
    private function avisarServicioGps(Pedido $pedido, string $nuevoEstado): void
    {
        if ($pedido->esTest()) {
            return;
        }

        $slug = tenant()?->slug;
        $idConductor = $pedido->id_conductor;

        if ($slug === null || $idConductor === null) {
            return;
        }

        $idPedido = $pedido->id_pedido;

        // TOMADO es el primer estado en el que el conductor se pone en movimiento; los tres
        // estados finales son el complemento exacto (`ESTADOS_FINALES`).
        if ($nuevoEstado === 'TOMADO') {
            $this->diferir($pedido, fn () => $this->gps->envioIniciado($slug, $idConductor, $idPedido));

            return;
        }

        if (in_array($nuevoEstado, self::ESTADOS_FINALES, true)) {
            $this->diferir($pedido, fn () => $this->gps->envioTerminado($slug, $idConductor, $idPedido));
        }
    }

    /**
     * Único punto de contacto entre la máquina de estados y el modo TEST (spec tenant/025, RN-07).
     * En LIVE no hace absolutamente nada.
     *
     * Los dos momentos son los que en LIVE arrancan un desplazamiento real: aceptar el envío
     * (empieza a manejar hacia la recogida) y recoger el paquete (empieza a manejar hacia la
     * entrega). `EN_CAMINO` y `ENTREGADO` los sigue apretando el conductor en su app; lo que el
     * servidor suple son los dos hitos de *llegada*, que en TEST la geocerca nunca dispararía
     * porque el teléfono está quieto en otra parte.
     *
     * Envuelto en `try/catch` por el mismo criterio que el resto de efectos de `transicionar()`:
     * que Google no conteste no puede impedirle a un conductor aceptar un viaje.
     */
    private function abrirTramoSimulado(Pedido $pedido, string $nuevoEstado): void
    {
        if (! $pedido->esTest() || ! in_array($nuevoEstado, ['TOMADO', 'EN_CAMINO'], true)) {
            return;
        }

        try {
            $simulaciones = app(SimulacionEnvioService::class);

            match ($nuevoEstado) {
                'TOMADO' => $simulaciones->iniciarAcercamiento($pedido),
                'EN_CAMINO' => $simulaciones->iniciarEntrega($pedido),
            };
        } catch (\Throwable $e) {
            Log::warning('No se pudo abrir el tramo simulado del envío', [
                'id_pedido' => $pedido->id_pedido,
                'estado' => $nuevoEstado,
                'error' => $e->getMessage(),
            ]);
        }
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

        $idPedido = $pedido->id_pedido;
        $idConductor = $pedido->id_conductor;

        // La app solo conoce el estado que ella misma provoca, así que sin este aviso un cambio
        // hecho del lado del servidor (desde el Panel) sería invisible para el conductor.
        if ($idConductor !== null) {
            $this->diferir($pedido, fn () => PedidoEstadoCambiado::dispatch($idPedido, $idConductor, $nuevoEstado, $slug));
        }

        match (true) {
            $nuevoEstado === 'TOMADO' => $this->diferir($pedido, fn () => PedidoYaTomado::dispatch($idPedido, $slug)),
            // spec tenant/023: el Panel calcula "Disponible"/"Ocupado" por pedido asignado, así que
            // necesita enterarse del cierre igual que se entera de la toma y de la cancelación.
            $nuevoEstado === 'ENTREGADO' => $this->diferir($pedido, fn () => PedidoEntregado::dispatch($idPedido, $slug)),
            default => null,
        };
    }

    /**
     * Deja el aviso en cola hasta que el pedido se guarde (spec tenant/027).
     *
     * Estos eventos arman su carga releyendo el pedido de la base (`DatosDeEventoPanel`), y
     * `transicionar()` no guarda —deja que el llamador decida cuándo—, así que mandarlos aquí
     * significaba mandar el estado anterior: `pedido.tomado` salía con el `estado` todavía en
     * `PUBLICADO`, `id_conductor` nulo y, en consecuencia, `seguimiento` nulo —con lo que el mapa
     * del Panel se quedaba sin la línea en guiones del tramo H1—. `PedidoObserver::saved()` los
     * vacía ya con la fila escrita, y como el observador es `afterCommit`, también después de
     * confirmar la transacción cuando el llamador abrió una.
     *
     * `ofertar()` no pasa por aquí a propósito: sus eventos llevan el modelo en memoria, no un id
     * que haya que releer, y `Tenant\PedidoController::publicar()` depende de que las ofertas
     * existan antes de persistir.
     */
    private function diferir(Pedido $pedido, \Closure $aviso): void
    {
        $pedido->avisosDiferidos[] = $aviso;
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

        // Diferido como el resto (ver `diferir()`): su carga también se arma releyendo el pedido,
        // así que aquí salía con el estado de antes de cancelar.
        $argumentos = [
            $pedido->id_pedido,
            $slug,
            $pedido->id_conductor,
            $pedido->cancelado_por,
            $pedido->motivo_cancelacion,
            $llevaPaquete,
            $llevaPaquete ? 'RETURN_TO_PICKUP' : 'STOP',
        ];

        $this->diferir($pedido, fn () => PedidoCanceladoParaConductor::dispatch(...$argumentos));

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
