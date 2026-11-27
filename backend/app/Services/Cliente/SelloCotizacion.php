<?php

declare(strict_types=1);

namespace App\Services\Cliente;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * El precio que vio el cliente, sellado por el servidor (spec tenant/035, RN-23 y RN-24).
 *
 * Va cifrado y firmado con la `APP_KEY`: el teléfono lo guarda y lo devuelve al confirmar, pero no
 * puede leerlo ni cambiarlo. Al confirmar (Fase 5) se compara contra los datos del pedido: si algo
 * no coincide, si es de otro cliente o de otra flotilla, o si ya venció, no sirve.
 */
class SelloCotizacion
{
    public const VIGENCIA_MINUTOS = 10;

    private const VERSION = 1;

    /**
     * @param  array<string, mixed>  $condiciones  Lo que define el precio (ver `condiciones()`).
     */
    public function sellar(int $idCliente, string $slug, array $condiciones, float $km, float $importeEnvio): string
    {
        return Crypt::encryptString((string) json_encode([
            'v' => self::VERSION,
            'cliente' => $idCliente,
            'flotilla' => $slug,
            'condiciones' => $condiciones,
            'km' => $km,
            'importe_envio' => $importeEnvio,
            'vence' => now()->addMinutes(self::VIGENCIA_MINUTOS)->getTimestamp(),
        ]));
    }

    /**
     * El contenido del sello si es auténtico, del cliente y de la flotilla, y sigue vigente. `null` si
     * no; `$motivo` dice por qué.
     *
     * @return array{km: float, importe_envio: float, condiciones: array<string, mixed>}|null
     */
    public function abrir(string $sello, int $idCliente, string $slug, ?string &$motivo = null): ?array
    {
        try {
            $datos = json_decode(Crypt::decryptString($sello), true);
        } catch (DecryptException) {
            $motivo = 'ALTERADO';

            return null;
        }

        if (! is_array($datos) || ($datos['v'] ?? null) !== self::VERSION) {
            $motivo = 'ALTERADO';

            return null;
        }

        if ($datos['cliente'] !== $idCliente || $datos['flotilla'] !== $slug) {
            $motivo = 'AJENO';

            return null;
        }

        if ($datos['vence'] < now()->getTimestamp()) {
            $motivo = 'VENCIDO';

            return null;
        }

        return [
            'km' => (float) $datos['km'],
            'importe_envio' => (float) $datos['importe_envio'],
            'condiciones' => $datos['condiciones'],
        ];
    }

    /**
     * Lo que define el precio y el cobro, normalizado para compararlo igual al sellar y al confirmar.
     * Coordenadas como texto con 6 decimales (unos 10 cm) e importes en centavos enteros: así salen
     * idénticos del JSON del sello (un `0.0` volvería como `0` y la comparación fallaría).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function condiciones(array $datos): array
    {
        $asap = (bool) ($datos['lo_antes_posible'] ?? true);
        $coordenada = fn ($valor) => number_format((float) $valor, 6, '.', '');

        return [
            'tipo' => (string) $datos['tipo'],
            'recogida' => [$coordenada($datos['recogida']['lat']), $coordenada($datos['recogida']['lng'])],
            'entrega' => [$coordenada($datos['entrega']['lat']), $coordenada($datos['entrega']['lng'])],
            'modalidad' => (string) $datos['modalidad'],
            'producto_centavos' => (int) round((float) ($datos['importe_producto'] ?? 0) * 100),
            'lo_antes_posible' => $asap,
            'fecha' => $asap ? null : (string) $datos['fecha'],
            'hora' => $asap ? null : (string) $datos['hora'],
        ];
    }
}
