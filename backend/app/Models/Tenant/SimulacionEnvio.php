<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un tramo simulado de un envío TEST (spec tenant/025).
 *
 * `iniciada_en` es prácticamente todo el estado: la posición del móvil se deduce del reloj
 * (RN-13), no de un contador que haya que mantener vivo. `avanzada_hasta_m` solo recuerda hasta
 * dónde ya se escribieron puntos, para no repetirlos en la siguiente corrida del comando.
 */
#[Fillable([
    'id_pedido',
    'tramo',
    'ruta',
    'distancia_m',
    'iniciada_en',
    'avanzada_hasta_m',
    'terminada_en',
])]
class SimulacionEnvio extends Model
{
    protected $table = 'simulaciones_envio';

    protected $primaryKey = 'id_simulacion';

    public const TRAMO_ACERCAMIENTO = 'ACERCAMIENTO';

    public const TRAMO_ENTREGA = 'ENTREGA';

    /**
     * A qué estado pasa el envío cuando el móvil termina cada tramo (RN-12). Son los dos hitos que
     * en LIVE dispara la geocerca del teléfono, y que en TEST nunca se dispararían porque el
     * teléfono está quieto en otra parte.
     */
    public const ESTADO_AL_LLEGAR = [
        self::TRAMO_ACERCAMIENTO => 'ARRIBADO',
        self::TRAMO_ENTREGA => 'ARRIBADO_A_ENTREGA',
    ];

    protected function casts(): array
    {
        return [
            'ruta' => 'array',
            'distancia_m' => 'float',
            'avanzada_hasta_m' => 'float',
            'iniciada_en' => 'datetime',
            'terminada_en' => 'datetime',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'id_pedido', 'id_pedido');
    }
}
