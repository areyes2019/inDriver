<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Services\PedidoEstadoService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['id_usuario', 'id_despachador', 'numero_licencia', 'fecha_vencimiento_licencia', 'estado', 'disponibilidad', 'saldo'])]
class Conductor extends Model
{
    protected $table = 'conductores';

    protected $primaryKey = 'id_conductor';

    protected function casts(): array
    {
        return [
            'fecha_vencimiento_licencia' => 'date',
            'saldo' => 'decimal:2',
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

    /**
     * Todo lo que `ConductorActivoResource` necesita para pintar una fila de la flotilla: relaciones,
     * el pedido activo y las dos subconsultas del saldo.
     *
     * Es un scope y no código repetido porque desde la spec tenant/027 hay dos consumidores que
     * tienen que devolver **exactamente** la misma fila: el listado `GET /conductores/activos` y el
     * evento `conductor.disponibilidad-cambiada`, que ahora lleva el conductor completo para que el
     * Panel lo inserte sin recargar la lista. Si las dos consultas se escribieran por separado, un
     * conductor que se conecta se vería distinto al mismo conductor tras recargar.
     *
     * Aplícalo **al final** de la cadena: el `select('conductores.*')` de aquí borraría las columnas
     * que agregan `withSum`/`withCount` si estas se hubieran encadenado antes.
     */
    public function scopeConDatosDePanel(Builder $query): void
    {
        $query
            ->with([
                'usuario', 'vehiculo', 'estadoActual',
                'pedidos' => fn ($q) => $q->whereNotIn('estado', PedidoEstadoService::ESTADOS_FINALES),
            ])
            ->select('conductores.*')
            ->withSum('ventasViajes as viajes_vendidos', 'cantidad_viajes')
            ->withCount(['pedidos as viajes_consumidos' => fn ($q) => $q->where('prepago_descontado', true)]);
    }
}
