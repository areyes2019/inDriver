<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\ComisionConductor;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\LiquidacionComision;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\VentaViajeConductor;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Cuenta que el conductor ve en el monedero de panda_express, armada desde las bitácoras que ya
 * existen; no guarda nada propio.
 *
 * - `Comision`: entra la comisión de cada viaje y sale lo que el tenant ya le liquidó. `balance` es
 *   el pendiente histórico, el mismo número que ve el Panel (`ComisionesConductor`), no el del periodo.
 * - `Prepago`: entra el importe de cada envío entregado y sale lo que pagó por paquetes de viajes.
 *   `balance` es la resta dentro del periodo.
 */
class MonederoConductor
{
    /**
     * @return array{modalidad: string, entradas: float, salidas: float, balance: float, viajes_prepagados: ?int, movimientos: array<int, array{fecha: string, descripcion: string, entrada: float, salida: float}>}
     */
    public static function desde(Conductor $conductor, CarbonInterface $desde): array
    {
        $esComision = ConfiguracionTenant::obtener(ConfiguracionTenant::MODALIDAD, 'Prepago') === 'Comision';

        $movimientos = ($esComision ? self::deComision($conductor, $desde) : self::dePrepago($conductor, $desde))
            ->sortByDesc('fecha')
            ->values();

        $entradas = round((float) $movimientos->sum('entrada'), 2);
        $salidas = round((float) $movimientos->sum('salida'), 2);

        return [
            'modalidad' => $esComision ? 'Comision' : 'Prepago',
            'entradas' => $entradas,
            'salidas' => $salidas,
            'balance' => $esComision ? ComisionesConductor::pendienteDe($conductor) : round($entradas - $salidas, 2),
            'viajes_prepagados' => $esComision ? null : SaldoViajes::para($conductor),
            'movimientos' => $movimientos->all(),
        ];
    }

    private static function deComision(Conductor $conductor, CarbonInterface $desde): Collection
    {
        $comisiones = ComisionConductor::where('id_conductor', $conductor->id_conductor)
            ->where('created_at', '>=', $desde)
            ->with('pedido')
            ->get()
            ->map(fn (ComisionConductor $c) => self::movimiento(
                $c->created_at,
                'Comisión del envío '.($c->pedido?->numero_pedido ?? "#{$c->id_pedido}"),
                entrada: (float) $c->importe_comision,
            ));

        $liquidaciones = LiquidacionComision::where('id_conductor', $conductor->id_conductor)
            ->where('created_at', '>=', $desde)
            ->get()
            ->map(fn (LiquidacionComision $l) => self::movimiento(
                $l->created_at,
                'Pago recibido',
                salida: (float) $l->monto,
            ));

        return $comisiones->toBase()->concat($liquidaciones);
    }

    private static function dePrepago(Conductor $conductor, CarbonInterface $desde): Collection
    {
        $entregas = Pedido::where('id_conductor', $conductor->id_conductor)
            ->where('estado', 'ENTREGADO')
            ->where('fecha_entrega', '>=', $desde)
            ->get()
            ->map(fn (Pedido $p) => self::movimiento(
                $p->fecha_entrega,
                "Envío {$p->numero_pedido} entregado",
                entrada: (float) $p->importe_envio,
            ));

        $compras = VentaViajeConductor::where('id_conductor', $conductor->id_conductor)
            ->where('fecha_venta', '>=', $desde)
            ->get()
            ->map(fn (VentaViajeConductor $v) => self::movimiento(
                $v->fecha_venta,
                "Compra de {$v->cantidad_viajes} viajes",
                salida: (float) $v->monto_pagado,
            ));

        return $entregas->toBase()->concat($compras);
    }

    /**
     * @return array{fecha: string, descripcion: string, entrada: float, salida: float}
     */
    private static function movimiento(CarbonInterface $fecha, string $descripcion, float $entrada = 0, float $salida = 0): array
    {
        return [
            'fecha' => $fecha->copy()->utc()->toIso8601String(),
            'descripcion' => $descripcion,
            'entrada' => $entrada,
            'salida' => $salida,
        ];
    }
}
