<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una confirmación de la app de clientes con su llave anti-duplicados (spec tenant/035, RN-31).
 * `id_pedido` queda nulo mientras el pedido se está creando.
 */
#[Fillable(['id_cliente', 'llave', 'huella', 'id_pedido'])]
class ClienteSolicitudPedido extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cliente_solicitudes_pedido';

    protected $primaryKey = 'id_solicitud';

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'id_pedido', 'id_pedido');
    }
}
