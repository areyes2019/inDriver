<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Conductor;

use App\Http\Controllers\Controller;
use App\Models\Tenant\ConductorDispositivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DispositivoController extends Controller
{
    /**
     * Registra el token FCM del dispositivo del conductor (spec tenant/018), para poder mandarle
     * push cuando el socket de Reverb está caído. Un solo registro por conductor: al iniciar
     * sesión en panda_express se sobreescribe el token anterior.
     */
    public function registrar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcm_token' => ['required', 'string', 'max:255'],
        ]);

        $conductor = $request->user('conductor-token')->conductor;

        ConductorDispositivo::updateOrCreate(
            ['id_conductor' => $conductor->id_conductor],
            ['fcm_token' => $data['fcm_token'], 'updated_at' => now()],
        );

        return response()->json(status: 204);
    }
}
