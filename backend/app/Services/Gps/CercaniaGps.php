<?php

declare(strict_types=1);

namespace App\Services\Gps;

use App\Models\Tenant\ConductorEstado;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "¿Qué conductores hay cerca de este punto?" (spec tenant/028, §7.6).
 *
 * El servicio GPS responde geografía y nada más: quién es elegible, quién tiene saldo y a quién se
 * le asigna el pedido lo decide Laravel encima de esta lista (RN-16).
 */
class CercaniaGps
{
    /** RN-06: una posición más vieja que esto ya no cuenta como "en vivo". */
    public const FRESCURA_MINUTOS = 3;

    public function __construct(private readonly BuzonGps $buzon) {}

    /**
     * Nunca lanza: si el servicio no contesta se cae al respaldo sobre `conductor_estados`
     * (RN-18). La operación no puede detenerse porque un servicio auxiliar esté caído.
     *
     * @return array<int, array{id_conductor: int, distancia_km: float}>
     */
    public function buscar(float $latitud, float $longitud, float $radioKm = 3.0, int $limite = 10): array
    {
        $slug = tenant()?->slug;

        if (! $this->buzon->habilitado() || $slug === null) {
            return $this->respaldo($latitud, $longitud, $radioKm, $limite);
        }

        try {
            $respuesta = Http::withToken((string) config('gps.token_servicio'))
                ->withHeaders(['X-Tenant' => $slug])
                ->timeout((float) config('gps.timeout'))
                ->get(config('gps.url').'/gps/v1/nearby', [
                    'lat' => $latitud,
                    'lng' => $longitud,
                    'radio_km' => $radioKm,
                    'limite' => $limite,
                ]);

            if ($respuesta->failed()) {
                throw new \RuntimeException('el servicio GPS respondió '.$respuesta->status());
            }

            return array_map(fn (array $fila) => [
                'id_conductor' => (int) $fila['id_conductor'],
                'distancia_km' => round((float) $fila['distancia_km'], 3),
            ], $respuesta->json('conductores', []));
        } catch (\Throwable $e) {
            Log::warning('El servicio GPS no respondió; se usa la posición guardada.', [
                'tenant' => $slug,
                'error' => $e->getMessage(),
            ]);

            return $this->respaldo($latitud, $longitud, $radioKm, $limite);
        }
    }

    /**
     * El comportamiento de siempre: la última posición conocida en `conductor_estados`, filtrada
     * por la misma frescura que aplica el servicio para no devolver un conductor que ya no manda
     * señales.
     *
     * La distancia se calcula en SQL (fórmula del semiverseno) para no traerse la flota entera a
     * PHP y descartarla aquí.
     *
     * @return array<int, array{id_conductor: int, distancia_km: float}>
     */
    private function respaldo(float $latitud, float $longitud, float $radioKm, int $limite): array
    {
        $distancia = '(6371 * acos(least(1, greatest(-1,
            cos(radians(?)) * cos(radians(ultima_latitud)) * cos(radians(ultima_longitud) - radians(?))
            + sin(radians(?)) * sin(radians(ultima_latitud))
        ))))';

        return ConductorEstado::query()
            ->select('id_conductor')
            ->selectRaw("{$distancia} as distancia_km", [$latitud, $longitud, $latitud])
            ->where('estado', 'ONLINE')
            ->whereNotNull('ultima_latitud')
            ->whereNotNull('ultima_longitud')
            ->where('ultima_actualizacion', '>=', now()->subMinutes(self::FRESCURA_MINUTOS))
            ->havingRaw('distancia_km <= ?', [$radioKm])
            ->orderBy('distancia_km')
            ->limit($limite)
            ->get()
            ->map(fn (ConductorEstado $estado) => [
                'id_conductor' => (int) $estado->id_conductor,
                'distancia_km' => round((float) $estado->distancia_km, 3),
            ])
            ->all();
    }
}
