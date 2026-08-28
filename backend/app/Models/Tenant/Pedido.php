<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'numero_pedido',
    'id_cliente',
    'nombre_solicitante',
    'telefono_solicitante',
    'direccion_recogida',
    'latitud_recogida',
    'longitud_recogida',
    'direccion_entrega',
    'latitud_entrega',
    'longitud_entrega',
    'fecha_servicio',
    'hora_desde',
    'hora_hasta',
    'lo_antes_posible',
    'modalidad_pago',
    'importe_envio',
    'importe_cobro',
    'id_despachador',
    'id_conductor',
    'id_vehiculo',
    'estado',
    'fecha_publicacion',
    'fecha_asignacion',
    'fecha_entrega',
    'fecha_cancelacion',
])]
class Pedido extends Model
{
    protected $table = 'pedidos';

    protected $primaryKey = 'id_pedido';

    protected function casts(): array
    {
        return [
            'fecha_servicio' => 'date',
            'lo_antes_posible' => 'boolean',
            'importe_envio' => 'decimal:2',
            'importe_cobro' => 'decimal:2',
            'fecha_publicacion' => 'datetime',
            'fecha_asignacion' => 'datetime',
            'fecha_entrega' => 'datetime',
            'fecha_cancelacion' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }

    public function despachador(): BelongsTo
    {
        return $this->belongsTo(Despachador::class, 'id_despachador', 'id_despachador');
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Conductor::class, 'id_conductor', 'id_conductor');
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'id_vehiculo', 'id_vehiculo');
    }
}
