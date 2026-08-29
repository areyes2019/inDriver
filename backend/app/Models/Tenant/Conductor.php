<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id_usuario', 'numero_licencia', 'tipo_licencia', 'fecha_vencimiento_licencia', 'telefono_emergencia', 'estado', 'disponibilidad'])]
class Conductor extends Model
{
    protected $table = 'conductores';

    protected $primaryKey = 'id_conductor';

    protected function casts(): array
    {
        return [
            'fecha_vencimiento_licencia' => 'date',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'id_conductor', 'id_conductor');
    }
}
