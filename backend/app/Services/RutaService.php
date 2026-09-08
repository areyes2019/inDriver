<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Traduce "de aquí a allá" en una ruta real de calles (spec tenant/025).
 *
 * Devuelve la polilínea ya decodificada y con la **distancia acumulada** en cada vértice
 * (`{lat, lng, m}`). Ese acumulado es lo que permite a `SimulacionEnvioService` interpolar la
 * posición por metros y sostener 60 km/h de verdad, independientemente de qué tan larga sea la
 * ruta. El simulador anterior muestreaba a 40 puntos fijos y por eso un viaje de 1 km y uno de
 * 15 km tardaban lo mismo.
 *
 * El llamador guarda el resultado, así que cada tramo se le pregunta a Google una sola vez.
 */
class RutaService
{
    private const RADIO_TIERRA_M = 6371000.0;

    /**
     * @param  array{lat: float, lng: float}  $origen
     * @param  array{lat: float, lng: float}  $destino
     * @return array<int, array{lat: float, lng: float, m: float}>
     */
    public function calcular(array $origen, array $destino): array
    {
        return $this->acumular($this->puntos($origen, $destino));
    }

    /**
     * @param  array{lat: float, lng: float}  $origen
     * @param  array{lat: float, lng: float}  $destino
     * @return array<int, array{lat: float, lng: float}>
     */
    private function puntos(array $origen, array $destino): array
    {
        $llave = config('services.google_maps.key');

        if (! is_string($llave) || $llave === '') {
            return [$origen, $destino];
        }

        try {
            $respuesta = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/directions/json', [
                'origin' => "{$origen['lat']},{$origen['lng']}",
                'destination' => "{$destino['lat']},{$destino['lng']}",
                'mode' => 'driving',
                'key' => $llave,
            ]);

            $polilinea = $respuesta->json('routes.0.overview_polyline.points');

            if (is_string($polilinea) && $polilinea !== '') {
                $decodificada = $this->decodificar($polilinea);

                if (count($decodificada) >= 2) {
                    return $decodificada;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo calcular la ruta simulada, se usa línea recta', [
                'error' => $e->getMessage(),
            ]);

            return [$origen, $destino];
        }

        // Respondió, pero sin ruta utilizable: sin API key válida, cuota agotada, o Directions no
        // encontró camino entre los dos puntos. Mismo criterio que el respaldo de
        // `useMapController.js`: línea recta antes que dejar el viaje de prueba muerto (RN-15).
        Log::warning('Directions no devolvió una ruta utilizable, se usa línea recta', [
            'origen' => $origen,
            'destino' => $destino,
        ]);

        return [$origen, $destino];
    }

    /**
     * Anota en cada vértice cuántos metros van recorridos desde el arranque.
     *
     * @param  array<int, array{lat: float, lng: float}>  $puntos
     * @return array<int, array{lat: float, lng: float, m: float}>
     */
    private function acumular(array $puntos): array
    {
        $resultado = [];
        $acumulado = 0.0;

        foreach (array_values($puntos) as $i => $punto) {
            if ($i > 0) {
                $acumulado += $this->haversineM($puntos[$i - 1], $punto);
            }

            $resultado[] = [
                'lat' => (float) $punto['lat'],
                'lng' => (float) $punto['lng'],
                'm' => round($acumulado, 2),
            ];
        }

        return $resultado;
    }

    /**
     * @param  array{lat: float, lng: float}  $a
     * @param  array{lat: float, lng: float}  $b
     */
    private function haversineM(array $a, array $b): float
    {
        $lat1 = deg2rad((float) $a['lat']);
        $lat2 = deg2rad((float) $b['lat']);
        $deltaLat = deg2rad((float) $b['lat'] - (float) $a['lat']);
        $deltaLng = deg2rad((float) $b['lng'] - (float) $a['lng']);

        $formula = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        return self::RADIO_TIERRA_M * 2 * atan2(sqrt($formula), sqrt(1 - $formula));
    }

    /**
     * "Encoded polyline" de Google Directions (algoritmo estándar:
     * https://developers.google.com/maps/documentation/utilities/polylinealgorithm).
     *
     * @return array<int, array{lat: float, lng: float}>
     */
    private function decodificar(string $codificado): array
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
