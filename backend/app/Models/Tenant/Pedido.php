<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Models\Scopes\AmbienteScope;
use App\Observers\PedidoObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    'prepago_descontado',
    'comision_calculada',
    'id_despachador',
    'id_conductor',
    'id_vehiculo',
    'estado',
    'fecha_publicacion',
    'fecha_asignacion',
    'fecha_entrega',
    'fecha_cancelacion',
    'veces_ofertado',
    'distancia_recorrida_km',
    'resumen_ruta',
    'cancelado_por',
    'motivo_cancelacion',
    'notificado_conductor_en',
    'conteo_reubicaciones',
    'cargo_extra',
    'pago_extra',
])]
#[ScopedBy([AmbienteScope::class])]
#[ObservedBy([PedidoObserver::class])]
class Pedido extends Model
{
    /**
     * `ambiente` queda deliberadamente fuera del `#[Fillable]` de arriba (spec tenant/025, RN-03):
     * lo sella `Tenant\PedidoController@store` de forma explícita al crear el envío y nadie más
     * puede tocarlo, ni por `update()` ni por un `fill()` accidental. Un envío es de prueba o no lo
     * es desde que nace hasta que muere.
     */
    public const AMBIENTE_LIVE = 'live';

    public const AMBIENTE_TEST = 'test';

    protected $table = 'pedidos';

    protected $primaryKey = 'id_pedido';

    /**
     * Avisos de tiempo real que no pueden salir hasta que la fila esté escrita (spec tenant/027).
     *
     * `PedidoEstadoService::transicionar()` no guarda —el llamador decide cuándo— y las cargas de
     * esos eventos se arman releyendo el pedido de la base (`DatosDeEventoPanel`), así que
     * dispararlos ahí mismo mandaba el estado **anterior**: el Panel recibía `pedido.tomado` con
     * `id_conductor` nulo y `seguimiento` nulo, y por eso no dibujaba la línea del tramo H1.
     *
     * Los llena `PedidoEstadoService` y los vacía `PedidoObserver::saved()`. Si el llamador nunca
     * guarda —una transición que aborta— no se manda nada, que es justo lo correcto.
     *
     * @var array<int, \Closure>
     */
    public array $avisosDiferidos = [];

    protected function casts(): array
    {
        return [
            'fecha_servicio' => 'date',
            'latitud_recogida' => 'decimal:7',
            'longitud_recogida' => 'decimal:7',
            'latitud_entrega' => 'decimal:7',
            'longitud_entrega' => 'decimal:7',
            'lo_antes_posible' => 'boolean',
            'importe_envio' => 'decimal:2',
            'importe_cobro' => 'decimal:2',
            'prepago_descontado' => 'boolean',
            'comision_calculada' => 'decimal:2',
            'fecha_publicacion' => 'datetime',
            'fecha_asignacion' => 'datetime',
            'fecha_entrega' => 'datetime',
            'fecha_cancelacion' => 'datetime',
            'distancia_recorrida_km' => 'decimal:2',
            'resumen_ruta' => 'array',
            'notificado_conductor_en' => 'datetime',
            'cargo_extra' => 'decimal:2',
            'pago_extra' => 'decimal:2',
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

    public function ofertas(): HasMany
    {
        return $this->hasMany(PedidoOferta::class, 'id_pedido', 'id_pedido');
    }

    public function posiciones(): HasMany
    {
        return $this->hasMany(ConductorPosicion::class, 'id_pedido', 'id_pedido');
    }

    public function cambios(): HasMany
    {
        return $this->hasMany(PedidoCambio::class, 'id_pedido', 'id_pedido');
    }

    public function cotizaciones(): HasMany
    {
        return $this->hasMany(PedidoCotizacion::class, 'id_pedido', 'id_pedido');
    }

    /** Tramos simulados, solo en envíos TEST (spec tenant/025). */
    public function simulaciones(): HasMany
    {
        return $this->hasMany(SimulacionEnvio::class, 'id_pedido', 'id_pedido');
    }

    public function esTest(): bool
    {
        return $this->ambiente === self::AMBIENTE_TEST;
    }
}
