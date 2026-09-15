<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Auditoria;
use App\Models\Tenant\ComisionConductor;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\LiquidacionComision;
use App\Models\Tenant\Usuario;
use App\Support\ComisionesConductor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único lugar que registra pagos de comisión y marca viajes como pagados (spec tenant/030).
 *
 * Todo ocurre en una transacción con `lockForUpdate` sobre el conductor (adición 5): leer el
 * pendiente, validar el tope, anotar el pago y marcar los viajes. Dos personas registrando a la
 * vez sobre el mismo conductor no pueden pagar de más: la segunda espera, relee y se rechaza.
 * Mismo mecanismo que `SaldoService`.
 */
class LiquidacionComisionService
{
    /**
     * @throws ValidationException en `monto` si es cero/negativo o excede el pendiente (RN-10).
     */
    public function registrar(
        Conductor $conductor,
        float $monto,
        ?Usuario $registradoPor = null,
        ?string $referencia = null,
    ): LiquidacionComision {
        $monto = round($monto, 2);

        if ($monto <= 0) {
            throw ValidationException::withMessages([
                'monto' => ['El monto a liquidar debe ser mayor a cero.'],
            ]);
        }

        return DB::transaction(function () use ($conductor, $monto, $registradoPor, $referencia) {
            /** @var Conductor $conductorBloqueado */
            $conductorBloqueado = Conductor::query()->lockForUpdate()->findOrFail($conductor->id_conductor);

            $pendiente = ComisionesConductor::pendienteDe($conductorBloqueado);

            if ($monto > $pendiente) {
                throw ValidationException::withMessages([
                    'monto' => ["El monto excede la comisión pendiente del conductor ({$pendiente})."],
                ]);
            }

            $liquidacion = LiquidacionComision::create([
                'id_conductor' => $conductorBloqueado->id_conductor,
                'id_usuario' => $registradoPor?->id_usuario,
                'monto' => $monto,
                'referencia' => $referencia,
            ]);

            $this->marcarViajesCubiertos($conductorBloqueado, $liquidacion);

            Auditoria::create([
                'id_usuario' => $registradoPor?->id_usuario,
                'tabla_afectada' => 'liquidaciones_comision',
                'accion' => 'ALTA',
                'descripcion' => "Liquidación de comisión de {$liquidacion->monto} al conductor {$conductorBloqueado->id_conductor}",
            ]);

            return $liquidacion->load('comisiones');
        });
    }

    /**
     * Estado por viaje, FIFO (spec tenant/030, "Estado por viaje"). Los totales nunca dependen de
     * esto: generado, pagado y pendiente son sumas. Aquí solo se decide qué viajes ya quedaron
     * cubiertos por completo y por cuál pago.
     *
     * `disponible` recupera solo el sobrante de liquidaciones anteriores que no alcanzó para
     * cubrir un viaje entero: todo lo pagado menos todo lo ya marcado como pagado.
     */
    private function marcarViajesCubiertos(Conductor $conductor, LiquidacionComision $liquidacion): void
    {
        $pagado = (float) LiquidacionComision::where('id_conductor', $conductor->id_conductor)->sum('monto');
        $cubierto = (float) ComisionConductor::where('id_conductor', $conductor->id_conductor)
            ->where('estado', ComisionConductor::ESTADO_PAGADA)
            ->sum('importe_comision');

        $disponible = round($pagado - $cubierto, 2);

        $pendientes = ComisionConductor::where('id_conductor', $conductor->id_conductor)
            ->where('estado', ComisionConductor::ESTADO_PENDIENTE)
            ->orderBy('created_at')
            ->orderBy('id_comision')
            ->get();

        foreach ($pendientes as $comision) {
            $importe = (float) $comision->importe_comision;

            if ($disponible < $importe) {
                break;
            }

            $comision->update([
                'estado' => ComisionConductor::ESTADO_PAGADA,
                'id_liquidacion' => $liquidacion->id_liquidacion,
                'pagada_en' => now(),
            ]);

            $disponible = round($disponible - $importe, 2);
        }
    }
}
