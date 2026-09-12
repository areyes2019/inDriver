<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Tenant\UbicacionActualizada;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConductorPosicion;
use App\Models\Tenant\Pedido;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Posición del conductor durante un envío activo (spec tenant/021, SPEC-021). Único lugar que
 * escribe en `conductor_posiciones` y que calcula el resumen del recorrido al entregar.
 */
class TrackingService
{
    /** RN-05: más de esto en un lote se rechaza (BATCH_TOO_LARGE). */
    public const MAX_LOTE = 200;

    /** RN-08: tamaño máximo de `resumen_ruta`. */
    public const MAX_PUNTOS_RESUMEN = 50;

    /** Retención de `conductor_posiciones` después de concluido el envío. */
    public const RETENCION_DIAS = 7;

    /**
     * RN-03 (spec tenant/021): una lectura que implica más velocidad que esto desde la posición
     * anterior es GPS implausible —geolocalización por red/IP en vez de GPS real, típico al
     * probar en una máquina remota sin señal de GPS— y se descarta.
     */
    public const MAX_VELOCIDAD_KMH = 150.0;

    /**
     * RN-03b (spec tenant/021): no se rechazan más de estas lecturas seguidas contra la misma
     * línea base. Pasado el tope, la sospechosa es la base, no las lecturas.
     */
    public const MAX_RECHAZOS_CONSECUTIVOS = 3;

    /**
     * Flujo normal, en vivo (RN-04): guarda el punto y actualiza la posición "actual" del
     * conductor en la misma operación, y difunde al Panel.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException con `DELIVERY_NOT_ACTIVE` si el conductor no tiene un envío en
     *                             curso ahora mismo (RN-01): estar en línea sin envío no genera
     *                             tracking.
     */
    public function registrarPosicion(Conductor $conductor, array $datos): ?ConductorPosicion
    {
        $pedido = $this->pedidoActivo($conductor);

        if (! $pedido) {
            throw ValidationException::withMessages([
                'pedido' => ['DELIVERY_NOT_ACTIVE'],
            ]);
        }

        // Se descarta en silencio, igual que un LOCATION_UPDATE inválido por socket (spec
        // tenant/021, sección de errores): no hay a quién devolverle el error, y reintentar sería
        // peor que perder un punto.
        if (! $this->filtrarPosicionEnVivo($conductor, (float) $datos['latitud'], (float) $datos['longitud'])) {
            return null;
        }

        $posicion = ConductorPosicion::create([
            'id_conductor' => $conductor->id_conductor,
            'id_pedido' => $pedido->id_pedido,
            'latitud' => $datos['latitud'],
            'longitud' => $datos['longitud'],
            'precision' => $datos['precision'] ?? null,
            'velocidad' => $datos['velocidad'] ?? null,
            'rumbo' => $datos['rumbo'] ?? null,
            'bateria' => $datos['bateria'] ?? null,
            'fecha_posicion' => now(),
        ]);

        if ($slug = tenant()?->slug) {
            UbicacionActualizada::dispatch($conductor->id_conductor, (float) $datos['latitud'], (float) $datos['longitud'], $slug);
        }

        return $posicion;
    }

    /**
     * El conductor está en línea pero sin envío en curso.
     *
     * RN-01 sigue valiendo tal cual: **no** se guarda historia en `conductor_posiciones` ni se
     * difunde nada al Panel —estar en línea no genera tracking—. Lo único que se conserva es
     * dónde quedó parado, que es otra cosa: sin ello su posición no existe hasta que el primer
     * envío escribe una, y en TEST no existe **nunca**, porque ahí el GPS real se descarta
     * siempre (spec tenant/025, RN-11). Ese hueco es lo que dejaba al simulador sin origen: el
     * tramo de acercamiento arrancaba en el punto de recogida mismo (RN-08), medía cero metros y
     * el conductor aparecía clavado ahí sin recorrer nada.
     *
     * Se escribe con `update()` y solo sobre un conductor ONLINE: si la fila no existe —nunca se
     * conectó— no se inventa ninguna, igual que en `registrarLatido()`.
     */
    public function registrarPosicionSinEnvio(Conductor $conductor, float $latitud, float $longitud): void
    {
        ConductorEstado::where('id_conductor', $conductor->id_conductor)
            ->where('estado', 'ONLINE')
            ->update([
                'ultima_latitud' => $latitud,
                'ultima_longitud' => $longitud,
                'ultima_actualizacion' => now(),
                // Esta vía no pasa por RN-03 y reemplaza la base entera: una racha de rechazos
                // contra la base anterior ya no dice nada de la nueva (RN-03b).
                'rechazos_consecutivos' => 0,
            ]);
    }

