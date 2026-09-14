<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un viaje consumido del saldo del tenant por un envío entregado (spec tenant/015, corrección
 * "el saldo del tenant se consume por envío entregado"). La fila existe o no existe: contar es
 * la única lectura que necesita `SaldoViajesTenant`.
 */
#[Fillable(['id_pedido'])]
class ConsumoViajeTenant extends Model
{
    protected $table = 'consumos_viajes_tenant';

    protected $primaryKey = 'id_consumo';

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'id_pedido', 'id_pedido');
    }
}
