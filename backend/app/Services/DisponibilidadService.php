<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Tenant\ConductorDisponibilidadCambiada;
use App\Http\Controllers\Tenant\VentaViajeConductorController;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConfiguracionTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Conectar/desconectar al conductor (spec tenant/019). Único lugar que decide si un conductor
 * puede encenderse y que sincroniza `conductor_estado.estado` con `conductores.disponibilidad` —
 * ambos ya existían (spec tenant/013) y quedan como las dos representaciones de "en línea": no se
 * agrega una columna nueva para eso (RN-01, solo dos estados, sin duplicar lo que ya distingue
 * `pedidos`).
 */
class DisponibilidadService
{
    public function __construct(private readonly SaldoService $saldos) {}

    /**
     * @throws ValidationException con `INSUFFICIENT_BALANCE` si el saldo no alcanza (RN-02).
     */
    public function conectar(Conductor $conductor): ConductorEstado
    {
        if (! $this->tieneSaldoDisponible($conductor)) {
            throw ValidationException::withMessages([
                'estado' => ['INSUFFICIENT_BALANCE'],
            ]);
        }

        $conductorEstado = $this->guardar($conductor, 'ONLINE');

        // Conectarse también es "quedar libre" (spec tenant/026, RN-01): si mientras estuvo
        // desconectado se acumularon pedidos, los ve al instante y no con la cara vacía de "No
        // tienes viajes" hasta que alguien publique uno nuevo.
        $this->reactivarCola($conductor);

        return $conductorEstado;
    }

    /**
     * @throws ValidationException con `HAS_ACTIVE_DELIVERY` si tiene un pedido en curso y no se
     *                             fuerza la desconexión (RN-03).
     */
    public function desconectar(Conductor $conductor, bool $forzar = false): ConductorEstado
    {
        if (! $forzar && $conductor->tienePedidoActivo()) {
            throw ValidationException::withMessages([
                'estado' => ['HAS_ACTIVE_DELIVERY'],
            ]);
        }

        return $this->guardar($conductor, 'OFFLINE');
    }

    /**
     * Modalidad `Comision`: saldo en dinero (SPEC-023). Modalidad `Prepago`: viajes disponibles
     * (spec tenant/013) — son los dos modelos de cobro que ya coexisten en el tenant.
     *
     * Público porque `OfertaPedidoService` decide con esto mismo si un conductor recién liberado
     * puede recibir la cola (spec tenant/026, RN-07): "puede trabajar" tiene que significar
     * exactamente lo mismo al conectarse que al terminar una entrega.
     */
    public function tieneSaldoDisponible(Conductor $conductor): bool
    {
        $modalidad = ConfiguracionTenant::obtener(ConfiguracionTenant::MODALIDAD, 'Prepago');

        if ($modalidad === 'Comision') {
            return $this->saldos->tieneSaldoDisponible($conductor);
        }

        return VentaViajeConductorController::saldoConductor($conductor) > 0;
    }

    /**
     * Las dos representaciones de "en línea" se escriben en una sola transacción: media escritura
     * (una tabla sí, la otra no) es justo la desincronización que el Panel y la App ven como
     * "dice en línea de un lado y fuera de servicio del otro".
     *
     * El aviso al Panel va después del commit y nunca puede tumbar el cambio de estado: si el
     * socket falla (Reverb apagado, cola sin tabla), el conductor igual quedó conectado y solo se
     * pierde el refresco en vivo — se registra en el log y la petición responde 200.
     */
    private function guardar(Conductor $conductor, string $estado): ConductorEstado
    {
        $disponibilidad = $estado === 'ONLINE' ? 'DISPONIBLE' : 'FUERA_DE_SERVICIO';

        $conductorEstado = DB::transaction(function () use ($conductor, $estado, $disponibilidad) {
            $conductorEstado = ConductorEstado::firstOrNew(['id_conductor' => $conductor->id_conductor]);
            $conductorEstado->estado = $estado;
            $conductorEstado->{$estado === 'ONLINE' ? 'ultima_conexion' : 'ultima_desconexion'} = now();

            // Conectarse ES una señal de vida (spec tenant/019, RN-04). Sin esto, un conductor que
            // vuelve después de horas arrastra el `ultima_actualizacion` de su sesión anterior, y
            // `conductor:apagar-inactivos` —que corre cada minuto— lo apaga al minuto siguiente de
            // haberse conectado, sin que él haya hecho nada mal.
            if ($estado === 'ONLINE') {
                $conductorEstado->ultima_actualizacion = now();
            }

            $conductorEstado->save();

            $conductor->update(['disponibilidad' => $disponibilidad]);

            return $conductorEstado;
        });

        if ($slug = tenant()?->slug) {
            try {
                ConductorDisponibilidadCambiada::dispatch($conductor->id_conductor, $disponibilidad, $slug);
            } catch (\Throwable $e) {
                Log::warning('No se pudo avisar al Panel del cambio de disponibilidad.', [
                    'id_conductor' => $conductor->id_conductor,
                    'disponibilidad' => $disponibilidad,
                    'tenant' => $slug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $conductorEstado;
    }

    /**
     * Nunca puede tumbar la conexión (mismo criterio que el aviso al Panel de `guardar()`): si la
     * reoferta falla, el conductor igual quedó en línea y su sondeo de 10s le traerá la cola unos
     * segundos después.
     */
    private function reactivarCola(Conductor $conductor): void
    {
        try {
            app(OfertaPedidoService::class)->reactivarColaPara($conductor);
        } catch (\Throwable $e) {
            Log::warning('No se pudo reactivar la cola del conductor al conectarse.', [
                'id_conductor' => $conductor->id_conductor,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
