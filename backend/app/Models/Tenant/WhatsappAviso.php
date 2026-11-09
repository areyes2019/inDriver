<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Un aviso de estado de un pedido por WhatsApp, enviado o no (spec tenant/031, etapa 7a).
 */
#[Fillable(['id_pedido', 'momento', 'estado', 'motivo', 'wamid'])]
class WhatsappAviso extends Model
{
    public const ENVIADO = 'ENVIADO';

    public const OMITIDO = 'OMITIDO';

    public const FALLIDO = 'FALLIDO';

    protected $table = 'whatsapp_avisos';

    protected $primaryKey = 'id_aviso';
}
