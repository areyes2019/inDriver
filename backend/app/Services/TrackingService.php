<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Tenant\UbicacionActualizada;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConductorPosicion;
use App\Models\Tenant\Pedido;
use Illuminate\Support\Collection;
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
     * Flujo normal, en vivo (RN-04): guarda el punto y actualiza la posición "actual" del
     * conductor en la misma operación, y difunde al Panel.
     *
     * @param  array<string, mixed>  $datos
     * @param  bool  $desdeSimulador  spec "PWA agnóstica a LIVE/TEST": true solo cuando llama
     *                                `SimularSiguientePunto` (el generador de posición del modo
     *                                TEST). panda_express nunca sabe que existe este parámetro —
     *                                siempre manda su GPS real y siempre recibe 204. Si el pedido
     *                                es `es_prueba` y quien llama NO es el simulador, el dato del
     *                                teléfono se descarta en silencio: la posición real no debe
     *                                pisar la que ya está generando el backend.
     *
     * @throws ValidationException con `DELIVERY_NOT_ACTIVE` si el conductor no tiene un envío en
     *                             curso ahora mismo (RN-01): estar en línea sin envío no genera
     *                             tracking.
     */
    public function registrarPosicion(Conductor $conductor, array $datos, bool $desdeSimulador = false): ?ConductorPosicion
    {
        $pedido = $this->pedidoActivo($conductor);

        if (! $pedido) {
            throw ValidationException::withMessages([
                'pedido' => ['DELIVERY_NOT_ACTIVE'],
            ]);
        }

        if ($pedido->es_prueba && ! $desdeSimulador) {
            return null;
        }

        $this->actualizarPosicionActual($conductor, (float) $datos['latitud'], (float) $datos['longitud']);

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

    private function pedidoActivo(Conductor $conductor): ?Pedido
    {
        return $conductor->pedidos()->whereNotIn('estado', PedidoEstadoService::ESTADOS_FINALES)->first();
    }

    private function actualizarPosicionActual(Conductor $conductor, float $latitud, float $longitud): void
    {
        ConductorEstado::updateOrCreate(
            ['id_conductor' => $conductor->id_conductor],
            ['ultima_latitud' => $latitud, 'ultima_longitud' => $longitud, 'ultima_actualizacion' => now()],
        );
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
