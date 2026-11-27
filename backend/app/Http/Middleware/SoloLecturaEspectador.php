<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modo espectador de ADMIN_CENTRAL (ver `Admin\EspectadorController`). Va en el grupo
 * `auth:usuario` del Panel y solo actúa si la sesión trae la marca de espectador:
 *
 * - Deja pasar las lecturas (GET/HEAD), la autorización de canales de tiempo real y el logout.
 * - Rechaza cualquier otra petición con 403: el espectador mira, no mueve nada.
 * - La sesión de espectador vale solo para su tenant y solo mientras el admin central siga con
 *   sesión; si no, se cierra la sesión del Panel y responde 401.
 */
class SoloLecturaEspectador
{
    public const SESION = 'espectador';

    public const CODIGO = 'ESPECTADOR_SOLO_LECTURA';

    /** Rutas de escritura que el espectador sí necesita: el canal en vivo y salir. */
    private const PERMITIDAS = ['broadcasting/auth', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $espectador = $request->session()->get(self::SESION);

        if (! $espectador) {
            return $next($request);
        }

        // `admins_centrales` vive en la base central, y aquí la tenencia ya está inicializada: sin
        // `central()` el guard buscaría al admin en la base del tenant y la petición reventaría.
        $adminConSesion = (bool) tenancy()->central(fn () => Auth::guard('admin')->check());

        if (! $adminConSesion || ($espectador['id_tenant'] ?? null) !== tenant()?->id_tenant) {
            Auth::guard('usuario')->logout();
            $request->session()->forget(self::SESION);

            return response()->json(['message' => 'La sesión de espectador terminó.'], 401);
        }

        if ($request->isMethodSafe() || $this->esPermitida($request)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Estás en modo espectador: solo puedes mirar.',
            'code' => self::CODIGO,
        ], 403);
    }

    private function esPermitida(Request $request): bool
    {
        foreach (self::PERMITIDAS as $sufijo) {
            if (str_ends_with($request->path(), $sufijo)) {
                return true;
            }
        }

        return false;
    }
}
