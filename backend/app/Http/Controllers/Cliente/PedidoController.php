<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cliente;

use App\Http\Controllers\Controller;
use App\Http\Resources\Cliente\PedidoAppResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\Pedido;
use App\Services\Cliente\CotizacionApp;
use App\Services\Cliente\SelloCotizacion;
use App\Services\Cliente\SolicitudPedidoService;
use App\Services\CotizacionPedidoService;
use App\Services\PedidoEstadoService;
use App\Support\HoraOperacion;
use App\Support\HorarioAtencion;
use App\Support\TelefonoMexicano;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Los pedidos del cliente en su app (spec tenant/035, RN-24, RN-30 a RN-38).
 *
 * El pedido se crea con `CreacionPedidoService`, el mismo del Panel y de WhatsApp (RN-30), así que
 * desde ahí todo es lo de hoy: publicación, ofertas, cola, modo automático y aviso al Panel
 * (RN-32). Todo se busca dentro de los pedidos del propio cliente: el de otro responde 404.
 */
class PedidoController extends Controller
{
    /** RN-34. */
    private const MAXIMO_POR_HORA = 5;

    private const MAXIMO_ACTIVOS = 3;

    /** Cuántos pedidos devuelve cada lista. */
    private const POR_LISTA = 50;

    /** Como en la app (`grupoDe`): una incidencia sigue en activos hasta que la flotilla la atiende. */
    private const COMPLETADOS = ['ENTREGADO', 'DEVUELTO'];

    /** RN-36: el cliente solo cancela mientras nadie ha aceptado. */
    private const CANCELABLES = ['PENDIENTE', 'PUBLICADO'];

    public function __construct(
        private readonly CotizacionApp $cotizacionApp,
        private readonly SelloCotizacion $sellos,
        private readonly SolicitudPedidoService $solicitudes,
        private readonly CotizacionPedidoService $cotizacion,
        private readonly PedidoEstadoService $estados,
    ) {}

    /** `GET /cliente/pedidos?grupo=activos|completados|cancelados`. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $grupo = $request->validate([
            'grupo' => ['required', Rule::in(['activos', 'completados', 'cancelados'])],
        ])['grupo'];

        $consulta = $this->propios($request);

        match ($grupo) {
            'activos' => $consulta->whereNotIn('estado', [...self::COMPLETADOS, 'CANCELADO']),
            'completados' => $consulta->whereIn('estado', self::COMPLETADOS),
            'cancelados' => $consulta->where('estado', 'CANCELADO'),
        };

        return PedidoAppResource::collection(
            $consulta->with('conductor.usuario', 'conductor.estadoActual')
                ->orderByDesc('id_pedido')
                ->limit(self::POR_LISTA)
                ->get(),
        );
    }

    /** `GET /cliente/pedidos/{pedido}`. */
    public function show(Request $request, int $pedido): JsonResponse
    {
        return response()->json(new PedidoAppResource($this->propio($request, $pedido)));
    }