    /**
     * Respaldo por lotes tras reconectar (RN-05): se guardan como historia, sin difundir al Panel
     * (RN-07) — ya no son la posición actual, son puntos que ya pasaron.
     *
     * @param  array<int, array<string, mixed>>  $puntos
     */
    public function registrarLote(Conductor $conductor, Pedido $pedido, array $puntos): void
    {
        $ahora = now();

        $filas = array_map(fn (array $punto) => [
            'id_conductor' => $conductor->id_conductor,
            'id_pedido' => $pedido->id_pedido,
            'latitud' => $punto['latitud'],
            'longitud' => $punto['longitud'],
            'precision' => $punto['precision'] ?? null,
            'velocidad' => $punto['velocidad'] ?? null,
            'rumbo' => $punto['rumbo'] ?? null,
            'bateria' => $punto['bateria'] ?? null,
            'fecha_posicion' => $punto['fecha_posicion'],
            'created_at' => $ahora,
        ], $puntos);

        ConductorPosicion::insert($filas);

        $ultimo = end($puntos);
        $this->actualizarPosicionActual($conductor, (float) $ultimo['latitud'], (float) $ultimo['longitud']);
    }

    /**
     * Al entregar (RN-08): distancia total recorrida y una versión simplificada del recorrido
     * (máximo 50 puntos) para que sobreviva a la purga de `conductor_posiciones`. No llama a
     * `save()` — igual que el resto de `PedidoEstadoService::transicionar()`, el llamador decide
     * cuándo persistir.
     */
    public function calcularResumenRuta(Pedido $pedido): void
    {
        $puntos = ConductorPosicion::where('id_pedido', $pedido->id_pedido)
            ->orderBy('fecha_posicion')
            ->get(['latitud', 'longitud', 'fecha_posicion']);

        if ($puntos->isEmpty()) {
            return;
        }

        $distanciaKm = 0.0;
        for ($i = 1; $i < $puntos->count(); $i++) {
            $distanciaKm += $this->haversineKm($puntos[$i - 1], $puntos[$i]);
        }

        $pedido->distancia_recorrida_km = round($distanciaKm, 2);
        $pedido->resumen_ruta = $this->simplificar($puntos);
    }

    /**
     * El conductor sigue vivo aunque su posición no se guarde (spec tenant/025, RN-11).
     *
     * En TEST el GPS real se descarta, pero el teléfono sigue reportando: descartar también la
     * señal de vida haría que `conductor:apagar-inactivos` lo apagara a los 10 minutos en mitad de
     * un envío simulado. Lo único que no se toca es la posición, que la manda el simulador.
     */
    public function registrarLatido(Conductor $conductor): void
    {
        ConductorEstado::where('id_conductor', $conductor->id_conductor)
            ->update(['ultima_actualizacion' => now()]);
    }

    /**
     * Público desde la spec tenant/025: `Conductor\UbicacionController` necesita preguntar si el
     * envío en curso es TEST para descartar el GPS real (RN-11), y duplicar esta consulta allá
     * sería peor.
     */
    public function pedidoActivo(Conductor $conductor): ?Pedido
    {
        return $conductor->pedidos()->whereNotIn('estado', PedidoEstadoService::ESTADOS_FINALES)->first();
    }

    /**
     * Aplica RN-03 y, si la posición es plausible, la deja como la "actual" del conductor. Punto
     * de entrada compartido por el camino HTTP directo (`registrarPosicion`) y por el worker que
     * aplica lo que reenvía el microservicio GPS (`ConsumirBuzonGps::difundir`, spec tenant/028):
     * el filtro no puede depender de por cuál de los dos caminos entró el punto.
     *
     * RN-03b: RN-03 compara siempre contra la posición anterior, así que una base equivocada se
     * defiende sola —rechaza por "salto imposible" justo las lecturas buenas que la contradicen— y
     * el conductor queda clavado ahí hasta que pasen las horas que la velocidad implícita necesita
     * para volverse plausible. Pasó en producción: una lectura de geolocalización por red/IP entró
     * como base y dejó el mapa congelado el resto del envío. Por eso, tras
     * `MAX_RECHAZOS_CONSECUTIVOS` rechazos seguidos, gana la mayoría: varias lecturas que coinciden
     * en contradecir a la base dicen más que la base sola.
     */
    public function filtrarPosicionEnVivo(Conductor $conductor, float $latitud, float $longitud): bool
    {
        if ($this->esSaltoImposible($conductor, $latitud, $longitud)) {
            $rechazos = $this->contarRechazo($conductor);
            $baseEnvenenada = $rechazos > self::MAX_RECHAZOS_CONSECUTIVOS;

            // Se registra siempre, por los dos caminos de entrada: una coordenada inválida que se
            // descarta en silencio es indistinguible de un GPS apagado cuando hay que diagnosticar.
            Log::warning($baseEnvenenada
                ? 'RN-03b: la posición anterior se da por envenenada tras rechazos seguidos; se adopta la lectura nueva como base.'
                : 'RN-03: posición descartada por salto implausible desde la posición anterior.', [
                    'id_conductor' => $conductor->id_conductor,
                    'tenant' => tenant()?->slug,
                    'latitud' => $latitud,
                    'longitud' => $longitud,
                    'rechazos_consecutivos' => $rechazos,
                ]);

            if (! $baseEnvenenada) {
                return false;
            }
        }

        $this->actualizarPosicionActual($conductor, $latitud, $longitud);

        return true;
    }

