<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Conductor;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\SimulacionEnvio;
use Illuminate\Support\Facades\Log;

/**
 * Desplaza el conductor de un envío TEST por una ruta real de calles (spec tenant/025).
 *
 * Dos ideas sostienen todo:
 *
 * 1. **Las coordenadas entran por la puerta de siempre.** Se escriben con
 *    `TrackingService::registrarPosicion()`, el mismo método que usa el GPS del teléfono. Por eso
 *    el Panel, el mapa, los eventos Reverb y `resumen_ruta` funcionan en TEST sin una línea propia
 *    (RN-10).
 *
 * 2. **La posición se deduce del reloj, no de un contador** (RN-13). Producción no tiene un worker
 *    permanente: el `schedule` levanta un `queue:work` acotado a 55s por minuto (spec tenant/024),
 *    así que una cadena de jobs perdería eslabones y dejaría el viaje congelado. Aquí el único
 *    estado es `iniciada_en`: si una corrida se pierde, la siguiente recalcula dónde debería estar
 *    el móvil y escribe de golpe lo atrasado.
 */
class SimulacionEnvioService
{
    /** RN-09: 60 km/h constantes, sin tráfico ni semáforos. */
    public const VELOCIDAD_KMH_POR_DEFECTO = 60.0;

    /** RN-09: cada cuántos segundos simulados se escribe un punto (unos 33 m a 60 km/h). */
    public const PASO_SEGUNDOS_POR_DEFECTO = 2;

    /**
     * Configurable (`SIMULACION_VELOCIDAD_KMH`) solo por comodidad de desarrollo: esperar en tiempo
     * real a que el móvil recorra el trayecto vuelve impracticable probar el flujo varias veces
     * seguidas. Producción se queda en los 60 km/h de RN-09.
     */
    public static function velocidadKmh(): float
    {
        return max(1.0, (float) config('simulacion.velocidad_kmh', self::VELOCIDAD_KMH_POR_DEFECTO));
    }

    /** La velocidad en metros por segundo, que es como la consume el tracking. */
    public static function velocidadMs(): float
    {
        return self::velocidadKmh() * 1000 / 3600;
    }

    public static function pasoSegundos(): int
    {
        return max(1, (int) config('simulacion.paso_segundos', self::PASO_SEGUNDOS_POR_DEFECTO));
    }

    /** Distancia entre puntos consecutivos del recorrido. */
    public static function pasoM(): float
    {
        return self::velocidadMs() * self::pasoSegundos();
    }

    /**
     * Tope de puntos por tramo y por corrida. Un viaje que estuvo detenido horas (servidor caído,
     * cron apagado) no debe intentar escribir miles de posiciones de golpe: se escriben las últimas
     * y el móvil aparece donde le toca por reloj, que es lo que importa.
     */
    private const MAX_PUNTOS_POR_CORRIDA = 400;

    public function __construct(
        private readonly RutaService $rutas,
        private readonly TrackingService $tracking,
    ) {}

    /**
     * Abre el tramo de acercamiento: de donde está el conductor al punto de recogida (RN-07).
     * Al completarse, el envío pasa a `ARRIBADO`.
     */
    public function iniciarAcercamiento(Pedido $pedido): ?SimulacionEnvio
    {
        return $this->abrirTramo(
            $pedido,
            SimulacionEnvio::TRAMO_ACERCAMIENTO,
            $this->posicionDelConductor($pedido),
            ['lat' => (float) $pedido->latitud_recogida, 'lng' => (float) $pedido->longitud_recogida],
        );
    }

    /**
     * Abre el tramo de entrega: de la recogida al punto de entrega (RN-07). Al completarse, el
     * envío pasa a `ARRIBADO_A_ENTREGA`.
     */
    public function iniciarEntrega(Pedido $pedido): ?SimulacionEnvio
    {
        return $this->abrirTramo(
            $pedido,
            SimulacionEnvio::TRAMO_ENTREGA,
            ['lat' => (float) $pedido->latitud_recogida, 'lng' => (float) $pedido->longitud_recogida],
            ['lat' => (float) $pedido->latitud_entrega, 'lng' => (float) $pedido->longitud_entrega],
        );
    }

