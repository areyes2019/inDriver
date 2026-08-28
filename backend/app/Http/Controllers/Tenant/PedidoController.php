<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\PedidoResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\Despachador;
use App\Models\Tenant\Pedido;
use App\Models\Tenant\Vehiculo;
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

    public function recursos(): JsonResponse
    {
        return response()->json([
            'clientes' => Cliente::query()->orderBy('nombre')->get(['id_cliente', 'nombre']),
            'despachadores' => Despachador::query()
                ->with('usuario:id_usuario,nombre,apellido_paterno')
                ->where('estado', 'Activo')
                ->get(['id_despachador', 'id_usuario'])
                ->map(fn (Despachador $despachador) => [
                    'id_despachador' => $despachador->id_despachador,
                    'nombre' => trim("{$despachador->usuario->nombre} {$despachador->usuario->apellido_paterno}"),
                ])
                ->values(),
            'conductores' => Conductor::query()
                ->with('usuario:id_usuario,nombre,apellido_paterno')
                ->where('estado', 'ACTIVO')
                ->get(['id_conductor', 'id_usuario'])
                ->map(fn (Conductor $conductor) => [
                    'id_conductor' => $conductor->id_conductor,
                    'nombre' => trim("{$conductor->usuario->nombre} {$conductor->usuario->apellido_paterno}"),
                ])
                ->values(),
            'vehiculos' => Vehiculo::query()
                ->where('estado', 'ACTIVO')
                ->orderBy('placa')
                ->get(['id_vehiculo', 'placa', 'marca', 'modelo']),
        ]);
    }

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
            'fecha_servicio' => ['required', 'date'],
            'lo_antes_posible' => ['sometimes', 'boolean'],
            'hora_desde' => ['nullable', 'date_format:H:i'],
            'hora_hasta' => ['nullable', 'date_format:H:i'],
            'modalidad_pago' => ['required', Rule::in([
                'RECEPTOR_PAGA_ENVIO',
                'REMITENTE_PAGA_ENVIO',
                'RECEPTOR_PAGA_ENVIO_PRODUCTOS',
            ])],
            'importe_envio' => ['nullable', 'numeric', 'min:0'],
            'importe_cobro' => ['nullable', 'numeric', 'min:0'],
            'id_despachador' => ['nullable', 'integer', 'exists:despachadores,id_despachador'],
            'id_conductor' => ['nullable', 'integer', 'exists:conductores,id_conductor'],
            'id_vehiculo' => ['nullable', 'integer', 'exists:vehiculos,id_vehiculo'],
        ]);

        $loAntesPosible = $request->boolean('lo_antes_posible');

        if (! $loAntesPosible) {
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
        $data['importe_envio'] = $data['importe_envio'] ?? 0;
        $data['importe_cobro'] = $data['importe_cobro'] ?? 0;

        return $data;
    }
}