    /**
     * RN-03b: no mueve `ultima_actualizacion`. Ese sello es el denominador de la velocidad
     * implícita, y refrescarlo en cada rechazo volvería a hacer "reciente" una base que ya se
     * sospecha mala, dejando el salto igual de imposible para siempre.
     *
     * Suma en SQL y relee: el mismo conductor puede venir por los dos caminos de entrada a la vez,
     * y `$conductor->estadoActual` es una copia de cuando se cargó el modelo.
     */
    private function contarRechazo(Conductor $conductor): int
    {
        ConductorEstado::where('id_conductor', $conductor->id_conductor)->increment('rechazos_consecutivos');

        return (int) ConductorEstado::where('id_conductor', $conductor->id_conductor)->value('rechazos_consecutivos');
    }

    private function actualizarPosicionActual(Conductor $conductor, float $latitud, float $longitud): void
    {
        ConductorEstado::updateOrCreate(
            ['id_conductor' => $conductor->id_conductor],
            ['ultima_latitud' => $latitud, 'ultima_longitud' => $longitud, 'ultima_actualizacion' => now(), 'rechazos_consecutivos' => 0],
        );
    }

    /**
     * RN-03 (spec tenant/021): sin posición previa no hay nada contra qué comparar —se acepta,
     * igual que hace la App—. Con posición previa, la velocidad implícita entre ambas no puede
     * superar `MAX_VELOCIDAD_KMH`; dos lecturas casi simultáneas mandan la distancia derecho al
     * numerador (un segundo mínimo evita dividir entre cero sin abrir la puerta a un salto de
     * cientos de kilómetros "instantáneo").
     */
    private function esSaltoImposible(Conductor $conductor, float $latitud, float $longitud): bool
    {
        $estado = $conductor->estadoActual;

        if ($estado === null || $estado->ultima_latitud === null || $estado->ultima_longitud === null || $estado->ultima_actualizacion === null) {
            return false;
        }

        $segundos = max(1, $estado->ultima_actualizacion->diffInSeconds(now(), true));

        $distanciaKm = $this->haversineKm(
            (object) ['latitud' => $estado->ultima_latitud, 'longitud' => $estado->ultima_longitud],
            (object) ['latitud' => $latitud, 'longitud' => $longitud],
        );

        return ($distanciaKm / ($segundos / 3600)) > self::MAX_VELOCIDAD_KMH;
    }

    private function haversineKm(object $a, object $b): float
    {
        $radioTierraKm = 6371.0;
        $lat1 = deg2rad((float) $a->latitud);
        $lat2 = deg2rad((float) $b->latitud);
        $deltaLat = deg2rad((float) $b->latitud - (float) $a->latitud);
        $deltaLng = deg2rad((float) $b->longitud - (float) $a->longitud);

        $formula = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        return $radioTierraKm * 2 * atan2(sqrt($formula), sqrt(1 - $formula));
    }

    /**
     * Muestreo uniforme: conserva el primero y el último, y reparte el resto a distancia pareja
     * en vez de quedarse solo con el arranque del recorrido.
     *
     * @return array<int, array<string, mixed>>
     */
    private function simplificar(Collection $puntos): array
    {
        $total = $puntos->count();

        if ($total <= self::MAX_PUNTOS_RESUMEN) {
            return $puntos->map(fn ($p) => $this->puntoResumen($p))->all();
        }

        $paso = ($total - 1) / (self::MAX_PUNTOS_RESUMEN - 1);
        $resultado = [];

        for ($i = 0; $i < self::MAX_PUNTOS_RESUMEN; $i++) {
            $resultado[] = $this->puntoResumen($puntos[(int) round($i * $paso)]);
        }

        return $resultado;
    }

    /**
     * @return array<string, mixed>
     */
    private function puntoResumen(object $punto): array
    {
        return [
            'lat' => (float) $punto->latitud,
            'lng' => (float) $punto->longitud,
            'fecha' => $punto->fecha_posicion->toIso8601String(),
        ];
    }
}
