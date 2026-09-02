<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\CiudadResource;
use App\Http\Resources\Tenant\UsuarioResource;
use App\Models\Tenant\Ciudad;
use App\Models\Tenant\Usuario;
use App\Models\Tenant\ZonaServicio;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $usuario = Usuario::where('email', $credentials['email'])->first();

        if (! $usuario || ! Hash::check($credentials['password'], $usuario->password) || $usuario->estado !== 'Activo') {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        Auth::guard('usuario')->login($usuario);
        $request->session()->regenerate();

        $usuario->forceFill(['ultimo_acceso' => now()])->save();

        return response()->json($this->respuestaUsuario($usuario));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('usuario')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(status: 204);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->respuestaUsuario($request->user('usuario')));
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::broker('usuarios')->sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'Si el correo existe, se envió un enlace de recuperación.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::broker('usuarios')->reset(
            $data,
            function (Usuario $usuario, string $password) {
                $usuario->forceFill(['password' => Hash::make($password)])->save();

                event(new PasswordReset($usuario));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => __($status)]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password_actual' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $usuario = $request->user('usuario');

        if (! Hash::check($data['password_actual'], $usuario->password)) {
            throw ValidationException::withMessages([
                'password_actual' => 'La contraseña actual no es correcta.',
            ]);
        }

        $usuario->forceFill(['password' => Hash::make($data['password'])])->save();

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }

    /**
     * Además de los datos propios del usuario (incluida `ciudades`, lo que este usuario tiene
     * asignado si es AdminCliente), agrega `ciudades_tenant`: la unión de las ciudades asignadas a
     * cualquier AdminCliente del tenant. El mapa del Panel (visible para AdminCliente y
     * Despachador) se centra con `ciudades_tenant`, no con `ciudades`, porque el Despachador nunca
     * tiene ciudades propias pero debe ver el mismo encuadre que su AdminCliente configuró.
     *
     * También agrega `cobertura_bounds`: el rectángulo que envuelve las zonas de cobertura
     * (`zonas_servicio`) activas del tenant, usado para acotar el autocompletado de direcciones de
     * pedidos al área de servicio configurada (spec `tenant/016-geocerca-area-servicio.md`).
     *
     * @return array<string, mixed>
     */
    private function respuestaUsuario(Usuario $usuario): array
    {
        $usuario->load('ciudades');
        $data = (new UsuarioResource($usuario))->resolve();

        $ciudadesTenant = Ciudad::whereHas('usuarios', fn ($query) => $query->where('rol', 'AdminCliente'))
            ->get();
        $data['ciudades_tenant'] = CiudadResource::collection($ciudadesTenant)->resolve();
        $data['cobertura_bounds'] = ZonaServicio::boundsDeZonasActivas();

        return $data;
    }
}
