<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id_tenant', 'id_admin', 'tipo', 'accion', 'descripcion'])]
class LogCentral extends Model
{
    protected $table = 'logs_centrales';

    protected $primaryKey = 'id_log';

    public const UPDATED_AT = null;
}
