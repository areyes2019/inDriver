<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Tenant\PedidoCanceladoParaConductor;
use App\Events\Tenant\PedidoDisponible;
use App\Events\Tenant\PedidoYaTomado;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\VentaViajeConductor;
use Illuminate\Validation\ValidationException;

/**
 * Transición de estado de un pedido + liquidación del conductor al entregar.
 *
 * Extraído de `Tenant\PedidoController` (spec tenant/013) para que tanto el panel de
 * despachador/admin como la app de conductor (panda_express) compartan exactamente la misma
 * máquina de estados y la misma regla de liquidación, sin duplicarla.
 */
class PedidoEstadoService
{
    public const ESTADOS_FINALES = ['ENTREGADO', 'CANCELADO', 'RECHAZADO'];

    /**
     * Mapa de transiciones válidas: desde cada estado, a qué estados se puede pasar.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSICIONES = [
        'PENDIENTE' => ['PUBLICADO', 'CANCELADO'],
        'PUBLICADO' => ['TOMADO', 'RECHAZADO', 'CANCELADO'],
        'TOMADO' => ['ARRIBADO', 'CANCELADO'],
        'ARRIBADO' => ['EN_CAMINO', 'CANCELADO'],
        'EN_CAMINO' => ['ARRIBADO_A_ENTREGA', 'CANCELADO'],
        'ARRIBADO_A_ENTREGA' => ['ENTREGADO', 'CANCELADO'],
        'ENTREGADO' => [],
        'RECHAZADO' => [],
        'CANCELADO' => [],
    ];

    /**
     * Subconjunto de `TRANSICIONES` que puede disparar el conductor desde la app (spec
     * tenant/013): nunca `RECHAZADO`, y solo sobre su propio pedido activo (el llamador valida la
     * pertenencia antes de invocar este servicio).
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSICIONES_CONDUCTOR = [
        'PUBLICADO' => ['TOMADO'],
        'TOMADO' => ['ARRIBADO', 'CANCELADO'],
        'ARRIBADO' => ['EN_CAMINO', 'CANCELADO'],
        'EN_CAMINO' => ['ARRIBADO_A_ENTREGA', 'CANCELADO'],
        'ARRIBADO_A_ENTREGA' => ['ENTREGADO', 'CANCELADO'],
    ];

    /**
     * Aplica la transición y sus efectos (fechas, liquidación) sobre el modelo en memoria. No
     * llama a `save()` — el llamador decide cuándo persistir, para poder envolverlo en su propia
     * transacción/auditoría.
     *
     * @throws ValidationException si la transición no es válida desde el estado actual.
     */
    public function transicionar(Pedido $pedido, string $nuevoEstado): void
    {
        $permitidos = self::TRANSICIONES[$pedido->estado] ?? [];

        if (! in_array($nuevoEstado, $permitidos, true)) {
            throw ValidationException::withMessages([
                'estado' => "No se puede pasar el pedido de {$pedido->estado} a {$nuevoEstado}.",
            ]);
        }

        $pedido->estado = $nuevoEstado;

        match ($nuevoEstado) {
            'PUBLICADO' => $pedido->fecha_publicacion = now(),
            'TOMADO' => $pedido->fecha_asignacion = now(),
            'ENTREGADO' => $pedido->fecha_entrega = now(),
            'CANCELADO' => $pedido->fecha_cancelacion = now(),
            default => null,
        };

        if ($nuevoEstado === 'ENTREGADO' && $pedido->id_conductor) {
            $this->liquidarConductor($pedido);
        }

        $this->notificarConductores($pedido, $nuevoEstado);
    }

    /**
     * Dispara los eventos de tiempo real (spec tenant/013) para que la app de conductor se entere
     * al instante, sin esperar su sondeo de 10s. `tenant()` resuelve al tenant ya inicializado por
     * `IdentificarTenantPorSlug` para esta petición. Se usa el slug (no el id numérico) para
     * nombrar el canal porque panda_express ya lo conoce en build-time (spec tenant/013, "un solo
     * tenant por build") — evita agregar `id_tenant` a las respuestas de la API solo para esto.
     */
    private function notificarConductores(Pedido $pedido, string $nuevoEstado): void
    {
        $slug = tenant()?->slug;

        if ($slug === null) {
            return;
        }

        match (true) {
            $nuevoEstado === 'PUBLICADO' => PedidoDisponible::dispatch($pedido, $slug),
            $nuevoEstado === 'TOMADO' => PedidoYaTomado::dispatch($pedido->id_pedido, $slug),
            $nuevoEstado === 'CANCELADO' && $pedido->id_conductor !== null => PedidoCanceladoParaConductor::dispatch($pedido->id_pedido, $slug),
            default => null,
        };
    }

    /**
     * Descuenta 1 viaje del saldo prepagado del conductor, o calcula la comisión del pedido, según
     * la modalidad de cobro configurada para el tenant (spec 015).
     */
    private function liquidarConductor(Pedido $pedido): void
    {
        $modalidad = ConfiguracionTenant::obtener(ConfiguracionTenant::MODALIDAD, 'Prepago');

        if ($modalidad === 'Comision') {
            $porcentaje = (float) ConfiguracionTenant::obtener(ConfiguracionTenant::COMISION_PORCENTAJE, '0');
            $pedido->comision_calculada = round((float) $pedido->importe_cobro * $porcentaje / 100, 2);

            return;
        }

        $vendidos = VentaViajeConductor::where('id_conductor', $pedido->id_conductor)->sum('cantidad_viajes');
        $consumidos = Pedido::where('id_conductor', $pedido->id_conductor)
            ->where('prepago_descontado', true)
            ->count();

        if (($vendidos - $consumidos) > 0) {
            $pedido->prepago_descontado = true;
        }
    }
}
