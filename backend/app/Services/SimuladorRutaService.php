<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SimularSiguientePunto;
use App\Models\Tenant\Pedido;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Genera el recorrido simulado de un pedido `es_prueba` (spec "PWA agnóstica a LIVE/TEST"): calcula
 * la ruta real de recogida a entrega y arranca el generador de puntos (`SimularSiguientePunto`), que
 * es quien realmente va escribiendo la posición vía `TrackingService`. Igual que
 * `useConductorPrueba.ts` del Panel, solo se recorre el tramo recogida→entrega — no el
 * acercamiento del conductor hasta el punto de recogida.
 */
class SimuladorRutaService
{
    /** Cuántos puntos como máximo tiene el recorrido simulado, sin importar qué tan larga sea la ruta real. */
    private const MAX_PUNTOS = 40;

    /** Segundos entre cada punto simulado (limitado por el intervalo real del worker de colas, no por UX). */
    private const SEGUNDOS_ENTRE_PUNTOS = 2;

    public function iniciar(Pedido $pedido): void
    {
        $origen = ['lat' => (float) $pedido->latitud_recogida, 'lng' => (float) $pedido->longitud_recogida];
        $destino = ['lat' => (float) $pedido->latitud_entrega, 'lng' => (float) $pedido->longitud_entrega];

        $puntos = $this->calcularRuta($origen, $destino);

        SimularSiguientePunto::dispatch(tenant(), $pedido->id_pedido, $puntos, 0, self::SEGUNDOS_ENTRE_PUNTOS);
    }

    /**
     * @return array<int, array{lat: float, lng: float}>
     */
    private function calcularRuta(array $origen, array $destino): array
    {
        try {
            $respuesta = Http::get('https://maps.googleapis.com/maps/api/directions/json', [
                'origin' => "{$origen['lat']},{$origen['lng']}",
                'destination' => "{$destino['lat']},{$destino['lng']}",
                'mode' => 'driving',
                'key' => config('services.google_maps.key'),
            ]);

            $polyline = $respuesta->json('routes.0.overview_polyline.points');

            if (is_string($polyline) && $polyline !== '') {
                return $this->muestrear($this->decodificarPolyline($polyline));
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo calcular la ruta simulada, se usa línea recta', ['error' => $e->getMessage()]);
        }

        // Sin ruta real disponible (sin API key, sin red, o Directions no encontró camino): línea
        // recta entre recogida y entrega, igual que el fallback de `useMapController.js`.
        return $this->muestrear([$origen, $destino]);
    }

    /**
     * Muestreo uniforme a un máximo de `MAX_PUNTOS`, siempre conservando el primero y el último —
     * mismo criterio que `TrackingService::simplificar()`, para que la duración del recorrido
     * simulado no dependa de qué tan larga sea la ruta real.
     *
     * @param  array<int, array{lat: float, lng: float}>  $puntos
     * @return array<int, array{lat: float, lng: float}>
     */
    private function muestrear(array $puntos): array
    {
        $total = count($puntos);

        if ($total <= self::MAX_PUNTOS) {
            return $puntos;
        }

        $paso = ($total - 1) / (self::MAX_PUNTOS - 1);
        $resultado = [];

        for ($i = 0; $i < self::MAX_PUNTOS; $i++) {
            $resultado[] = $puntos[(int) round($i * $paso)];
        }

        return $resultado;
    }

    /**
     * Decodifica el "encoded polyline" que devuelve Google Directions (algoritmo estándar de
     * Google: https://developers.google.com/maps/documentation/utilities/polylinealgorithm).
     *
     * @return array<int, array{lat: float, lng: float}>
     */
    private function decodificarPolyline(string $codificado): array
    {
        $puntos = [];
        $index = 0;
        $len = strlen($codificado);
        $lat = 0;
        $lng = 0;

        while ($index < $len) {
            $lat += $this->decodificarValor($codificado, $index);
            $lng += $this->decodificarValor($codificado, $index);

            $puntos[] = ['lat' => $lat / 1e5, 'lng' => $lng / 1e5];
        }

        return $puntos;
    }

    private function decodificarValor(string $codificado, int &$index): int
    {
        $shift = 0;
        $resultado = 0;

        do {
            $byte = ord($codificado[$index++]) - 63;
            $resultado |= ($byte & 0x1F) << $shift;
            $shift += 5;
        } while ($byte >= 0x20);

        return ($resultado & 1) ? ~($resultado >> 1) : ($resultado >> 1);
    }
}
