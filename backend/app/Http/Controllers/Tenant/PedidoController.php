<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Events\Tenant\PedidoReprogramado;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\PedidoResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\ConductorPosicion;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\PedidoCotizacion;
use App\Services\CambioEnvioService;
use App\Services\PedidoEstadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PedidoController extends Controller
{
    public function __construct(
        private readonly PedidoEstadoService $estados,
        private readonly CambioEnvioService $cambios,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Pedido::query()
            ->with(['cliente', 'despachador.usuario', 'conductor.usuario', 'vehiculo'])
            ->orderByDesc('id_pedido');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('numero_pedido', 'like', "%{$search}%")
                    ->orWhere('nombre_solicitante', 'like', "%{$search}%")
                    ->orWhere('telefono_solicitante', 'like', "%{$search}%");
            });
        }

        if ($estado = $request->query('estado')) {
            $query->where('estado', $estado);
        }

        return PedidoResource::collection($query->paginate(15));
    }

    public function show(Pedido $pedido): JsonResponse
    {
        $pedido->load(['cliente', 'despachador.usuario', 'conductor.usuario', 'vehiculo']);

        return response()->json(new PedidoResource($pedido));
    }

    public function store(Request $request): JsonResponse
    {
        $this->validarPuedeCrearPedido($request);
        $this->validarTarifasConfiguradas();
        $data = $this->validarDatos($request);

        $pedido = DB::transaction(function () use ($data) {
            $siguienteId = (int) (Pedido::max('id_pedido') ?? 0) + 1;
            $numeroPedido = 'PED-'.str_pad((string) $siguienteId, 6, '0', STR_PAD_LEFT);

            return Pedido::create([
                ...$data,
                'numero_pedido' => $numeroPedido,
                'estado' => 'PENDIENTE',
            ]);
        });

        $pedido->load(['cliente', 'despachador.usuario', 'conductor.usuario', 'vehiculo']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'pedidos',
            'accion' => 'ALTA',
            'descripcion' => "Alta del pedido {$pedido->numero_pedido}",
        ]);

        return response()->json(new PedidoResource($pedido), 201);
    }

    public function update(Request $request, Pedido $pedido): JsonResponse
    {
        if (in_array($pedido->estado, PedidoEstadoService::ESTADOS_FINALES, true)) {
            throw ValidationException::withMessages([
                'estado' => "El pedido {$pedido->numero_pedido} ya está en un estado final ({$pedido->estado}) y no se puede editar.",
            ]);
        }

        $data = $this->validarDatos($request);

        // Capturado antes de update() (spec tenant/018): si cambia la agenda de un pedido ya
        // asignado, se avisa al conductor por tiempo real (RN-04, evento "crítico" con push).
        // `fecha_servicio` se normaliza a string (cast a Carbon en el modelo, string en $data) para
        // no comparar tipos distintos y detectar un cambio falso siempre.
        $agendaAntes = [
            'fecha_servicio' => $pedido->fecha_servicio?->toDateString(),
            'hora_desde' => $pedido->hora_desde,
            'hora_hasta' => $pedido->hora_hasta,
            'lo_antes_posible' => $pedido->lo_antes_posible,
        ];
        $cambioAgenda = collect($agendaAntes)->contains(
            fn ($valorAnterior, string $campo) => array_key_exists($campo, $data) && $data[$campo] != $valorAnterior
        );

        // spec tenant/022, RN-07: con el paquete ya en la mano, se cancela o se entrega, no se
        // reprograma. RN-06: no tiene sentido reprogramar a un horario que ya casi llegó.
        if ($cambioAgenda) {
            if (in_array($pedido->estado, ['EN_CAMINO', 'ARRIBADO_A_ENTREGA'], true)) {
                throw ValidationException::withMessages([
                    'fecha_servicio' => ['CANNOT_RESCHEDULE_IN_TRANSIT'],
                ]);
            }

            if (! $data['lo_antes_posible'] && Carbon::parse("{$data['fecha_servicio']} {$data['hora_desde']}")->lt(now()->addMinutes(15))) {
                throw ValidationException::withMessages([
                    'hora_desde' => ['SCHEDULE_IN_PAST'],
                ]);
            }
        }

        $pedido->update($data);
        $pedido->load(['cliente', 'despachador.usuario', 'conductor.usuario', 'vehiculo']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'pedidos',
            'accion' => 'EDICION',
            'descripcion' => "Edición del pedido {$pedido->numero_pedido}",
        ]);

        if ($cambioAgenda && $pedido->id_conductor !== null) {
            $agendaDespues = [
                'fecha_servicio' => $pedido->fecha_servicio?->toDateString(),
                'hora_desde' => $pedido->hora_desde,
                'hora_hasta' => $pedido->hora_hasta,
                'lo_antes_posible' => $pedido->lo_antes_posible,
            ];

            $this->cambios->registrarCambio($pedido, 'REPROGRAMADO', $agendaAntes, $agendaDespues, 'ADMIN', $request->user('usuario')->id_usuario);
            $this->cambios->requiereConfirmacion($pedido);
            $pedido->save();

            if ($slug = tenant()?->slug) {
                PedidoReprogramado::dispatch(
                    $pedido->id_pedido,
                    $slug,
                    $pedido->id_conductor,
                    $agendaAntes['lo_antes_posible'] ? 'Lo antes posible' : "{$agendaAntes['fecha_servicio']} {$agendaAntes['hora_desde']}",
                    $agendaDespues['lo_antes_posible'] ? 'Lo antes posible' : "{$agendaDespues['fecha_servicio']} {$agendaDespues['hora_desde']}",
                );
            }
        }

        return response()->json(new PedidoResource($pedido));
    }

    /**
     * Cotiza el cambio de destino sin aplicarlo (spec tenant/022, RN-10/RN-11/RN-13/RN-15).
     */
    public function cotizarReubicacion(Request $request, Pedido $pedido): JsonResponse
    {
        if (in_array($pedido->estado, PedidoEstadoService::ESTADOS_FINALES, true)) {
            throw ValidationException::withMessages([
                'estado' => ['DELIVERY_ALREADY_COMPLETED'],
            ]);
        }

        $data = $request->validate([
            'direccion' => ['required', 'string', 'max:255'],
            'latitud' => ['required', 'numeric', 'between:-90,90'],
            'longitud' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $cotizacion = $this->cambios->cotizarReubicacion($pedido, (float) $data['latitud'], (float) $data['longitud'], $data['direccion']);

        return response()->json([
            'quote_id' => $cotizacion->id_cotizacion,
            'extra_distance_km' => (float) $cotizacion->distancia_extra_km,
            'extra_charge' => (float) $cotizacion->cargo_extra,
            'expires_at' => $cotizacion->expira_en,
        ]);
    }

    /**
     * Aplica una cotización vigente (spec tenant/022, RN-12/RN-16).
     */
    public function aplicarReubicacion(Request $request, Pedido $pedido): JsonResponse
    {
        $data = $request->validate([
            'quote_id' => ['required', 'integer'],
        ]);

        $cotizacion = PedidoCotizacion::where('id_pedido', $pedido->id_pedido)->find($data['quote_id']);

        if (! $cotizacion) {
            throw ValidationException::withMessages([
                'quote_id' => ['QUOTE_NOT_FOUND'],
            ]);
        }

        $this->cambios->aplicarReubicacion($pedido, $cotizacion, $request->user('usuario'));

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'pedidos',
            'accion' => 'EDICION',
            'descripcion' => "Cambio de destino del pedido {$pedido->numero_pedido}",
        ]);

        return response()->json([
            'id_pedido' => $pedido->id_pedido,
            'direccion_entrega' => $pedido->direccion_entrega,
            'cargo_extra' => (float) $pedido->cargo_extra,
            'conteo_reubicaciones' => $pedido->conteo_reubicaciones,
        ]);
    }

    /**
     * Recorrido del envío para el mapa del Panel (spec tenant/021): mientras esté activo, es la
     * posición en vivo más el historial; a los 7 días de entregado, `conductor_posiciones` ya se
     * purgó y solo queda `resumen_ruta`.
     */
    public function recorrido(Pedido $pedido): JsonResponse
    {
        $puntos = ConductorPosicion::where('id_pedido', $pedido->id_pedido)
            ->orderBy('fecha_posicion')
            ->get(['latitud', 'longitud', 'fecha_posicion']);

        return response()->json([
            'data' => $puntos,
            'distancia_recorrida_km' => $pedido->distancia_recorrida_km,
            'resumen_ruta' => $pedido->resumen_ruta,
        ]);
    }

    public function cambiarEstado(Request $request, Pedido $pedido): JsonResponse
    {
        $data = $request->validate([
            'estado' => ['required', Rule::in(array_keys(PedidoEstadoService::TRANSICIONES))],
            // spec tenant/022: solo aplican al cancelar un envío que ya tiene conductor; se
            // ignoran en cualquier otra transición.
            'motivo' => ['nullable', 'string', 'max:120'],
            'cancelado_por' => ['nullable', Rule::in(['CLIENTE', 'ADMIN'])],
        ]);

        $nuevoEstado = $data['estado'];

        if ($nuevoEstado === 'CANCELADO') {
            $pedido->motivo_cancelacion = $data['motivo'] ?? null;
            $pedido->cancelado_por = $data['cancelado_por'] ?? 'ADMIN';
        }

        $this->estados->transicionar($pedido, $nuevoEstado);

        $pedido->save();
        $pedido->load(['cliente', 'despachador.usuario', 'conductor.usuario', 'vehiculo']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'pedidos',
            'accion' => 'CAMBIO_ESTADO',
            'descripcion' => "Cambio de estado del pedido {$pedido->numero_pedido} a {$nuevoEstado}",
        ]);

        return response()->json(new PedidoResource($pedido));
    }

    /**
     * Solo un rol "opera" la creación de pedidos a la vez, según la configuración del tenant
     * (spec tenant/011): AdminCliente cuando el tenant no usa despachadores, Despachador cuando sí
     * los usa. Nunca ambos al mismo tiempo.
     */
    private function validarPuedeCrearPedido(Request $request): void
    {
        $usaDespachadores = ConfiguracionTenant::obtener(ConfiguracionTenant::USAR_DESPACHADORES, 'No') === 'Sí';
        $rol = $request->user('usuario')->rol;

        $puedeCrear = ($rol === 'Despachador' && $usaDespachadores) || ($rol === 'AdminCliente' && ! $usaDespachadores);

        if (! $puedeCrear) {
            abort(403, 'No puedes crear pedidos con la configuración actual de despachadores del tenant.');
        }
    }

    /**
     * El tenant debe tener configuradas las tres tarifas (spec 015) antes de poder crear pedidos:
     * sin ellas no hay un `importe_envio` calculado que tenga sentido guardar.
     */
    private function validarTarifasConfiguradas(): void
    {
        $tarifaBanderazo = ConfiguracionTenant::obtener(ConfiguracionTenant::BANDERAZO);
        $kmIncluidosBanderazo = ConfiguracionTenant::obtener(ConfiguracionTenant::KM_INCLUIDOS);
        $tarifaKmAdicional = ConfiguracionTenant::obtener(ConfiguracionTenant::KM_ADICIONAL);

        if ($tarifaBanderazo === null || $kmIncluidosBanderazo === null || $tarifaKmAdicional === null) {
            throw ValidationException::withMessages([
                'importe_envio' => 'El administrador del tenant debe configurar las tarifas antes de poder agendar pedidos.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validarDatos(Request $request): array
    {
        $data = $request->validate([
            'id_cliente' => ['nullable', 'integer', 'exists:clientes,id_cliente'],
            'nombre_solicitante' => ['required', 'string', 'max:255'],
            'telefono_solicitante' => ['required', 'string', 'max:255'],
            'direccion_recogida' => ['required', 'string', 'max:255'],
            'latitud_recogida' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud_recogida' => ['nullable', 'numeric', 'between:-180,180'],
            'direccion_entrega' => ['required', 'string', 'max:255'],
            'latitud_entrega' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud_entrega' => ['nullable', 'numeric', 'between:-180,180'],
            'fecha_servicio' => ['nullable', 'date'],
            'lo_antes_posible' => ['sometimes', 'boolean'],
            'hora_desde' => ['nullable', 'date_format:H:i'],
            'hora_hasta' => ['nullable', 'date_format:H:i'],
            'modalidad_pago' => ['required', Rule::in([
                'RECEPTOR_PAGA_ENVIO',
                'REMITENTE_PAGA_ENVIO',
                'RECEPTOR_PAGA_ENVIO_PRODUCTOS',
            ])],
            'importe_envio' => ['required', 'numeric', 'min:0'],
            'importe_cobro' => ['nullable', 'numeric', 'min:0'],
            'id_despachador' => ['nullable', 'integer', 'exists:despachadores,id_despachador'],
            'id_conductor' => ['nullable', 'integer', 'exists:conductores,id_conductor'],
            'id_vehiculo' => ['nullable', 'integer', 'exists:vehiculos,id_vehiculo'],
        ]);

        $loAntesPosible = $request->boolean('lo_antes_posible');

        if (! $loAntesPosible) {
            if (empty($data['fecha_servicio'])) {
                throw ValidationException::withMessages([
                    'fecha_servicio' => 'Indica la fecha del servicio o marca "Lo antes posible".',
                ]);
            }

            if (empty($data['hora_desde']) || empty($data['hora_hasta'])) {
                throw ValidationException::withMessages([
                    'hora_desde' => 'Indica el horario del servicio o marca "Lo antes posible".',
                ]);
            }

            if ($data['hora_hasta'] <= $data['hora_desde']) {
                throw ValidationException::withMessages([
                    'hora_hasta' => 'La hora hasta debe ser posterior a la hora desde.',
                ]);
            }
        }

        $data['lo_antes_posible'] = $loAntesPosible;
        $data['fecha_servicio'] = $data['fecha_servicio'] ?? now()->toDateString();
        $data['importe_cobro'] = $data['modalidad_pago'] === 'RECEPTOR_PAGA_ENVIO_PRODUCTOS'
            ? ($data['importe_cobro'] ?? 0)
            : 0;

        return $data;
    }
}
