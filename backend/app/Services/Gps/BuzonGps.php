<?php

declare(strict_types=1);

namespace App\Services\Gps;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * El buzón entre Laravel y el microservicio GPS (spec tenant/028, §6.3 y §6.4): dos streams de
 * Redis, uno en cada sentido.
 *
 * Son streams y no Pub/Sub (RN-17). Con Pub/Sub, reiniciar el servicio o el worker perdería los
 * avisos emitidos mientras nadie escuchaba, y perder un `ENVIO_INICIADO` significa un conductor
 * rodando sin que se le guarde el recorrido.
 *
 * Publicar nunca puede tumbar la operación de negocio que lo provocó: todo va envuelto en
 * `try/catch`, igual que los avisos a Reverb (spec tenant/018, RN-08).
 */
class BuzonGps
{
    /** Avisos de Laravel al servicio. */
    public const ENVIO_INICIADO = 'ENVIO_INICIADO';

    public const ENVIO_TERMINADO = 'ENVIO_TERMINADO';

    public const PERMISO_CANCELADO = 'PERMISO_CANCELADO';

    /** Avisos del servicio a Laravel. */
    public const POSICION = 'POSICION';

    public const RECORRIDO = 'RECORRIDO';

    public const LATIDO = 'LATIDO';

    /** Tope aproximado del stream de salida: un buzón sin poda crece hasta llenar la memoria. */
    public const MAX_MENSAJES = 100_000;

    /**
     * Sin URL del servicio la integración está apagada y todo esto es una función vacía. Es lo que
     * permite que el paquete de Hostinger y el entorno de pruebas sigan como si nada (§14).
     */
    public function habilitado(): bool
    {
        return config('gps.url') !== '' && config('gps.secreto') !== null;
    }

    public function envioIniciado(string $tenant, int $idConductor, int $idPedido): void
    {
        $this->publicar(self::ENVIO_INICIADO, [
            'tenant' => $tenant,
            'id_conductor' => $idConductor,
            'id_pedido' => $idPedido,
        ]);
    }

    public function envioTerminado(string $tenant, int $idConductor, int $idPedido): void
    {
        $this->publicar(self::ENVIO_TERMINADO, [
            'tenant' => $tenant,
            'id_conductor' => $idConductor,
            'id_pedido' => $idPedido,
        ]);
    }

