<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Conductor;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\UsuarioResource;
use App\Models\Tenant\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login de la app de conductor (panda_express), por token de Sanctum en vez de sesión (spec
     * tenant/013) — solo emite token a un `Usuario` con `rol = 'Conductor'`.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $usuario = Usuario::where('email', $credentials['email'])
            ->where('rol', 'Conductor')
            ->first();

        if (! $usuario || ! Hash::check($credentials['password'], $usuario->password) || $usuario->estado !== 'Activo') {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $usuario->forceFill(['ultimo_acceso' => now()])->save();

        return response()->json([
            'token' => $usuario->createToken('panda-express')->plainTextToken,
            'usuario' => (new UsuarioResource($usuario))->resolve(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user('conductor-token')->currentAccessToken()->delete();

        return response()->json(status: 204);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(new UsuarioResource($request->user('conductor-token')));
    }
}
