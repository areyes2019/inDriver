<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Tenant\PedidoCanceladoParaConductor;
use App\Events\Tenant\PedidoCanceladoSinConductor;
use App\Events\Tenant\PedidoCerradoPorIncidencia;
use App\Events\Tenant\PedidoDevuelto;
use App\Events\Tenant\PedidoEntregado;
use App\Events\Tenant\PedidoEstadoCambiado;
use App\Events\Tenant\PedidoYaTomado;
use App\Events\Tenant\SaldoTenantCambiado;
use App\Models\Tenant\ComisionConductor;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorPosicion;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\ConsumoViajeTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\SimulacionEnvio;
use App\Models\Tenant\VentaViajeConductor;
use App\Services\Gps\BuzonGps;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Transición de estado de un pedido + liquidación del conductor al entregar.
 *
 * Extraído de `Tenant\PedidoController` (spec tenant/013) para que tanto el panel de
 * despachador/admin como la app de conductor (panda_express) compartan exactamente la misma
 * máquina de estados y la misma regla de liquidación, sin duplicarla. Las reglas de esa máquina
 * están en la spec tenant/006, sección "Máquina de estados del pedido".
 */
class PedidoEstadoService
{
    public const ESTADOS_FINALES = ['ENTREGADO', 'CANCELADO', 'INCIDENCIA', 'DEVUELTO'];

    /**
     * Lo que se le cobra al vendedor por el regreso de un paquete que el comprador no pagó, como
     * porcentaje del envío (spec tenant/031, D11).
     */
    public const PORCENTAJE_TARIFA_REGRESO = 30;

