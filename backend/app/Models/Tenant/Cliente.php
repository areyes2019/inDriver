<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['nombre', 'telefono', 'email', 'referencia', 'estado'])]
class Cliente extends Model
{
    protected $table = 'clientes';

    protected $primaryKey = 'id_cliente';
}