    /**
     * `desde` es una marca de tiempo, no un identificador de permiso: el servicio rechaza todo
     * permiso emitido antes de ese instante, con lo que caen de una vez los que el conductor
     * tuviera vivos en varios dispositivos (ver `PermisoGpsService::cancelar()`).
     */
    public function permisoCancelado(string $tenant, int $idConductor, int $desde): void
    {
        $this->publicar(self::PERMISO_CANCELADO, [
            'tenant' => $tenant,
            'id_conductor' => $idConductor,
            'desde' => $desde,
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function publicar(string $tipo, array $datos): void
    {
        if (! $this->habilitado()) {
            return;
        }

        try {
            $this->crudo([
                'XADD', config('gps.streams.hacia_servicio'),
                // MAXLEN aproximado: Redis poda por bloques enteros en vez de contar uno a uno,
                // que es varias veces mas barato y para un tope de 100k da exactamente igual.
                'MAXLEN', '~', (string) self::MAX_MENSAJES,
                '*',
                'tipo', $tipo,
                'datos', json_encode($datos, JSON_THROW_ON_ERROR),
            ]);
        } catch (\Throwable $e) {
            // Que Redis no conteste no puede impedir entregar un pedido ni cerrar sesión.
            Log::warning('No se pudo publicar el aviso al servicio GPS.', [
                'tipo' => $tipo,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Prepara el grupo de consumidores del stream de entrada. Se llama al arrancar el worker y
     * cada vez que Redis se cae, porque un Redis reiniciado vuelve sin grupos.
     *
     * Se crea en `$` (solo lo nuevo): al arrancar por primera vez, un `RECORRIDO` de hace tres
     * días describiría un envío que ya se cerró.
     */
    public function asegurarGrupo(): void
    {
        try {
            $this->crudo([
                'XGROUP', 'CREATE',
                config('gps.streams.hacia_laravel'),
                config('gps.grupo'),
                '$',
                'MKSTREAM',
            ]);
        } catch (\Throwable $e) {
            // BUSYGROUP significa que ya existía, que es el caso normal.
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                Log::warning('No se pudo preparar el grupo del buzón GPS.', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Lee avisos sin confirmar. Bloquea hasta `$bloqueoMs` esperando: sin eso, el worker giraría
     * en vacío consultando Redis miles de veces por segundo.
     *
     * @return array<int, array{id: string, tipo: string, datos: array<string, mixed>}>
     */
    public function leer(string $consumidor, int $cantidad = 50, int $bloqueoMs = 5000): array
    {
        $respuesta = $this->crudo([
            'XREADGROUP',
            'GROUP', config('gps.grupo'), $consumidor,
            'COUNT', (string) $cantidad,
            'BLOCK', (string) $bloqueoMs,
            'STREAMS', config('gps.streams.hacia_laravel'), '>',
        ]);

        // Sin avisos en la ventana de espera Redis responde nil, que llega como false o null segun
        // el cliente. No es un error: es que no paso nada en esos segundos.
        if (! is_array($respuesta)) {
            return [];
        }

        $avisos = [];

        // Forma cruda de XREADGROUP: [[nombre_stream, [[id, [campo, valor, campo, valor]], ...]]].
        foreach ($respuesta as $flujo) {
            foreach ($flujo[1] ?? [] as $mensaje) {
                $campos = $this->aClaveValor($mensaje[1] ?? []);
                $datos = json_decode((string) ($campos['datos'] ?? ''), true);

                $avisos[] = [
                    'id' => (string) ($mensaje[0] ?? ''),
                    'tipo' => (string) ($campos['tipo'] ?? ''),
                    'datos' => is_array($datos) ? $datos : [],
                ];
            }
        }

        return $avisos;
    }

    /**
     * Los campos de un mensaje llegan aplanados —campo, valor, campo, valor—, que es como viajan
     * por el protocolo.
     *
     * @param  array<int, string>  $plano
     * @return array<string, string>
     */
    private function aClaveValor(array $plano): array
    {
        $campos = [];

        for ($i = 0; $i + 1 < count($plano); $i += 2) {
            $campos[(string) $plano[$i]] = (string) $plano[$i + 1];
        }

        return $campos;
    }

    /**
     * Un aviso solo se confirma después de aplicarse: si el worker muere a media faena, el aviso
     * sigue pendiente y otro consumidor lo vuelve a recibir (RN-17).
     */
    public function confirmar(string ...$ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->crudo(array_merge(
            ['XACK', config('gps.streams.hacia_laravel'), config('gps.grupo')],
            array_values($ids),
        ));
    }

    /**
     * Conexión sin prefijo (ver `config/database.php`): el servicio en Go tiene que ver las mismas
     * claves con el mismo nombre.
     */
    private function conexion(): Connection
    {
        return Redis::connection(config('gps.conexion'));
    }

    /**
     * Manda el comando tal cual, sin pasar por los envoltorios del cliente.
     *
     * phpredis (lo que corre en el VPS) y predis (lo comodo en Windows para desarrollar) no
     * coinciden ni en el nombre del metodo ni en el orden de los argumentos de XADD y XREADGROUP:
     * el mismo codigo funcionaria en uno y guardaria basura en el otro. El protocolo de Redis, en
     * cambio, es uno solo, y la respuesta cruda llega con la misma forma por los dos caminos.
     *
     * @param  array<int, string>  $argumentos
     */
    private function crudo(array $argumentos): mixed
    {
        $cliente = $this->conexion()->client();

        if (method_exists($cliente, 'executeRaw')) {
            return $cliente->executeRaw($argumentos);
        }

        return $cliente->rawCommand(...$argumentos);
    }
}
