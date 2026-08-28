<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'telefono', 'email', 'referencia', 'estado'])]
class Cliente extends Model
{
    protected $table = 'clientes';

    protected $primaryKey = 'id_cliente';

    public function direcciones(): HasMany
    {
        return $this->hasMany(DireccionCliente::class, 'id_cliente', 'id_cliente');
    }
}
