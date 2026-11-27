<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ClienteResource;
use App\Models\Tenant\Cliente;
use App\Services\Cliente\ConciliacionClientesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pestaña "Por revisar" del módulo de clientes (spec tenant/035, 6.8 y RN-18). Solo AdminCliente.
 */
class ConciliacionClienteController extends Controller
{
    public function __construct(private readonly ConciliacionClientesService $conciliacion) {}

    public function index(): JsonResponse
    {
        $pendientes = $this->conciliacion->pendientes()->map(fn (array $p) => [
            'telefono_e164' => $p['telefono_e164'],
            'detectado_en' => $p['detectado_en'],
            'clientes' => $p['clientes']->map(fn (Cliente $c) => [
                'id_cliente' => $c->id_cliente,
                'nombre' => $c->nombre,
                'telefono' => $c->telefono,
                'email_acceso' => $c->email_acceso,
                'origen' => $c->origen,
                'estado' => $c->estado,
                'pedidos' => (int) $c->pedidos_count,
                'ultimo_pedido_en' => $c->pedidos_max_created_at,
                'created_at' => $c->created_at,
            ])->values(),
        ]);

        return response()->json(['data' => $pendientes]);
    }

    public function unificar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telefono_e164' => ['required', 'string', 'regex:/^\+52\d{10}$/'],
            'id_principal' => ['required', 'integer'],
            'id_correo' => ['nullable', 'integer'],
        ]);

        $principal = $this->conciliacion->unificar(
            $data['telefono_e164'],
            (int) $data['id_principal'],
            isset($data['id_correo']) ? (int) $data['id_correo'] : null,
            $request->user('usuario')->id_usuario,
        );

        return response()->json(new ClienteResource($principal));
    }
}
