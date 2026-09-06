<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Tenant\ConductorDisponibilidadCambiada;
use App\Http\Controllers\Tenant\VentaViajeConductorController;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConfiguracionTenant;
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

        return $this->guardar($conductor, 'ONLINE');
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
     */
    private function tieneSaldoDisponible(Conductor $conductor): bool
    {
        $modalidad = ConfiguracionTenant::obtener(ConfiguracionTenant::MODALIDAD, 'Prepago');

        if ($modalidad === 'Comision') {
            return $this->saldos->tieneSaldoDisponible($conductor);
        }

        return VentaViajeConductorController::saldoConductor($conductor) > 0;
    }

    private function guardar(Conductor $conductor, string $estado): ConductorEstado
    {
        $conductorEstado = ConductorEstado::firstOrNew(['id_conductor' => $conductor->id_conductor]);
        $conductorEstado->estado = $estado;
        $conductorEstado->{$estado === 'ONLINE' ? 'ultima_conexion' : 'ultima_desconexion'} = now();
        $conductorEstado->save();

        $disponibilidad = $estado === 'ONLINE' ? 'DISPONIBLE' : 'FUERA_DE_SERVICIO';
        $conductor->update(['disponibilidad' => $disponibilidad]);

        if ($slug = tenant()?->slug) {
            ConductorDisponibilidadCambiada::dispatch($conductor->id_conductor, $disponibilidad, $slug);
        }

        return $conductorEstado;
    }
}
