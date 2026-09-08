<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Conductor;
use App\Models\Tenant\VentaViajeConductor;

/**
 * Viajes prepagados que le quedan a un conductor: vendidos menos consumidos (spec tenant/015).
 *
 * Existe por la spec tenant/027: el saldo pasó a viajar dentro de los eventos de tiempo real
 * (`pedido.tomado`, `pedido.entregado`, `saldo.acreditado`) para que el Panel actualice la fila sin
 * volver a pedir la lista. Con la fórmula escrita en tres lugares —el resource, el controlador de
 * ventas y cada evento— bastaba con que uno se moviera para que el Panel mostrara un número y el
 * detalle otro; aquí es uno solo.
 *
 * Las dos formas calculan lo mismo:
 *
 * - `para()` consulta la base. Es la de una fila suelta: un evento, una respuesta de venta.
 * - `desdeAgregados()` lee las subconsultas `withSum`/`withCount` que ya trae un listado, sin
 *   volver a consultar. Es la de las listas, donde una consulta por fila serían N consultas.
 */
class SaldoViajes
{
    public static function para(Conductor $conductor): int
    {
        $vendidos = VentaViajeConductor::where('id_conductor', $conductor->id_conductor)->sum('cantidad_viajes');
        $consumidos = $conductor->pedidos()->where('prepago_descontado', true)->count();

        return (int) $vendidos - $consumidos;
    }

    /**
     * Espera un `Conductor` cargado con los alias `viajes_vendidos` y `viajes_consumidos` (ver
     * `Conductor::scopeConDatosDePanel`). Si no vienen, cuentan como 0 — un conductor sin ventas ni
     * pedidos no tiene saldo, que es exactamente lo que devuelven esas subconsultas vacías.
     */
    public static function desdeAgregados(Conductor $conductor): int
    {
        return (int) $conductor->viajes_vendidos - (int) $conductor->viajes_consumidos;
    }
}
