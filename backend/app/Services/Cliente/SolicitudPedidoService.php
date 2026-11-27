<?php

declare(strict_types=1);

namespace App\Services\Cliente;

use App\Models\Tenant\Cliente;
use App\Models\Tenant\ClienteSolicitudPedido;
use App\Models\Tenant\Pedido;
use App\Services\CreacionPedidoService;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * La llave anti-duplicados de la app de clientes (spec tenant/035, D12 y RN-31).
 *
 * La llave siempre se busca junto con el cliente, `(id_cliente, llave)`: la de otro cliente nunca
 * se encuentra, así que nunca se devuelve un pedido ajeno.
 */
class SolicitudPedidoService
{
    /** Ya hay un pedido para esta llave y estos datos: se devuelve ese. */
    public const CREADO = 'CREADO';

    /** La misma llave y los mismos datos siguen en proceso: la app reintenta en 2 segundos. */
    public const PROCESANDO = 'PROCESANDO';

    /** La misma llave con otros datos: no se crea nada. */
    public const OTROS_DATOS = 'OTROS_DATOS';

    public function __construct(private readonly CreacionPedidoService $creacion) {}

    /**
     * Lo que ya existe para esta llave, o `null` si es nueva.
     *
     * @return array{0: string, 1: Pedido|null}|null
     */
    public function previa(Cliente $cliente, string $llave, string $huella): ?array
    {
        $solicitud = ClienteSolicitudPedido::where('id_cliente', $cliente->id_cliente)
            ->where('llave', $llave)
            ->first();

        if ($solicitud === null) {
            return null;
        }

        if (! hash_equals($solicitud->huella, $huella)) {
            return [self::OTROS_DATOS, null];
        }

        $pedido = $solicitud->id_pedido !== null
            ? Pedido::withoutGlobalScopes()->find($solicitud->id_pedido)
            : null;

        return $pedido !== null ? [self::CREADO, $pedido] : [self::PROCESANDO, null];
    }

    /**
     * Aparta la llave y crea el pedido. Si otra petición con la misma llave ganó el índice único,
     * devuelve lo que dejó esa (`previa()`) en lugar de crear un segundo pedido.
     *
     * @param  array<string, mixed>  $datosPedido  Los campos de `Pedido` para `CreacionPedidoService`.
     * @return array{0: string, 1: Pedido|null}
     */
    public function crear(Cliente $cliente, string $llave, string $huella, array $datosPedido): array
    {
        try {
            $solicitud = ClienteSolicitudPedido::create([
                'id_cliente' => $cliente->id_cliente,
                'llave' => $llave,
                'huella' => $huella,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->previa($cliente, $llave, $huella) ?? [self::PROCESANDO, null];
        }

        try {
            $pedido = $this->creacion->crear($datosPedido, null);
        } catch (\Throwable $e) {
            // Sin pedido, la llave se libera para que el reintento pueda crearlo.
            $solicitud->delete();

            throw $e;
        }

        $solicitud->update(['id_pedido' => $pedido->id_pedido]);

        return [self::CREADO, $pedido];
    }
}
