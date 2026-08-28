<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id_usuario', 'tabla_afectada', 'accion', 'descripcion'])]
class Auditoria extends Model
{
    protected $table = 'auditoria';

    protected $primaryKey = 'id_auditoria';

    public const UPDATED_AT = null;
}
