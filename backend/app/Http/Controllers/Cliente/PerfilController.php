<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cliente;

use App\Http\Controllers\Controller;
use App\Http\Resources\Cliente\ClienteAppResource;
use App\Services\Cliente\ConciliacionClientesService;
use App\Support\TelefonoMexicano;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** "Mi cuenta" (spec tenant/035, 6.7 y RN-57). */
class PerfilController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(new ClienteAppResource($request->user('cliente-token')));
    }

    /** Nombre y celular. El correo de acceso no se cambia desde la app. */
    public function update(Request $request, ConciliacionClientesService $conciliacion): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:255'],
            'telefono' => ['required', 'string', 'max:30'],
        ]);

        $telefono = TelefonoMexicano::nacional($data['telefono']);
        if ($telefono === null) {
            throw ValidationException::withMessages(['telefono' => 'Escribe los 10 dígitos de tu celular.']);
        }

        $cliente = $request->user('cliente-token');
        $cliente->update(['nombre' => $data['nombre'], 'telefono' => $telefono]);
        $conciliacion->asignarTelefono($cliente);

        return response()->json(new ClienteAppResource($cliente));
    }
}