    /**
     * `POST /cliente/pedidos` con el encabezado `Idempotency-Key` (RN-24, RN-30, RN-31, RN-34).
     *
     * Errores con `motivo`: `OTROS_DATOS` (422), `PROCESANDO` (409), `FUERA_DE_HORARIO`,
     * `SELLO_INVALIDO`, `DATOS_DISTINTOS`, `FUERA_DE_ZONA`,
     * `LIMITE` (429) y `SELLO_VENCIDO` (409, con la cotización nueva).
     */
    public function store(Request $request): JsonResponse
    {
        $cliente = $this->cliente($request);
        $llave = (string) $request->header('Idempotency-Key');

        if (! Str::isUuid($llave)) {
            throw ValidationException::withMessages(['llave' => 'Falta la llave de la solicitud.']);
        }

        $datos = $this->cotizacionApp->normalizar($request->validate([
            ...CotizacionController::reglasCotizacion(),
            'recogida.direccion' => ['required', 'string', 'max:255'],
            'entrega.direccion' => ['required', 'string', 'max:255'],
            'referencias_recogida' => ['nullable', 'string', 'max:255'],
            'referencias_entrega' => ['nullable', 'string', 'max:255'],
            'contacto_recogida.nombre' => ['required', 'string', 'max:120'],
            'contacto_recogida.telefono' => ['required', 'string', 'max:30'],
            'recibe.nombre' => ['required', 'string', 'max:120'],
            'recibe.telefono' => ['required', 'string', 'max:30'],
            'sello' => ['required', 'string', 'max:4000'],
        ]));

        $datos['contacto_recogida']['telefono'] = $this->telefono($datos['contacto_recogida']['telefono'], 'contacto_recogida.telefono');
        $datos['recibe']['telefono'] = $this->telefono($datos['recibe']['telefono'], 'recibe.telefono');

        $huella = $this->huella($datos);

        // Primero la llave: el reintento de un pedido ya creado recibe ese pedido aunque el sello
        // haya vencido o el límite ya se haya alcanzado con él.
        if ($previa = $this->solicitudes->previa($cliente, $llave, $huella)) {
            return $this->respuestaDeSolicitud($previa);
        }

        if ($motivo = $this->motivoParaNoCrear($cliente, $datos)) {
            return $motivo;
        }

        $sello = $this->sellos->abrir($datos['sello'], $cliente->id_cliente, tenant()->slug, $motivoSello);

        if ($sello === null && $motivoSello === 'VENCIDO') {
            // RN-24: se cotiza de nuevo; la app reenvía sola si el precio es igual.
            [$estado, $cuerpo] = $this->cotizacionApp->cotizar($cliente->id_cliente, $datos);

            return $estado === 200
                ? response()->json(['motivo' => 'SELLO_VENCIDO', 'cotizacion' => $cuerpo], 409)
                : response()->json($cuerpo, $estado);
        }

        if ($sello === null) {
            return response()->json(['motivo' => 'SELLO_INVALIDO'], 422);
        }

        if ($sello['condiciones'] !== $this->sellos->condiciones($datos)) {
            return response()->json(['motivo' => 'DATOS_DISTINTOS'], 422);
        }

        // RN-24: la zona se vuelve a revisar, por si cambió después de cotizar.
        foreach (['recogida', 'entrega'] as $punto) {
            if (! $this->cotizacion->cubre((float) $datos[$punto]['lat'], (float) $datos[$punto]['lng'])) {
                return response()->json(['motivo' => 'FUERA_DE_ZONA', 'punto' => $punto], 422);
            }
        }

        return $this->respuestaDeSolicitud(
            $this->solicitudes->crear($cliente, $llave, $huella, $this->datosDelPedido($cliente, $datos, $sello['importe_envio'])),
            201,
        );
    }

    /** `POST /cliente/pedidos/{pedido}/cancelar` (RN-36 y RN-37). */
    public function cancelar(Request $request, int $pedido): JsonResponse
    {
        $propio = $this->propio($request, $pedido);

        $cancelado = DB::transaction(function () use ($propio): ?Pedido {
            // Bloqueado y releído: si un repartidor lo acaba de tomar, ya no se cancela desde aquí.
            /** @var Pedido $bloqueado */
            $bloqueado = Pedido::withoutGlobalScopes()->lockForUpdate()->findOrFail($propio->id_pedido);

            if (! in_array($bloqueado->estado, self::CANCELABLES, true)) {
                return null;
            }

            $bloqueado->cancelado_por = 'CLIENTE';
            $bloqueado->motivo_cancelacion = 'Cancelado por el cliente desde la app';
            $this->estados->transicionar($bloqueado, 'CANCELADO');
            $bloqueado->save();

            return $bloqueado;
        });

        if ($cancelado === null) {
            return response()->json([
                'motivo' => 'YA_ASIGNADO',
                'pedido' => new PedidoAppResource($this->propio($request, $pedido)),
            ], 409);
        }

        Auditoria::create([
            'id_usuario' => null,
            'tabla_afectada' => 'pedidos',
            'accion' => 'CAMBIO_ESTADO',
            'descripcion' => "El cliente canceló el pedido {$cancelado->numero_pedido} desde la app",
        ]);

        return response()->json(new PedidoAppResource($cancelado->load('conductor.usuario', 'conductor.estadoActual')));
    }

    /**
     * D19, RN-29 y RN-34: lo que impide crear aunque los datos estén bien.
     *
     * @param  array<string, mixed>  $datos
     */
    private function motivoParaNoCrear(Cliente $cliente, array $datos): ?JsonResponse
    {
        if ($motivo = $this->cotizacionApp->motivoDeHorario($datos)) {
            return response()->json(['motivo' => $motivo], 422);
        }

        $pedidos = fn () => Pedido::withoutGlobalScopes()->where('id_cliente', $cliente->id_cliente);

        $enLaHora = $pedidos()->where('origen', 'APP')->where('created_at', '>=', now()->subHour())->count();
        $activos = $pedidos()->whereNotIn('estado', PedidoEstadoService::ESTADOS_FINALES)->count();

        if ($enLaHora >= self::MAXIMO_POR_HORA || $activos >= self::MAXIMO_ACTIVOS) {
            return response()->json(['motivo' => 'LIMITE'], 429);
        }

        return null;
    }

