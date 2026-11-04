<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Tenant\UbicacionActualizada;
use App\Models\Tenant as TenantCentral;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\Pedido;
use App\Services\Gps\BuzonGps;
use App\Services\TrackingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Worker del buzón del microservicio GPS (spec tenant/028, §6.4).
 *
 * Es el único punto por el que lo que ve el servicio entra a MySQL y al Panel: el servicio en Go
 * no abre la base de datos (RN-20) ni conoce Reverb. Aquí se traduce cada aviso a lo que el
 * sistema ya hacía —`TrackingService` y `UbicacionActualizada`—, sin lógica nueva de tracking.
 */
#[Signature('gps:consumir-buzon {--ciclos=0 : Cuántas vueltas dar antes de salir (0 = sin límite)}')]
#[Description('Aplica los avisos que manda el microservicio GPS: posición, recorrido y latido')]
class ConsumirBuzonGps extends Command
{
    /** Cada cuánto se vuelve a intentar lo que quedó sin confirmar (RN-17). */
    private const REVISAR_PENDIENTES_CADA = 30;

    /**
     * Una posición más vieja que esto ya no se difunde al Panel: al vaciar un buzón atrasado, el
     * marcador repetiría el viaje en cámara rápida. Se sigue guardando como última conocida.
     */
    private const DIFUSION_MAX_SEGUNDOS = 60;

    private bool $seguir = true;

    /** Slug del tenant activo ahora mismo, para no reinicializar tenancy en cada aviso. */
    private ?string $tenantActivo = null;

