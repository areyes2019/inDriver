<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Tenant\PedidoCreado;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Da de alta un pedido y lo mete al flujo de despacho (spec tenant/031).
 *
 * Es el único camino de creación para el Panel y para WhatsApp, de modo que un pedido sale igual
 * venga de donde venga. No valida: cada canal entrega `$data` ya validado, con los campos de
 * `Pedido` y `lo_antes_posible` resuelto.
 */
class CreacionPedidoService
{
    /** Cuántas veces se reintenta si otro pedido simultáneo ganó el mismo número. */
    private const INTENTOS_NUMERO = 3;

    public function __construct(
        private readonly PedidoEstadoService $estados,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  int|null  $idUsuario  Quien lo da de alta; `null` cuando no hay un usuario detrás, como en WhatsApp.
     */
    public function crear(array $data, ?int $idUsuario): Pedido
    {
        $pedido = $this->insertar($data);

        $pedido->load(['cliente', 'despachador.usuario', 'conductor.usuario', 'vehiculo']);

        Auditoria::create([
            'id_usuario' => $idUsuario,
            'tabla_afectada' => 'pedidos',
            'accion' => 'ALTA',
            'descripcion' => "Alta del pedido {$pedido->numero_pedido}",
        ]);

        // spec tenant/024: un pedido "lo antes posible" se publica en el acto — sin esto nace
        // PENDIENTE y se queda ahí para siempre, porque nada más en el sistema lo publica y sin
        // PUBLICADO nunca se crean las ofertas que forman el pool del conductor (spec tenant/020).
        // Los agendados los publica `pedidos:publicar-agendados` 15 minutos antes de su horario.
        if ($pedido->lo_antes_posible) {
            $this->publicar($pedido);
        }

        // spec tenant/027, RN-05: el alta se anuncia por el canal para que la fila entre sola a
        // "Viajes en turno". No basta con `pedido.disponible`: ese solo sale si el envío se publica
        // y además hay conductores elegibles en ese momento, así que un agendado —o uno creado con
        // la flotilla desconectada— obligaba a recargar la página para verlo.
        //
        // Va después de `publicar()` para que la carga lleve el estado con el que quedó el envío y
        // no el PENDIENTE intermedio del `create()`.
        if ($slug = tenant()?->slug) {
            PedidoCreado::dispatch($pedido->id_pedido, $slug);
        }

        return $pedido;
    }

    /**
     * El número sale de `max(id_pedido) + 1` sin bloqueo, así que dos altas simultáneas pueden
     * calcular el mismo. El índice único de `numero_pedido` rechaza a la segunda, que vuelve a
     * calcularlo en lugar de devolver un error.
     *
     * @param  array<string, mixed>  $data
     */
    private function insertar(array $data): Pedido
    {
        // spec tenant/025, RN-03: el envío sella aquí el ambiente del interruptor y no vuelve a
        // cambiarlo nunca. Va fuera del `create()` a propósito: `ambiente` no está en el
        // `#[Fillable]` del modelo, para que ningún `update()` ni `fill()` pueda moverlo después.
        $ambiente = ConfiguracionTenant::obtener(ConfiguracionTenant::AMBIENTE, Pedido::AMBIENTE_LIVE);

        for ($intento = 1; ; $intento++) {
            try {
                return DB::transaction(function () use ($data, $ambiente) {
                    // `withoutGlobalScopes()`: el número de envío es correlativo del tenant entero, no
                    // del ambiente. Sin esto, el primer envío TEST reciclaría el PED-000001 de un envío LIVE.
                    $siguienteId = (int) (Pedido::withoutGlobalScopes()->max('id_pedido') ?? 0) + 1;
                    $numeroPedido = 'PED-'.str_pad((string) $siguienteId, 6, '0', STR_PAD_LEFT);

                    $pedido = Pedido::create([
                        ...$data,
                        'numero_pedido' => $numeroPedido,
                        'estado' => 'PENDIENTE',
                    ]);

                    $pedido->ambiente = $ambiente;
                    $pedido->save();

                    return $pedido;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($intento >= self::INTENTOS_NUMERO) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Publica el pedido: es lo único que abre la oferta a los conductores en línea
     * (`PedidoEstadoService` -> `OfertaPedidoService::ofertar`, spec tenant/020).
     *
     * Va fuera de la transacción del alta y protegido (spec tenant/018, RN-08): si Reverb está
     * caído, quien lo crea no tiene por qué recibir un error — el pedido queda publicado igual y
     * el aviso perdido solo se anota en la bitácora. `transicionar` deja el estado en memoria
     * antes de avisar, así que se persiste pase lo que pase: dejar ofertas abiertas sobre un
     * pedido que en la base sigue PENDIENTE es peor que perder el aviso.
     */
    private function publicar(Pedido $pedido): void
    {
        try {
            $this->estados->transicionar($pedido, 'PUBLICADO');
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar de la publicación del pedido', [
                'id_pedido' => $pedido->id_pedido,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($pedido->estado === 'PUBLICADO') {
                $pedido->save();
            }
        }
    }
}
