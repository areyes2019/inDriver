<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Tenant\SaldoCambiado;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\MovimientoSaldo;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único lugar con permiso de tocar `conductores.saldo` (spec tenant/022, SPEC-023). Cada cambio
 * inserta una fila en `movimientos_saldo` y actualiza el saldo en la misma transacción, con
 * `lockForUpdate` sobre el conductor, para que dos movimientos simultáneos nunca se pisen.
 */
class SaldoService
{
    /**
     * Signo esperado de `monto` por tipo de movimiento: true exige positivo, false exige negativo,
     * null acepta cualquiera menos cero (AJUSTE puede ir en ambos sentidos).
     *
     * @var array<string, bool|null>
     */
    private const SIGNO_ESPERADO = [
        'CREDITO' => true,
        'COMISION' => false,
        'COMPENSACION' => true,
        'AJUSTE' => null,
    ];

    /**
     * @throws ValidationException si el monto es cero o tiene el signo equivocado para el tipo.
     */
    public function registrar(
        Conductor $conductor,
        string $tipo,
        float $monto,
        ?Pedido $pedido = null,
        ?Usuario $creadoPor = null,
        ?string $referencia = null,
    ): MovimientoSaldo {
        $this->validarMonto($tipo, $monto);

        return DB::transaction(function () use ($conductor, $tipo, $monto, $pedido, $creadoPor, $referencia) {
            /** @var Conductor $conductorBloqueado */
            $conductorBloqueado = Conductor::query()->lockForUpdate()->findOrFail($conductor->id_conductor);

            $saldoResultante = round((float) $conductorBloqueado->saldo + $monto, 2);
            $conductorBloqueado->saldo = $saldoResultante;
            $conductorBloqueado->save();

            $movimiento = MovimientoSaldo::create([
                'id_conductor' => $conductorBloqueado->id_conductor,
                'id_pedido' => $pedido?->id_pedido,
                'id_usuario' => $creadoPor?->id_usuario,
                'tipo' => $tipo,
                'monto' => $monto,
                'saldo_resultante' => $saldoResultante,
                'referencia' => $referencia,
            ]);

            if ($slug = tenant()?->slug) {
                SaldoCambiado::dispatch($movimiento, $slug);
            }

            return $movimiento;
        });
    }

    public function tieneSaldoDisponible(Conductor $conductor): bool
    {
        return (float) $conductor->saldo > 0;
    }

    private function validarMonto(string $tipo, float $monto): void
    {
        if ($monto === 0.0) {
            throw ValidationException::withMessages([
                'monto' => ['INVALID_AMOUNT'],
            ]);
        }

        $signoEsperado = self::SIGNO_ESPERADO[$tipo] ?? null;

        if ($signoEsperado === true && $monto < 0) {
            throw ValidationException::withMessages([
                'monto' => ['INVALID_AMOUNT'],
            ]);
        }

        if ($signoEsperado === false && $monto > 0) {
            throw ValidationException::withMessages([
                'monto' => ['INVALID_AMOUNT'],
            ]);
        }
    }
}
