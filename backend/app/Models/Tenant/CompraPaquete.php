<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'codigo_paquete',
    'cantidad_paquetes',
    'cantidad_viajes',
    'precio_unitario',
    'importe_total',
    'forma_pago',
    'estado',
    'fecha_compra',
])]
class CompraPaquete extends Model
{
    protected $table = 'compras_paquetes';

    protected $primaryKey = 'id_compra';

    protected function casts(): array
    {
        return [
            'precio_unitario' => 'decimal:2',
            'importe_total' => 'decimal:2',
            'fecha_compra' => 'datetime',
        ];
    }
}
