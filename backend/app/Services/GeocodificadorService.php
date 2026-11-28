<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Ciudad;
use App\Models\Tenant\ZonaServicio;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Convierte una dirección escrita en coordenadas, en el servidor (spec tenant/031, RN-16).
 *
 * Solo acepta resultados precisos: una dirección que Google ubica en el centro de una colonia o de
 * una ciudad no sirve para recoger un paquete, y cobrar con ella sería adivinar.
 */
class GeocodificadorService
{
    /** `GEOMETRIC_CENTER` y `APPROXIMATE` son el centro de un área, no un domicilio. */
    private const PRECISOS = ['ROOFTOP', 'RANGE_INTERPOLATED'];

    /**
     * Entre los resultados precisos se prefiere el que cae en la cobertura: `bounds` solo sesga a
     * Google, y "Adolfo López Mateos 125, Centro" existe en decenas de ciudades. Si ninguno cae
     * dentro, se vuelve a buscar con el nombre de la ciudad de la flotilla.
     *
     * @return array{lat: float, lng: float, direccion: string}|null `null` si no hay un resultado preciso.
     */
    public function geocodificar(string $direccion): ?array
    {
        $llave = config('services.google_maps.key');

        if (! is_string($llave) || $llave === '') {
            Log::warning('No se puede geocodificar: falta la llave de Google Maps');

            return null;
        }

        $candidatos = $this->buscar($direccion, $llave);
        $elegido = $this->primeroEnCobertura($candidatos);

        if ($elegido === null && ($conCiudad = $this->conCiudad($direccion)) !== null) {
            $elegido = $this->primeroEnCobertura($this->buscar($conCiudad, $llave));
        }

        // Ninguno en la cobertura: se devuelve el primero para que quien llama diga que no llegamos.
        return $elegido ?? $candidatos[0] ?? null;
    }

    /**
     * @return list<array{lat: float, lng: float, direccion: string}> Solo los resultados precisos, en el orden de Google.
     */
    private function buscar(string $direccion, string $llave): array
    {
        $parametros = [
            'address' => $direccion,
            'components' => 'country:MX',
            'language' => 'es',
            'key' => $llave,
        ];

        $limites = $this->limitesDeOperacion();

        if ($limites !== null) {
            $parametros['bounds'] = "{$limites['south']},{$limites['west']}|{$limites['north']},{$limites['east']}";
        }

        try {
            $respuesta = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/geocode/json', $parametros);
        } catch (\Throwable $e) {
            Log::warning('No se pudo geocodificar', ['error' => $e->getMessage()]);

            return [];
        }

        $estado = $respuesta->json('status');

        if ($estado !== 'OK' && $estado !== 'ZERO_RESULTS') {
            // REQUEST_DENIED suele ser la Geocoding API sin habilitar en la llave.
            Log::warning('Geocoding no respondió OK', ['status' => $estado, 'error' => $respuesta->json('error_message')]);

            return [];
        }

        $precisos = [];

        foreach ((array) $respuesta->json('results', []) as $resultado) {
            if (! is_array($resultado) || ! in_array($resultado['geometry']['location_type'] ?? null, self::PRECISOS, true)) {
                continue;
            }

            $precisos[] = [
                'lat' => (float) $resultado['geometry']['location']['lat'],
                'lng' => (float) $resultado['geometry']['location']['lng'],
                'direccion' => (string) ($resultado['formatted_address'] ?? $direccion),
            ];
        }

        return $precisos;
    }

    /**
     * @param  list<array{lat: float, lng: float, direccion: string}>  $candidatos
     * @return array{lat: float, lng: float, direccion: string}|null
     */
    private function primeroEnCobertura(array $candidatos): ?array
    {
        foreach ($candidatos as $candidato) {
            if (ZonaServicio::cubrePunto($candidato['lat'], $candidato['lng'])) {
                return $candidato;
            }
        }

        return null;
    }

    /**
     * La dirección con la ciudad de la flotilla al final, o `null` si no hay ciudad o ya la trae.
     */
    private function conCiudad(string $direccion): ?string
    {
        $nombre = Ciudad::query()->orderBy('id_ciudad')->value('nombre');

        if (! is_string($nombre) || trim($nombre) === '') {
            return null;
        }

        $ciudad = trim(explode(',', $nombre)[0]);
        $normalizar = fn (string $texto): string => Str::lower(Str::ascii($texto));

        if ($ciudad === '' || str_contains($normalizar($direccion), $normalizar($ciudad))) {
            return null;
        }

        return "{$direccion}, {$nombre}";
    }

    /**
     * La dirección más cercana a una ubicación compartida (RN-41), solo para mostrarla. Las
     * coordenadas ya son exactas, así que si Google no responde se describe como ubicación.
     */
    public function direccionDe(float $lat, float $lng): string
    {
        $llave = config('services.google_maps.key');
        $respaldo = 'la ubicación que compartiste';

        if (! is_string($llave) || $llave === '') {
            return $respaldo;
        }

        try {
            $respuesta = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'latlng' => "{$lat},{$lng}",
                'language' => 'es',
                'key' => $llave,
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo geocodificar una ubicación', ['error' => $e->getMessage()]);

            return $respaldo;
        }

        $direccion = $respuesta->json('results.0.formatted_address');

        return $respuesta->json('status') === 'OK' && is_string($direccion) && $direccion !== '' ? $direccion : $respaldo;
    }

    /**
     * La cobertura si la flotilla la dibujó; si no, el área de su primera ciudad.
     *
     * @return array{north: float, south: float, east: float, west: float}|null
     */
    private function limitesDeOperacion(): ?array
    {
        $zonas = ZonaServicio::boundsDeZonasActivas();

        if ($zonas !== null) {
            return $zonas;
        }

        $bounds = Ciudad::query()->whereNotNull('bounds')->value('bounds');
        $bounds = is_string($bounds) ? json_decode($bounds, true) : $bounds;

        if (is_array($bounds) && isset($bounds['north'], $bounds['south'], $bounds['east'], $bounds['west'])) {
            return array_map('floatval', $bounds);
        }

        return null;
    }
}
