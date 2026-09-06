<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Tenant\PedidoDireccionActualizada;
use App\Jobs\AvisarSinConfirmar;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConductorEstado;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\PedidoCambio;
use App\Models\Tenant\PedidoCotizacion;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\ZonaServicio;
use Illuminate\Validation\ValidationException;

/**
 * Incidencias sobre un envío ya asignado — cancelación, reprogramación y cambio de destino (spec
 * tenant/022, SPEC-022). El cierre del ciclo (cancelar/reprogramar en sí) vive en
 * `Tenant\PedidoController` y `PedidoEstadoService`; aquí está lo que le es propio a esta spec: la
 * bitácora, el "ack" de la App, y todo el flujo de reubicación.
 */
class CambioEnvioService
{
    /** RN-13: a la tercera reubicación se rechaza. */
    public const MAX_REUBICACIONES = 2;

    /** RN-11: una cotización vieja ya no aplica. */
    public const VIGENCIA_COTIZACION_MINUTOS = 5;

    public function registrarCambio(
        Pedido $pedido,
        string $tipo,
        ?array $anterior,
        ?array $nuevo,
        string $actorTipo,
        ?int $idUsuario,
    ): PedidoCambio {
        return PedidoCambio::create([
            'id_pedido' => $pedido->id_pedido,
            'tipo' => $tipo,
            'valor_anterior' => $anterior,
            'valor_nuevo' => $nuevo,
            'actor_tipo' => $actorTipo,
            'id_usuario' => $idUsuario,
        ]);
    }

    /**
     * Abre la ventana de 60s para que la App confirme (RN-03): limpia el ack anterior y programa
     * el aviso al Panel si no llega a tiempo. Solo tiene sentido si el envío tiene conductor. No
     * llama a `save()` — igual que `PedidoEstadoService::transicionar()`, deja que el llamador
     * decida cuándo persistir, para poder guardarlo junto con el resto del cambio.
     */
    public function requiereConfirmacion(Pedido $pedido): void
    {
        $pedido->notificado_conductor_en = null;

        if ($slug = tenant()?->slug) {
            AvisarSinConfirmar::dispatch(tenant(), $pedido->id_pedido)->delay(now()->addSeconds(60));
        }
    }

    /**
     * El "ack" del sistema (RN-16): la App solo confirma que vio el cambio, nunca lo rechaza — el
     * servidor ya lo validó antes de aplicarlo.
     */
    public function confirmar(Conductor $conductor, Pedido $pedido): void
    {
        if ($pedido->id_conductor !== $conductor->id_conductor) {
            abort(403, 'Este pedido no te pertenece.');
        }

        $pedido->notificado_conductor_en = now();
        $pedido->save();
    }

