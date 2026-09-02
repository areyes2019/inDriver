<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\PedidoResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\VentaViajeConductor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PedidoController extends Controller
{
    private const ESTADOS_FINALES = ['ENTREGADO', 'CANCELADO', 'RECHAZADO'];

    /**
     * Mapa de transiciones válidas: desde cada estado, a qué estados se puede pasar.
     *
     * @var array<string, array<int, string>>
     */
    private const TRANSICIONES = [
        'PENDIENTE' => ['PUBLICADO', 'CANCELADO'],
        'PUBLICADO' => ['TOMADO', 'RECHAZADO', 'CANCELADO'],
        'TOMADO' => ['ARRIBADO', 'CANCELADO'],
        'ARRIBADO' => ['EN_CAMINO', 'CANCELADO'],
        'EN_CAMINO' => ['ARRIBADO_A_ENTREGA', 'CANCELADO'],
        'ARRIBADO_A_ENTREGA' => ['ENTREGADO', 'CANCELADO'],
        'ENTREGADO' => [],
        'RECHAZADO' => [],
        'CANCELADO' => [],
    ];

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
        if (in_array($pedido->estado, self::ESTADOS_FINALES, true)) {
            throw ValidationException::withMessages([
                'estado' => "El pedido {$pedido->numero_pedido} ya está en un estado final ({$pedido->estado}) y no se puede editar.",
            ]);
        }

        $data = $this->validarDatos($request);

        $pedido->update($data);
        $pedido->load(['cliente', 'despachador.usuario', 'conductor.usuario', 'vehiculo']);

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'pedidos',
            'accion' => 'EDICION',
            'descripcion' => "Edición del pedido {$pedido->numero_pedido}",
        ]);

        return response()->json(new PedidoResource($pedido));
    }

    public function cambiarEstado(Request $request, Pedido $pedido): JsonResponse
    {
        $data = $request->validate([
            'estado' => ['required', Rule::in(array_keys(self::TRANSICIONES))],
        ]);

        $nuevoEstado = $data['estado'];
        $permitidos = self::TRANSICIONES[$pedido->estado];

        if (! in_array($nuevoEstado, $permitidos, true)) {
            throw ValidationException::withMessages([
                'estado' => "No se puede pasar el pedido de {$pedido->estado} a {$nuevoEstado}.",
            ]);
        }

        $pedido->estado = $nuevoEstado;

        match ($nuevoEstado) {
            'PUBLICADO' => $pedido->fecha_publicacion = now(),
            'TOMADO' => $pedido->fecha_asignacion = now(),
            'ENTREGADO' => $pedido->fecha_entrega = now(),
            'CANCELADO' => $pedido->fecha_cancelacion = now(),
            default => null,
        };

        if ($nuevoEstado === 'ENTREGADO' && $pedido->id_conductor) {
            $this->liquidarConductor($pedido);
        }

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
     * Descuenta 1 viaje del saldo prepagado del conductor, o calcula la comisión del pedido,
     * según la modalidad de cobro configurada para el tenant (ver spec 015).
     */
    private function liquidarConductor(Pedido $pedido): void
    {
        $modalidad = ConfiguracionTenant::obtener(ConfiguracionTenant::MODALIDAD, 'Prepago');

        if ($modalidad === 'Comision') {
            $porcentaje = (float) ConfiguracionTenant::obtener(ConfiguracionTenant::COMISION_PORCENTAJE, '0');
            $pedido->comision_calculada = round((float) $pedido->importe_cobro * $porcentaje / 100, 2);

            return;
        }

        $vendidos = VentaViajeConductor::where('id_conductor', $pedido->id_conductor)->sum('cantidad_viajes');
        $consumidos = Pedido::where('id_conductor', $pedido->id_conductor)
            ->where('prepago_descontado', true)
            ->count();

        if (($vendidos - $consumidos) > 0) {
            $pedido->prepago_descontado = true;
        }
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
     * @return array<string, mixed>
     */
    private function validarDatos(Request $request): array
    {
        $data = $request->validate([
            'id_cliente' => ['nullable', 'integer', 'exists:clientes,id_cliente'],
            'nombre_solicitante' => ['required', 'string', 'max:255'],
            'telefono_solicitante' => ['required', 'string', 'max:255'],
            'direccion_recogida' => ['required', 'string', 'max:255'],
            'direccion_entrega' => ['required', 'string', 'max:255'],
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