    /**
     * La fase "En recogida": hay conductor pero todavía no trae el paquete. Es lo único que el
     * conductor puede cancelar.
     */
    public const ESTADOS_EN_RECOGIDA = ['TOMADO', 'ARRIBADO'];

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
        'EN_REGRESO',
    ];

    /**
     * Mapa de transiciones válidas: desde cada estado, a qué estados se puede pasar.
     *
     * El regreso `TOMADO`/`ARRIBADO` -> `PUBLICADO` no está aquí a propósito: solo lo provoca el
     * conductor al cancelar, y ocurre por `devolverPorConductor()`. Con el paquete encima no se
     * cancela: `EN_CAMINO` y `ARRIBADO_A_ENTREGA` solo salen entregando o con `INCIDENCIA`.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSICIONES = [
        'PENDIENTE' => ['PUBLICADO', 'CANCELADO'],
        // PUBLICADO -> PENDIENTE: spec tenant/020, RN-04. Se reofertó 3 veces sin que nadie
        // aceptara; vuelve al punto de partida para que el AdminCliente lo asigne a mano.
        'PUBLICADO' => ['TOMADO', 'PENDIENTE', 'CANCELADO'],
        'TOMADO' => ['ARRIBADO', 'CANCELADO'],
        'ARRIBADO' => ['EN_CAMINO', 'CANCELADO'],
        'EN_CAMINO' => ['ARRIBADO_A_ENTREGA', 'INCIDENCIA'],
        'ARRIBADO_A_ENTREGA' => ['ENTREGADO', 'INCIDENCIA', 'EN_REGRESO'],
        'EN_REGRESO' => ['DEVUELTO', 'INCIDENCIA'],
        'DEVUELTO' => [],
        'ENTREGADO' => [],
        'CANCELADO' => [],
        'INCIDENCIA' => [],
    ];

    /**
     * Subconjunto de `TRANSICIONES` que puede disparar el conductor desde la app (spec
     * tenant/013): solo avanzar, y solo sobre su propio pedido activo (el llamador valida la
     * pertenencia antes de invocar este servicio). Cancelar va por `devolverPorConductor()`.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSICIONES_CONDUCTOR = [
        'PUBLICADO' => ['TOMADO'],
        'TOMADO' => ['ARRIBADO'],
        'ARRIBADO' => ['EN_CAMINO'],
        'EN_CAMINO' => ['ARRIBADO_A_ENTREGA'],
        'ARRIBADO_A_ENTREGA' => ['ENTREGADO', 'EN_REGRESO'],
        'EN_REGRESO' => ['DEVUELTO'],
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

        if ($nuevoEstado === 'EN_REGRESO') {
            $this->iniciarRegreso($pedido);
        }

        $pedido->estado = $nuevoEstado;

        match ($nuevoEstado) {
            'PUBLICADO' => $pedido->fecha_publicacion = now(),
            'TOMADO' => $pedido->fecha_asignacion = now(),
            'ENTREGADO', 'DEVUELTO' => $pedido->fecha_entrega = now(),
            'CANCELADO', 'INCIDENCIA' => $pedido->fecha_cancelacion = now(),
            default => null,
        };

        // Un regreso cuenta como viaje trabajado (spec tenant/031, D15).
        $viajeTrabajado = in_array($nuevoEstado, ['ENTREGADO', 'DEVUELTO'], true);

        if ($viajeTrabajado && $pedido->id_conductor) {
            $this->liquidarConductor($pedido);
            $this->tracking->calcularResumenRuta($pedido);
        }

        if ($viajeTrabajado) {
            $this->consumirViajeDelTenant($pedido);
        }

        $this->abrirTramoSimulado($pedido, $nuevoEstado);

        $this->avisarServicioGps($pedido, $nuevoEstado);

        $this->notificarConductores($pedido, $nuevoEstado, $estadoAnterior);
    }

    /**
     * El conductor canceló en En recogida: el pedido regresa a `PUBLICADO` y lo pueden tomar otros
     * (spec tenant/006; tenant/020, RN-09).
     *
     * No pasa por `transicionar()` porque el orden importa: `ofertar()` elige a quién ofrecerle
     * mirando la base, así que la asignación tiene que estar ya limpia y confirmada; si no, quien
     * canceló seguiría contando como ocupado. Por eso esta operación sí guarda, a diferencia de
     * `transicionar()`.
     *
     * Reofertar va fuera de la transacción y protegido, igual que publicar (spec tenant/018, RN-08):
     * si Reverb está caído, el conductor ya quedó libre y el pedido ya está publicado.
     *
     * @throws ValidationException si el pedido ya no está En recogida o ya no es de este conductor.
     */
    public function devolverPorConductor(Pedido $pedido, Conductor $conductor): Pedido
    {
        $idConductor = $conductor->id_conductor;

        $devuelto = DB::transaction(function () use ($pedido, $conductor, $idConductor) {
            /** @var Pedido $bloqueado */
            $bloqueado = Pedido::query()->lockForUpdate()->findOrFail($pedido->id_pedido);

            if ($bloqueado->id_conductor !== $idConductor || ! in_array($bloqueado->estado, self::ESTADOS_EN_RECOGIDA, true)) {
                throw ValidationException::withMessages([
                    'estado' => "No se puede cancelar el pedido en estado {$bloqueado->estado}.",
                ]);
            }

            $idPedido = $bloqueado->id_pedido;
            $slug = tenant()?->slug;

            $this->ofertas->prepararReoferta($bloqueado, $conductor);

            // El tramo simulado iba a nombre de este conductor, y `abrirTramo()` no abre uno nuevo
            // si ya existe: sin borrarlo, quien lo tome después en TEST nunca avanzaría.
            SimulacionEnvio::where('id_pedido', $idPedido)->delete();

            // Su recorrido no es parte del envío que entregará otro: contarlo inflaría la distancia
            // y la línea del resumen. Las posiciones se conservan a nombre del conductor.
            ConductorPosicion::where('id_pedido', $idPedido)
                ->where('id_conductor', $idConductor)
                ->update(['id_pedido' => null]);

            if (! $bloqueado->esTest() && $slug !== null) {
                $this->diferir($bloqueado, fn () => $this->gps->envioTerminado($slug, $idConductor, $idPedido));
            }

            if ($slug !== null) {
                $this->diferir($bloqueado, fn () => PedidoDevuelto::dispatch($idPedido, $slug, $idConductor));
            }

            $bloqueado->estado = 'PUBLICADO';
            $bloqueado->id_conductor = null;
            $bloqueado->id_vehiculo = null;
            $bloqueado->fecha_asignacion = null;
            $bloqueado->fecha_publicacion = now();
            $bloqueado->notificado_conductor_en = null;
            $bloqueado->veces_ofertado = 0;
            $bloqueado->save();

            return $bloqueado;
        });

        try {
            $this->ofertas->ofertar($devuelto);
            $this->ofertas->reactivarColaPara($conductor);
        } catch (\Throwable $e) {
            Log::warning('No se pudo reofertar el pedido devuelto por el conductor.', [
                'id_pedido' => $devuelto->id_pedido,
                'id_conductor' => $idConductor,
                'error' => $e->getMessage(),
            ]);
        }

        return $devuelto;
    }

    /**
     * El comprador no pagó (spec tenant/031): solo hay regreso si el repartidor adelantó un
     * producto. La tarifa queda fija aquí, para que un cambio posterior del porcentaje no altere lo
     * que ya se le dijo al repartidor (RN-07).
     *
     * @throws ValidationException si el pedido no es contra entrega.
     */
    private function iniciarRegreso(Pedido $pedido): void
    {
        if ($pedido->modalidad_pago !== 'RECEPTOR_PAGA_ENVIO_PRODUCTOS' || (float) $pedido->importe_cobro <= 0) {
            throw ValidationException::withMessages([
                'estado' => 'Solo un pedido contra entrega puede regresar al vendedor.',
            ]);
        }

        $pedido->tarifa_regreso = round((float) $pedido->importe_envio * self::PORCENTAJE_TARIFA_REGRESO / 100, 2);
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

        // TOMADO es el primer estado en el que el conductor se pone en movimiento; los estados
        // finales son el complemento exacto (`ESTADOS_FINALES`). La devolución del conductor avisa
        // desde `devolverPorConductor()`.
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

        if ($nuevoEstado === 'INCIDENCIA' && $pedido->id_conductor !== null) {
            $this->notificarIncidencia($pedido, $estadoAnterior);

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
            // Sin conductor no hay a quién avisar por push, pero el Panel lo tiene en "Viajes en
            // turno" y los conductores en su lista (spec tenant/022, RN-01).
            $nuevoEstado === 'CANCELADO' => $this->diferir($pedido, fn () => PedidoCanceladoSinConductor::dispatch($idPedido, $slug)),
            default => null,
        };
    }

    /**
     * Descuenta 1 viaje del saldo del tenant frente a ADMIN_CENTRAL (spec tenant/015, corrección
     * "el saldo del tenant se consume por envío entregado", RN-C2). Es independiente de
     * `liquidarConductor()`: aquella es la cuenta del tenant con cada conductor; esta, la del tenant
     * con quien le vendió el paquete. Consume en Prepago y en Comisión por igual.
     *
     * Va diferido como los avisos: `transicionar()` no guarda, y un consumo escrito antes del
     * `save()` quedaría huérfano si la entrega nunca llegara a persistirse. `firstOrCreate` sobre
     * el `UNIQUE(id_pedido)` es la garantía de "una sola vez" aunque `saved()` corra dos veces, y el
     * aviso solo sale si la fila es nueva. Se difiere ANTES que `pedido.entregado`
     * (`notificarConductores`) para que quien lea el saldo tras ese aviso ya lo vea descontado.
     *
     * En TEST no se consume nada (spec tenant/025, enmienda a RN-06): es la única excepción a "el
     * dinero no se simula", porque este saldo lo pagó el tenant contra un paquete con precio.
     */
    private function consumirViajeDelTenant(Pedido $pedido): void
    {
        if ($pedido->esTest()) {
            return;
        }

        $slug = tenant()?->slug;

        $this->diferir($pedido, function () use ($pedido, $slug): void {
            $consumo = ConsumoViajeTenant::firstOrCreate(['id_pedido' => $pedido->id_pedido]);

            if ($consumo->wasRecentlyCreated && $slug !== null) {
                SaldoTenantCambiado::dispatch(SaldoTenantCambiado::MOTIVO_CONSUMO, $pedido->id_pedido, $slug);
            }
        });
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
     * spec tenant/022: el aviso con push y confirmación es solo para el envío con conductor (RN-01);
     * el que no lo tiene sale por `PedidoCanceladoSinConductor`. Solo se cancela En recogida
     * (RN-04), cuando el conductor todavía no trae el paquete: la instrucción siempre es `STOP`.
     */
    private function notificarCancelacion(Pedido $pedido, string $estadoAnterior): void
    {
        $slug = tenant()?->slug;

        if ($slug === null) {
            return;
        }

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
            'STOP',
        ];

        $this->diferir($pedido, fn () => PedidoCanceladoParaConductor::dispatch(...$argumentos));

        $this->cambios->requiereConfirmacion($pedido);
    }

    /**
     * spec tenant/022, RN-17: el conductor lleva el paquete encima y el viaje se cerró por una
     * emergencia. Mismo circuito que la cancelación —bitácora, push y confirmación— porque tampoco
     * puede perderse el aviso.
     */
    private function notificarIncidencia(Pedido $pedido, string $estadoAnterior): void
    {
        $slug = tenant()?->slug;

        if ($slug === null) {
            return;
        }

        $this->cambios->registrarCambio(
            $pedido,
            'CANCELADO',
            ['estado' => $estadoAnterior],
            ['estado' => 'INCIDENCIA', 'motivo' => $pedido->motivo_cancelacion],
            'ADMIN',
            null,
        );

        $argumentos = [$pedido->id_pedido, $slug, $pedido->id_conductor, $pedido->motivo_cancelacion];

        $this->diferir($pedido, fn () => PedidoCerradoPorIncidencia::dispatch(...$argumentos));

        $this->cambios->requiereConfirmacion($pedido);
    }

    /**
     * Descuenta 1 viaje del saldo prepagado del conductor, o genera la comisión del pedido, según
     * la modalidad de cobro configurada para el tenant (spec 015; comisión: spec tenant/030).
     *
     * La modalidad y el porcentaje se resuelven aquí, al transicionar, y se pasan ya fijos a la
     * clausura diferida: un cambio de configuración entre la transición y el commit no puede
     * alterar lo que este viaje generó (RN-04).
     */
    private function liquidarConductor(Pedido $pedido): void
    {
        $modalidad = ConfiguracionTenant::obtener(ConfiguracionTenant::MODALIDAD, 'Prepago');

        if ($modalidad === 'Comision') {
            $porcentaje = (float) ConfiguracionTenant::obtener(ConfiguracionTenant::COMISION_PORCENTAJE, '0');

            $this->diferir($pedido, fn () => $this->generarComision($pedido, $porcentaje));

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

    /**
     * Anota la comisión del envío a favor del conductor (spec tenant/030). Gemela de
     * `consumirViajeDelTenant()`: va diferida hasta que la entrega quedó escrita, y `firstOrCreate`
     * sobre el `UNIQUE(id_pedido)` cubre un `saved()` repetido sin duplicar ni fallar (RN-03).
     *
     * La base es **solo** `importe_envio` (RN-02): ni `importe_cobro`, ni `pago_extra`, ni la
     * mercancía. Con porcentaje cero la fila nace `PAGADA`: queda constancia del viaje sin dejar
     * un pendiente de $0 para siempre (RN-05). A diferencia del consumo del tenant, aquí TEST y
     * LIVE generan por igual (supuesto 4): el sistema completo se prueba en TEST.
     *
     * No toca `pedidos.comision_calculada`: esa columna queda sin uso (adición 7).
     */
    private function generarComision(Pedido $pedido, float $porcentaje): void
    {
        $importeEnvio = round((float) $pedido->importe_envio, 2);
        $importeComision = round($importeEnvio * $porcentaje / 100, 2);

        ComisionConductor::firstOrCreate(
            ['id_pedido' => $pedido->id_pedido],
            [
                'id_conductor' => $pedido->id_conductor,
                'importe_envio' => $importeEnvio,
                'porcentaje_aplicado' => round($porcentaje, 2),
                'importe_comision' => $importeComision,
                'estado' => $importeComision > 0 ? ComisionConductor::ESTADO_PENDIENTE : ComisionConductor::ESTADO_PAGADA,
                'pagada_en' => $importeComision > 0 ? null : now(),
            ],
        );
    }
}