    /**
     * Avanza todos los tramos abiertos hasta donde diga el reloj. Es idempotente: llamarlo dos
     * veces seguidas no escribe nada la segunda vez, porque `avanzada_hasta_m` ya alcanzó al reloj.
     *
     * @return int cuántos puntos se escribieron
     */
    public function avanzarPendientes(): int
    {
        $escritos = 0;

        SimulacionEnvio::query()
            ->whereNull('terminada_en')
            ->with('pedido.conductor')
            ->get()
            ->each(function (SimulacionEnvio $simulacion) use (&$escritos) {
                $escritos += $this->avanzar($simulacion);
            });

        return $escritos;
    }

    /**
     * @return int cuántos puntos se escribieron para este tramo
     */
    public function avanzar(SimulacionEnvio $simulacion): int
    {
        $pedido = $simulacion->pedido;
        $conductor = $pedido?->conductor;

        // RN-14: el envío se canceló (o se cerró de cualquier otra forma) mientras el móvil iba en
        // camino. Se cierra el tramo y no se escribe nada más.
        if ($pedido === null || $conductor === null || in_array($pedido->estado, PedidoEstadoService::ESTADOS_FINALES, true)) {
            $simulacion->terminada_en = now();
            $simulacion->save();

            return 0;
        }

        $metrosPorReloj = min(
            $simulacion->distancia_m,
            max(0.0, now()->diffInSeconds($simulacion->iniciada_en, true) * self::velocidadMs()),
        );

        $escritos = 0;
        $avance = $simulacion->avanzada_hasta_m;

        // Si el móvil estuvo detenido mucho tiempo, se salta el atraso y se escriben solo los
        // últimos puntos: lo que importa es que aparezca donde le toca por reloj.
        $pendientes = (int) floor(($metrosPorReloj - $avance) / self::pasoM());
        if ($pendientes > self::MAX_PUNTOS_POR_CORRIDA) {
            $avance = $metrosPorReloj - (self::MAX_PUNTOS_POR_CORRIDA * self::pasoM());
        }

        while (($avance + self::pasoM()) <= $metrosPorReloj) {
            $avance += self::pasoM();
            $this->escribirPunto($simulacion, $conductor, $avance);
            $escritos++;
        }

        $simulacion->avanzada_hasta_m = $avance;

        if ($metrosPorReloj >= $simulacion->distancia_m) {
            // El último punto es el destino exacto, para que la geocerca del Panel y el resumen de
            // ruta cierren en el sitio correcto y no 30 metros antes.
            $this->escribirPunto($simulacion, $conductor, $simulacion->distancia_m);
            $escritos++;

            $simulacion->avanzada_hasta_m = $simulacion->distancia_m;
            $simulacion->terminada_en = now();
            $simulacion->save();

            $this->transicionarAlLlegar($pedido, $simulacion->tramo);

            return $escritos;
        }

        $simulacion->save();

        return $escritos;
    }

    /**
     * @param  array{lat: float, lng: float}  $origen
     * @param  array{lat: float, lng: float}  $destino
     */
    private function abrirTramo(Pedido $pedido, string $tramo, array $origen, array $destino): ?SimulacionEnvio
    {
        if (SimulacionEnvio::where('id_pedido', $pedido->id_pedido)->where('tramo', $tramo)->exists()) {
            return null;
        }

        $ruta = $this->rutas->calcular($origen, $destino);
        $distancia = (float) (end($ruta)['m'] ?? 0.0);

        return SimulacionEnvio::create([
            'id_pedido' => $pedido->id_pedido,
            'tramo' => $tramo,
            'ruta' => $ruta,
            'distancia_m' => $distancia,
            'iniciada_en' => now(),
            'avanzada_hasta_m' => 0,
        ]);
    }