    public function handle(BuzonGps $buzon, TrackingService $tracking): int
    {
        if (! $buzon->habilitado()) {
            $this->components->warn('La integración GPS está apagada (falta GPS_SERVICE_URL). No hay nada que consumir.');

            return self::SUCCESS;
        }

        // systemd manda SIGTERM al reiniciar: se termina la vuelta en curso en vez de morir a
        // media escritura, y los avisos sin confirmar los recoge el siguiente arranque.
        //
        // `pcntl` no existe en Windows, donde este worker no corre: allá se desarrolla y se
        // prueba, y sin la extensión las constantes de señal ni siquiera están definidas.
        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], function () {
                $this->seguir = false;
            });
        }

        $buzon->asegurarGrupo();

        // Sin el PID a propósito: lo pendiente queda a nombre del consumidor, y el siguiente arranque
        // tiene que poder recogerlo. Corre un solo worker por máquina (`gps-buzon.service`).
        $consumidor = (string) gethostname();
        $ciclos = (int) $this->option('ciclos');
        $vuelta = 0;
        $proximaRevision = 0;

        while ($this->seguir) {
            try {
                if (time() >= $proximaRevision) {
                    $this->reintentarPendientes($buzon, $tracking, $consumidor);
                    $proximaRevision = time() + self::REVISAR_PENDIENTES_CADA;
                }

                $avisos = $buzon->leer($consumidor);
            } catch (\Throwable $e) {
                // Redis se cayó, o se reinició y con él el grupo. Se recrea y se reintenta con una
                // pausa, en vez de girar en vacío quemando CPU.
                Log::warning('No se pudo leer el buzón GPS.', ['error' => $e->getMessage()]);
                sleep(1);
                $buzon->asegurarGrupo();

                $avisos = [];
            }

            foreach ($avisos as $aviso) {
                if ($this->aplicar($aviso, $tracking, $buzon)) {
                    $buzon->confirmar($aviso['id']);
                }
            }

            $this->terminarTenant();

            $vuelta++;

            if ($ciclos > 0 && $vuelta >= $ciclos) {
                break;
            }
        }

        return self::SUCCESS;
    }

    /**
     * RN-17: un aviso no se pierde porque falló una vez, pero tampoco se reintenta sin fin.
     */
    private function reintentarPendientes(BuzonGps $buzon, TrackingService $tracking, string $consumidor): void
    {
        foreach ($buzon->leerPendientes($consumidor) as $aviso) {
            if ($aviso['entregas'] >= BuzonGps::MAX_ENTREGAS) {
                Log::error('Aviso GPS descartado tras agotar sus reintentos.', [
                    'tipo' => $aviso['tipo'],
                    'id' => $aviso['id'],
                    'entregas' => $aviso['entregas'],
                ]);
                $buzon->confirmar($aviso['id']);

                continue;
            }

            if ($this->aplicar($aviso, $tracking, $buzon)) {
                $buzon->confirmar($aviso['id']);
            }
        }

        $this->terminarTenant();
    }

    /**
     * Devuelve false para dejar el aviso sin confirmar y que se reintente. Un aviso mal formado se
     * da por procesado: reintentarlo para siempre solo atasca el buzón.
     *
     * @param  array{id: string, tipo: string, datos: array<string, mixed>}  $aviso
     */
    private function aplicar(array $aviso, TrackingService $tracking, BuzonGps $buzon): bool
    {
        $slug = (string) ($aviso['datos']['tenant'] ?? '');
        $idConductor = (int) ($aviso['datos']['id_conductor'] ?? 0);

        if ($slug === '' || $idConductor === 0) {
            Log::warning('Aviso GPS sin tenant o sin conductor.', ['tipo' => $aviso['tipo'], 'id' => $aviso['id']]);

            return true;
        }

        try {
            if (! $this->activarTenant($slug)) {
                return true;
            }

            return match ($aviso['tipo']) {
                BuzonGps::POSICION => $this->difundir($tracking, $slug, $idConductor, $aviso['datos']),
                BuzonGps::POSICION_SIN_ENVIO => $this->registrarSinEnvio($tracking, $buzon, $slug, $idConductor, $aviso['datos']),
                BuzonGps::RECORRIDO => $this->guardarRecorrido($tracking, $idConductor, $aviso['datos']),
                BuzonGps::LATIDO => $this->latir($tracking, $idConductor),
                default => true,
            };
        } catch (\Throwable $e) {
            Log::error('No se pudo aplicar un aviso del servicio GPS.', [
                'tipo' => $aviso['tipo'],
                'id' => $aviso['id'],
                'tenant' => $slug,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * El mismo evento y el mismo canal de siempre (spec tenant/018): por eso el Panel no cambia ni
     * una línea con esta spec.
     *
     * Pasa por el mismo filtro RN-03 que el camino HTTP directo (`TrackingService::registrarPosicion`)
     * antes de difundir: sin esto, una lectura de geolocalización por red/IP que llega por el
     * microservicio en vez de `Conductor\UbicacionController` se retransmitía sin filtrar al Panel.
     *
     * @param  array<string, mixed>  $datos
     */
    private function difundir(TrackingService $tracking, string $slug, int $idConductor, array $datos): bool
    {
        $conductor = Conductor::find($idConductor);

        // El conductor ya no existe: el aviso llegó tarde. No hay nada que reintentar ni difundir.
        if (! $conductor) {
            return true;
        }

        $latitud = (float) $datos['latitud'];
        $longitud = (float) $datos['longitud'];
        $capturadaEn = $this->capturadaEn($datos);

        // El descarte lo registra `filtrarPosicionEnVivo`, que es el punto compartido con el camino
        // HTTP directo: duplicarlo aquí daría dos líneas por la misma posición.
        if (! $tracking->filtrarPosicionEnVivo($conductor, $latitud, $longitud, $capturadaEn)) {
            return true;
        }

        if ($capturadaEn !== null && $capturadaEn->lt(now()->subSeconds(self::DIFUSION_MAX_SEGUNDOS))) {
            return true;
        }

        // Si Reverb no contesta se pierde este refresco y nada más (spec tenant/018, RN-05): la
        // posición ya quedó guardada y la siguiente lo reemplaza. Reintentarlo solo atrasaría al
        // Panel con posiciones viejas.
        try {
            UbicacionActualizada::dispatch($idConductor, $latitud, $longitud, $slug);
        } catch (\Throwable $e) {
            Log::warning('No se pudo difundir la posición al Panel.', [
                'tenant' => $slug,
                'id_conductor' => $idConductor,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * La hora a la que se tomó la lectura, que el aviso ya trae. Sin ella RN-03 medía contra el
     * momento de procesarla, y al vaciar una cola atrasada varias posiciones capturadas con minutos
     * de diferencia se evaluaban como si hubieran llegado en el mismo segundo: movimiento normal que
     * parecía imposible (RN-03d).
     *
     * @param  array<string, mixed>  $datos
     */
    private function capturadaEn(array $datos): ?Carbon
    {
        return isset($datos['fecha_ms']) ? Carbon::createFromTimestampMs((int) $datos['fecha_ms']) : null;
    }

    /**
     * Conductor en línea sin envío (RN-06 de spec tenant/028): mismo trato que le daba
     * `Conductor\UbicacionController::actualizar()` antes de que el servicio GPS existiera — se
     * recuerda dónde quedó, sin difundir al Panel ni generar historia (RN-01). Sin este aviso,
     * `conductor_estados.ultima_latitud/longitud` se quedaba vacío para cualquier conductor que
     * aceptara su primer envío hablándole al servicio GPS, y la simulación TEST arrancaba el tramo
     * de acercamiento en la recogida misma (spec tenant/025, RN-08), cayendo en `ARRIBADO` al
     * instante.
     *
     * El servicio dice "sin envío" porque no tiene la marca, y eso no siempre es cierto. Laravel es
     * el dueño de esa verdad, así que aquí se comprueba:
     *
     * - **Envío TEST:** es el caso normal, porque en TEST no se avisa al servicio (RN-13). Manda el
     *   simulador: se descarta la posición y queda el latido, igual que en
     *   `Conductor\UbicacionController::actualizar()`. Sin esto reaparece el incidente de §16.
     * - **Envío LIVE:** la marca se perdió (Redis reiniciado, o el envío abrió antes de conectar el
     *   servicio). Se trata como la posición en vivo que es y se vuelve a avisar al servicio. Al
     *   recibirlo, sus siguientes puntos ya vienen como `POSICION`.
     *
     * @param  array<string, mixed>  $datos
     */
    private function registrarSinEnvio(TrackingService $tracking, BuzonGps $buzon, string $slug, int $idConductor, array $datos): bool
    {
        $conductor = Conductor::find($idConductor);

        // El conductor ya no existe: el aviso llegó tarde. No hay nada que reintentar.
        if (! $conductor) {
            return true;
        }

        $activo = $tracking->pedidoActivo($conductor);

        if ($activo?->esTest()) {
            $tracking->registrarLatido($conductor);

            return true;
        }

        if ($activo !== null) {
            $buzon->envioIniciado($slug, $idConductor, $activo->id_pedido);

            return $this->difundir($tracking, $slug, $idConductor, $datos);
        }

        $tracking->registrarPosicionSinEnvio($conductor, (float) $datos['latitud'], (float) $datos['longitud'], $this->capturadaEn($datos));

        return true;
    }

    /**
     * Una tanda de hasta 50 puntos entra con una sola escritura, por `TrackingService`. No se difunde
     * nada ni se mueve la posición actual: la posición en vivo viaja por `POSICION`, esto es historia.
     *
     * @param  array<string, mixed>  $datos
     */
    private function guardarRecorrido(TrackingService $tracking, int $idConductor, array $datos): bool
    {
        $conductor = Conductor::find($idConductor);
        $pedido = Pedido::find((int) ($datos['id_pedido'] ?? 0));

        // El envío o el conductor ya no existen: el aviso llegó tarde a un pedido borrado. No hay
        // nada que reintentar.
        if (! $conductor || ! $pedido) {
            return true;
        }

        $puntos = array_values(array_filter(array_map(
            fn (array $punto) => $this->punto($punto),
            $datos['puntos'] ?? [],
        )));

        if ($puntos === []) {
            return true;
        }

        $tracking->registrarRecorrido($conductor, $pedido, $puntos);

        return true;
    }

    /**
     * @param  array<string, mixed>  $punto
     * @return array<string, mixed>|null
     */
    private function punto(array $punto): ?array
    {
        if (! isset($punto['latitud'], $punto['longitud'], $punto['fecha_ms'])) {
            return null;
        }

        return [
            'latitud' => (float) $punto['latitud'],
            'longitud' => (float) $punto['longitud'],
            // Los opcionales viajan como cadena vacía cuando el teléfono no los mandó: Redis no
            // guarda nulos en un hash.
            'precision' => $this->decimal($punto['precision'] ?? null),
            'velocidad' => $this->decimal($punto['velocidad'] ?? null),
            'rumbo' => $this->entero($punto['rumbo'] ?? null),
            'bateria' => $this->entero($punto['bateria'] ?? null),
            'fecha_posicion' => Carbon::createFromTimestampMs((int) $punto['fecha_ms']),
        ];
    }

    private function latir(TrackingService $tracking, int $idConductor): bool
    {
        if ($conductor = Conductor::find($idConductor)) {
            $tracking->registrarLatido($conductor);
        }

        return true;
    }

    private function decimal(mixed $valor): ?float
    {
        return ($valor === null || $valor === '') ? null : (float) $valor;
    }

    private function entero(mixed $valor): ?int
    {
        return ($valor === null || $valor === '') ? null : (int) $valor;
    }

    /**
     * Este worker vive semanas y salta entre tenants, así que —a diferencia de una petición HTTP—
     * tiene que cerrar la tenancy al terminar cada vuelta: si no, arrastra la conexión del último
     * tenant y termina escribiendo en la base equivocada.
     */
    private function activarTenant(string $slug): bool
    {
        if ($this->tenantActivo === $slug) {
            return true;
        }

        $tenant = TenantCentral::where('slug', $slug)->first();

        if (! $tenant) {
            Log::warning('Aviso GPS de un tenant desconocido.', ['tenant' => $slug]);

            return false;
        }

        $this->terminarTenant();

        tenancy()->initialize($tenant);
        $this->tenantActivo = $slug;

        return true;
    }

    private function terminarTenant(): void
    {
        if ($this->tenantActivo !== null) {
            tenancy()->end();
            $this->tenantActivo = null;
        }
    }
}
