<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_cliente', 'alias', 'calle', 'numero', 'colonia', 'cp', 'ciudad', 'estado', 'referencia', 'latitud', 'longitud', 'instrucciones_entrega'])]
class DireccionCliente extends Model
{
    protected $table = 'direcciones_clientes';

    protected $primaryKey = 'id_direccion';

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }
}