    /**
     * La misma puerta que usa el GPS real (RN-10). `registrarPosicion()` guarda el punto, actualiza
     * `conductor_estado` y emite `UbicacionActualizada`, así que el Panel ve moverse el marcador
     * sin saber que detrás no hay ningún teléfono.
     */
    private function escribirPunto(SimulacionEnvio $simulacion, Conductor $conductor, float $metros): void
    {
        $punto = $this->interpolar($simulacion->ruta, $metros);

        $this->tracking->registrarPosicion($conductor, [
            'latitud' => $punto['lat'],
            'longitud' => $punto['lng'],
            'velocidad' => self::velocidadMs(),
        ]);
    }

    /**
     * Posición a `$metros` del arranque, interpolando linealmente entre los dos vértices que la
     * encierran. Esto es lo que hace que la velocidad sea constante de verdad y no dependa de
     * cuántos vértices trajo la ruta.
     *
     * @param  array<int, array{lat: float, lng: float, m: float}>  $ruta
     * @return array{lat: float, lng: float}
     */
    private function interpolar(array $ruta, float $metros): array
    {
        $ultimo = count($ruta) - 1;

        if ($ultimo < 1) {
            return ['lat' => (float) $ruta[0]['lat'], 'lng' => (float) $ruta[0]['lng']];
        }

        for ($i = 1; $i <= $ultimo; $i++) {
            if ((float) $ruta[$i]['m'] < $metros) {
                continue;
            }

            $desde = $ruta[$i - 1];
            $hasta = $ruta[$i];
            $tramo = (float) $hasta['m'] - (float) $desde['m'];
            $avance = $tramo > 0 ? ($metros - (float) $desde['m']) / $tramo : 0.0;

            return [
                'lat' => (float) $desde['lat'] + (((float) $hasta['lat'] - (float) $desde['lat']) * $avance),
                'lng' => (float) $desde['lng'] + (((float) $hasta['lng'] - (float) $desde['lng']) * $avance),
            ];
        }

        return ['lat' => (float) $ruta[$ultimo]['lat'], 'lng' => (float) $ruta[$ultimo]['lng']];
    }

    /**
     * RN-12: el hito de llegada que en LIVE dispara la geocerca del teléfono. Mismo trato que
     * `PublicarPedidosAgendados::publicar()` (spec tenant/018, RN-08): si el aviso falla, el estado
     * se persiste igual — dejar el envío clavado sería peor que perder el aviso.
     */
    private function transicionarAlLlegar(Pedido $pedido, string $tramo): void
    {
        $nuevoEstado = SimulacionEnvio::ESTADO_AL_LLEGAR[$tramo];

        if (! in_array($nuevoEstado, PedidoEstadoService::TRANSICIONES[$pedido->estado] ?? [], true)) {
            return;
        }

        try {
            app(PedidoEstadoService::class)->transicionar($pedido, $nuevoEstado);
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar del hito simulado', [
                'id_pedido' => $pedido->id_pedido,
                'estado' => $nuevoEstado,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($pedido->estado === $nuevoEstado) {
                $pedido->save();
            }
        }
    }

    /**
     * RN-08: si el conductor nunca reportó posición —se acaba de conectar—, el tramo de
     * acercamiento arranca en la recogida misma: dura un instante y `ARRIBADO` cae de inmediato.
     * Es preferible a inventarle un origen arbitrario.
     *
     * @return array{lat: float, lng: float}
     */
    private function posicionDelConductor(Pedido $pedido): array
    {
        // `estadoActual`, no `estado`: en `Conductor` esa columna es ACTIVO/INACTIVO; la posición
        // vive en la relación con `conductor_estado`.
        $estado = $pedido->conductor?->estadoActual;

        if ($estado?->ultima_latitud === null || $estado?->ultima_longitud === null) {
            return ['lat' => (float) $pedido->latitud_recogida, 'lng' => (float) $pedido->longitud_recogida];
        }

        return ['lat' => (float) $estado->ultima_latitud, 'lng' => (float) $estado->ultima_longitud];
    }
}
