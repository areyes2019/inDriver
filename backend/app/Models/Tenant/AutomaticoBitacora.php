<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que hizo o detectó el modo automático (spec tenant/034, RN-26). Solo se escribe; nadie la edita.
 */
#[Fillable(['id_pedido', 'accion', 'detalle'])]
class AutomaticoBitacora extends Model
{
    public const REOFERTA = 'REOFERTA';

    public const CANCELACION = 'CANCELACION';

    public const PAUSA = 'PAUSA';

    public const PERSONA = 'PERSONA';

    public const ATORADO = 'ATORADO';

    public const UPDATED_AT = null;

    protected $table = 'automatico_bitacora';

    protected $primaryKey = 'id_bitacora';

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'id_pedido', 'id_pedido');
    }
}
