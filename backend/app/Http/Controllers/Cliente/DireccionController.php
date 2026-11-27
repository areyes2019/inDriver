<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cliente;

use App\Http\Controllers\Controller;
use App\Http\Resources\Cliente\DireccionAppResource;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\DireccionCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Direcciones favoritas del cliente en su app (spec tenant/035, RN-56). Se guardan en
 * `direcciones_clientes`, la misma tabla del Panel, así que el despachador también las ve.
 *
 * Todo se busca dentro de las direcciones del propio cliente: la de otro responde 404.
 */
class DireccionController extends Controller
{
    private const MAXIMO = 10;

    public function index(Request $request): AnonymousResourceCollection
    {
        return DireccionAppResource::collection(
            $this->cliente($request)->direcciones()
                ->whereNotNull('latitud')
                ->whereNotNull('longitud')
                ->orderBy('id_direccion')
                ->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $cliente = $this->cliente($request);

        if ($cliente->direcciones()->count() >= self::MAXIMO) {
            throw ValidationException::withMessages(['alias' => 'Puedes guardar hasta 10 direcciones.']);
        }

        $direccion = $cliente->direcciones()->create($this->datos($request));

        return response()->json(new DireccionAppResource($direccion), 201);
    }

    public function update(Request $request, int $direccion): JsonResponse
    {
        $registro = $this->propia($request, $direccion);
        $registro->update($this->datos($request));

        return response()->json(new DireccionAppResource($registro));
    }

    public function destroy(Request $request, int $direccion): JsonResponse
    {
        $this->propia($request, $direccion)->delete();

        return response()->json(status: 204);
    }

    private function cliente(Request $request): Cliente
    {
        return $request->user('cliente-token');
    }

    private function propia(Request $request, int $id): DireccionCliente
    {
        return $this->cliente($request)->direcciones()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(Request $request): array
    {
        $datos = $request->validate([
            'alias' => ['required', 'string', 'max:40'],
            'direccion' => ['required', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'referencias' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'alias' => trim($datos['alias']),
            'calle' => $datos['direccion'],
            'latitud' => $datos['lat'],
            'longitud' => $datos['lng'],
            'referencia' => $datos['referencias'] ?? null,
        ];
    }
}
