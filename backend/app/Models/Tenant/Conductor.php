<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Services\PedidoEstadoService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['id_usuario', 'id_despachador', 'numero_licencia', 'fecha_vencimiento_licencia', 'estado', 'disponibilidad', 'saldo', 'es_prueba'])]
class Conductor extends Model
{
    protected $table = 'conductores';

    protected $primaryKey = 'id_conductor';

    protected function casts(): array
    {
        return [
            'fecha_vencimiento_licencia' => 'date',
            'saldo' => 'decimal:2',
            'es_prueba' => 'boolean',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }

    public function despachador(): BelongsTo
    {
        return $this->belongsTo(Despachador::class, 'id_despachador', 'id_despachador');
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'id_conductor', 'id_conductor');
    }

    public function ventasViajes(): HasMany
    {
        return $this->hasMany(VentaViajeConductor::class, 'id_conductor', 'id_conductor');
    }

    public function movimientosSaldo(): HasMany
    {
        return $this->hasMany(MovimientoSaldo::class, 'id_conductor', 'id_conductor');
    }

    public function vehiculo(): HasOne
    {
        return $this->hasOne(Vehiculo::class, 'id_conductor', 'id_conductor');
    }

    public function estadoActual(): HasOne
    {
        return $this->hasOne(ConductorEstado::class, 'id_conductor', 'id_conductor');
    }

    /**
     * Tiene un pedido propio que no llegó a un estado final (spec tenant/013). Es la única fuente
     * de verdad de "está ocupado": no se duplica como un estado de `disponibilidad` (spec
     * tenant/019, RN-01).
     */
    public function tienePedidoActivo(): bool
    {
        return $this->pedidos()->whereNotIn('estado', PedidoEstadoService::ESTADOS_FINALES)->exists();
    }

    /**
     * Dispositivo (token FCM) para mandarle push cuando el socket de Reverb está caído
     * (spec tenant/018). Un solo registro por conductor.
     */
    public function dispositivo(): HasOne
    {
        return $this->hasOne(ConductorDispositivo::class, 'id_conductor', 'id_conductor');
    }
}
