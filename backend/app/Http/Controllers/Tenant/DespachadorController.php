<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\DespachadorResource;
use App\Models\Tenant\Auditoria;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Despachador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DespachadorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->asegurarHabilitado();

        $query = Despachador::query()->with('usuario')->orderBy('id_despachador');

        if ($search = $request->query('search')) {
            $query->whereHas('usuario', function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('apellido_paterno', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return DespachadorResource::collection($query->paginate(15));
    }

    /**
     * Lista plana (sin paginar) de despachadores `Activo`, para poblar el selector de "despachador
     * responsable" al crear/editar un conductor (spec tenant/011) — mismo patrón que
     * `ConductorController::usuariosDisponibles`.
     */
    public function activos(): JsonResponse
    {
        $this->asegurarHabilitado();

        $despachadores = Despachador::query()
            ->with('usuario:id_usuario,nombre,apellido_paterno')
            ->where('estado', 'Activo')
            ->get(['id_despachador', 'id_usuario'])
            ->map(fn (Despachador $despachador) => [
                'id_despachador' => $despachador->id_despachador,
                'nombre' => "{$despachador->usuario->nombre} {$despachador->usuario->apellido_paterno}",
            ])
            ->values();

        return response()->json(['data' => $despachadores]);
    }

    public function cambiarEstado(Request $request, Despachador $despachador): JsonResponse
    {
        $this->asegurarHabilitado();

        $data = $request->validate([
            'estado' => ['required', Rule::in(['Activo', 'Suspendido', 'Inactivo'])],
        ]);

        $despachador->update($data);
        $despachador->load('usuario');

        Auditoria::create([
            'id_usuario' => $request->user('usuario')->id_usuario,
            'tabla_afectada' => 'despachadores',
            'accion' => 'CAMBIO_ESTADO',
            'descripcion' => "Cambio de estado del despachador {$despachador->usuario->nombre} {$despachador->usuario->apellido_paterno} a {$despachador->estado}",
        ]);

        return response()->json(new DespachadorResource($despachador));
    }

    private function asegurarHabilitado(): void
    {
        if (ConfiguracionTenant::obtener(ConfiguracionTenant::USAR_DESPACHADORES, 'No') !== 'Sí') {
            abort(403, 'La gestión de despachadores está deshabilitada para este tenant.');
        }
    }
}