    /**
     * @throws ValidationException con `RELOCATION_LIMIT_REACHED` o `DROPOFF_OUT_OF_COVERAGE`.
     */
    public function cotizarReubicacion(Pedido $pedido, float $lat, float $lng, string $direccion): PedidoCotizacion
    {
        if ($pedido->conteo_reubicaciones >= self::MAX_REUBICACIONES) {
            throw ValidationException::withMessages([
                'destino' => ['RELOCATION_LIMIT_REACHED'],
            ]);
        }

        if (! ZonaServicio::cubrePunto($lat, $lng)) {
            throw ValidationException::withMessages([
                'destino' => ['DROPOFF_OUT_OF_COVERAGE'],
            ]);
        }

        // RN-10: desde la posición actual del conductor (spec tenant/021), no desde el origen —
        // si ya pasó el punto viejo, el desvío real es mayor. Sin una posición registrada todavía
        // (p. ej. recién aceptado, sin `LOCATION_UPDATE` aún), se cae al destino actual como punto
        // de partida: es la mejor aproximación disponible.
        $estado = ConductorEstado::where('id_conductor', $pedido->id_conductor)->first();
        $latActual = (float) ($estado?->ultima_latitud ?? $pedido->latitud_entrega);
        $lngActual = (float) ($estado?->ultima_longitud ?? $pedido->longitud_entrega);

        $distanciaNueva = $this->haversineKm($latActual, $lngActual, $lat, $lng);
        $distanciaVieja = $this->haversineKm($latActual, $lngActual, (float) $pedido->latitud_entrega, (float) $pedido->longitud_entrega);
        $distanciaExtraKm = max(0.0, $distanciaNueva - $distanciaVieja);

        $tarifaKmAdicional = (float) ConfiguracionTenant::obtener(ConfiguracionTenant::KM_ADICIONAL, '0');
        $cargoExtra = round($distanciaExtraKm * $tarifaKmAdicional, 2);

        return PedidoCotizacion::create([
            'id_pedido' => $pedido->id_pedido,
            'direccion_nueva' => $direccion,
            'latitud_nueva' => $lat,
            'longitud_nueva' => $lng,
            'distancia_extra_km' => round($distanciaExtraKm, 2),
            // Sin una regla de comisión propia para esto, el abono al conductor iguala el cargo
            // al cliente (spec no define el reparto; ver README de la fase).
            'cargo_extra' => $cargoExtra,
            'pago_extra' => $cargoExtra,
            'expira_en' => now()->addMinutes(self::VIGENCIA_COTIZACION_MINUTOS),
        ]);
    }

    /**
     * @throws ValidationException con `QUOTE_EXPIRED` o `RELOCATION_LIMIT_REACHED`.
     */
    public function aplicarReubicacion(Pedido $pedido, PedidoCotizacion $cotizacion, Usuario $actor): void
    {
        if (! $cotizacion->estaVigente()) {
            throw ValidationException::withMessages([
                'quote_id' => ['QUOTE_EXPIRED'],
            ]);
        }

        if ($pedido->conteo_reubicaciones >= self::MAX_REUBICACIONES) {
            throw ValidationException::withMessages([
                'quote_id' => ['RELOCATION_LIMIT_REACHED'],
            ]);
        }

        $anterior = [
            'direccion' => $pedido->direccion_entrega,
            'latitud' => (float) $pedido->latitud_entrega,
            'longitud' => (float) $pedido->longitud_entrega,
        ];

        $pedido->direccion_entrega = $cotizacion->direccion_nueva;
        $pedido->latitud_entrega = $cotizacion->latitud_nueva;
        $pedido->longitud_entrega = $cotizacion->longitud_nueva;
        $pedido->cargo_extra = round((float) $pedido->cargo_extra + (float) $cotizacion->cargo_extra, 2);
        $pedido->pago_extra = round((float) $pedido->pago_extra + (float) $cotizacion->pago_extra, 2);
        $pedido->conteo_reubicaciones++;

        if ($pedido->id_conductor) {
            $this->requiereConfirmacion($pedido);
        }

        $pedido->save();

        $cotizacion->update(['usada' => true]);

        $this->registrarCambio(
            $pedido,
            'REUBICADO',
            $anterior,
            ['direccion' => $pedido->direccion_entrega, 'latitud' => (float) $pedido->latitud_entrega, 'longitud' => (float) $pedido->longitud_entrega],
            'ADMIN',
            $actor->id_usuario,
        );

        if ($pedido->id_conductor && $slug = tenant()?->slug) {
            PedidoDireccionActualizada::dispatch(
                $pedido->id_pedido,
                $slug,
                $pedido->id_conductor,
                $anterior['direccion'],
                $pedido->direccion_entrega,
                (float) $pedido->latitud_entrega,
                (float) $pedido->longitud_entrega,
                (float) $cotizacion->distancia_extra_km,
                (float) $cotizacion->pago_extra,
            );
        }
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $radioTierraKm = 6371.0;
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $formula = sin($deltaLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($deltaLng / 2) ** 2;

        return $radioTierraKm * 2 * atan2(sqrt($formula), sqrt(1 - $formula));
    }
}