    /**
     * Los mismos campos que entregan el Panel y WhatsApp. Quien recibe va en `*_solicitante`, como
     * desde la SPEC-006.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function datosDelPedido(Cliente $cliente, array $datos, float $importeEnvio): array
    {
        $asap = (bool) $datos['lo_antes_posible'];
        $producto = $datos['modalidad'] === 'RECEPTOR_PAGA_ENVIO_PRODUCTOS' ? (float) $datos['importe_producto'] : 0;

        $agenda = ['fecha_servicio' => HoraOperacion::hoy(), 'hora_desde' => null, 'hora_hasta' => null];

        if (! $asap) {
            // RN-28: la ventana de la flotilla. Una ventana que pasaría de medianoche se corta en
            // 23:59, porque `hora_hasta` se guarda sin fecha y tiene que quedar después de `hora_desde`.
            $desde = HoraOperacion::momento($datos['fecha'], $datos['hora']);
            $hasta = $desde->copy()->addMinutes(HorarioAtencion::ventanaMinutos());

            $agenda = [
                'fecha_servicio' => $datos['fecha'],
                'hora_desde' => $datos['hora'],
                'hora_hasta' => $hasta->isSameDay($desde) ? $hasta->format('H:i') : '23:59',
            ];
        }

        return [
            'id_cliente' => $cliente->id_cliente,
            'origen' => 'APP',
            'tipo_servicio' => $datos['tipo'],
            'nombre_solicitante' => $datos['recibe']['nombre'],
            'telefono_solicitante' => $datos['recibe']['telefono'],
            'contacto_recogida_nombre' => $datos['contacto_recogida']['nombre'],
            'contacto_recogida_telefono' => $datos['contacto_recogida']['telefono'],
            'direccion_recogida' => $datos['recogida']['direccion'],
            'latitud_recogida' => $datos['recogida']['lat'],
            'longitud_recogida' => $datos['recogida']['lng'],
            'referencias_recogida' => $datos['referencias_recogida'] ?? null,
            'direccion_entrega' => $datos['entrega']['direccion'],
            'latitud_entrega' => $datos['entrega']['lat'],
            'longitud_entrega' => $datos['entrega']['lng'],
            'referencias_entrega' => $datos['referencias_entrega'] ?? null,
            ...$agenda,
            'lo_antes_posible' => $asap,
            'modalidad_pago' => $datos['modalidad'],
            'importe_envio' => $importeEnvio,
            'importe_cobro' => $producto,
        ];
    }

    /**
     * RN-31: la huella de todo lo que el cliente capturó, sin el sello (que cambia al recotizar
     * sin que cambie la solicitud).
     *
     * @param  array<string, mixed>  $datos
     */
    private function huella(array $datos): string
    {
        $limpio = fn (?string $texto) => trim((string) $texto);

        return hash('sha256', (string) json_encode([
            $this->sellos->condiciones($datos),
            $limpio($datos['recogida']['direccion']),
            $limpio($datos['entrega']['direccion']),
            $limpio($datos['referencias_recogida'] ?? null),
            $limpio($datos['referencias_entrega'] ?? null),
            $limpio($datos['contacto_recogida']['nombre']),
            $datos['contacto_recogida']['telefono'],
            $limpio($datos['recibe']['nombre']),
            $datos['recibe']['telefono'],
        ]));
    }

    /**
     * @param  array{0: string, 1: Pedido|null}  $resultado
     */
    private function respuestaDeSolicitud(array $resultado, int $estadoCreado = 200): JsonResponse
    {
        [$estado, $pedido] = $resultado;

        return match ($estado) {
            SolicitudPedidoService::CREADO => response()->json(
                new PedidoAppResource($pedido->load('conductor.usuario', 'conductor.estadoActual')),
                $estadoCreado,
            ),
            SolicitudPedidoService::PROCESANDO => response()->json(['motivo' => 'PROCESANDO', 'message' => 'Tu pedido se está procesando'], 409),
            default => response()->json(['motivo' => 'OTROS_DATOS'], 422),
        };
    }

    private function telefono(string $valor, string $campo): string
    {
        $nacional = TelefonoMexicano::nacional($valor);

        if ($nacional === null) {
            throw ValidationException::withMessages([$campo => 'Escribe un celular de 10 dígitos.']);
        }

        return $nacional;
    }

    /**
     * Los pedidos del cliente que ve la app: todos los suyos (también los del Panel y WhatsApp),
     * menos los de prueba (spec tenant/025).
     *
     * @return Builder<Pedido>
     */
    private function propios(Request $request): Builder
    {
        return Pedido::withoutGlobalScopes()
            ->where('id_cliente', $this->cliente($request)->id_cliente)
            ->where('ambiente', Pedido::AMBIENTE_LIVE);
    }

    private function propio(Request $request, int $pedido): Pedido
    {
        return $this->propios($request)
            ->with('conductor.usuario', 'conductor.estadoActual')
            ->findOrFail($pedido);
    }

    private function cliente(Request $request): Cliente
    {
        return $request->user('cliente-token');
    }
}
