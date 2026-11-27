<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cliente;

use App\Http\Controllers\Controller;
use App\Http\Resources\Cliente\ClienteAppResource;
use App\Models\Tenant\Cliente;
use App\Models\Tenant\ConfiguracionTenant;
use App\Services\Cliente\CodigoAccesoService;
use App\Services\Cliente\ConciliacionClientesService;
use App\Support\TelefonoMexicano;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Registro y entrada a la app de clientes con un código por correo (spec tenant/035, RN-10 a RN-15).
 */
class AccesoController extends Controller
{
    public function __construct(
        private readonly CodigoAccesoService $codigos,
        private readonly ConciliacionClientesService $conciliacion,
    ) {}

    /** `POST /cliente/codigo`. */
    public function codigo(Request $request): JsonResponse
    {
        if (! ConfiguracionTenant::appClientesPrendida()) {
            return response()->json(['motivo' => 'NO_DISPONIBLE'], 403);
        }

        $data = $request->validate([
            'email' => $this->reglasCorreo(),
            'modo' => ['required', Rule::in(['registro', 'entrar'])],
        ]);

        try {
            $resultado = $this->codigos->solicitar(
                $this->normalizarCorreo($data['email']),
                $data['modo'] === 'registro',
                (string) $request->ip(),
                tenant()->nombre_comercial,
            );
        } catch (Throwable $e) {
            // El correo no salió. Sin el código ni el correo en el log (RN-13).
            Log::error('No se pudo mandar el código de acceso de la app de clientes.', [
                'error' => $e::class,
            ]);

            return response()->json(['motivo' => 'NO_SE_PUDO_ENVIAR'], 503);
        }

        return match ($resultado) {
            CodigoAccesoService::NO_ENCONTRADO => response()->json(['motivo' => 'NO_ENCONTRADO'], 404),
            CodigoAccesoService::LIMITE => response()->json(['motivo' => 'LIMITE'], 429),
            default => response()->json(['estado' => 'ENVIADO']),
        };
    }

    /** `POST /cliente/verificar`. En el registro llegan también nombre y celular (6.1). */
    public function verificar(Request $request): JsonResponse
    {
        if (! ConfiguracionTenant::appClientesPrendida()) {
            return response()->json(['motivo' => 'NO_DISPONIBLE'], 403);
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'max:191'],
            'codigo' => ['required', 'string', 'regex:/^\d{6}$/'],
            'nombre' => ['nullable', 'string', 'min:3', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
        ]);

        $email = $this->normalizarCorreo($data['email']);
        $resultado = $this->codigos->verificar($email, $data['codigo']);

        if ($resultado === CodigoAccesoService::BLOQUEADO) {
            return response()->json(['motivo' => 'LIMITE'], 429);
        }
        if ($resultado !== CodigoAccesoService::CORRECTO) {
            return response()->json(['motivo' => $resultado], 422);
        }

        $cliente = Cliente::where('email_acceso', $email)->first();
        $nuevo = $cliente === null;

        if ($nuevo) {
            $cliente = $this->registrar($email, $data);
        }

        if ($cliente->estado !== 'Activo') {
            return response()->json([
                'motivo' => 'CLIENTE_INACTIVO',
                'telefono_contacto' => tenant()->telefono,
            ], 403);
        }

        return response()->json([
            'token' => $cliente->createToken('pide', [Cliente::HABILIDAD_APP])->plainTextToken,
            'cliente' => new ClienteAppResource($cliente),
            'nuevo' => $nuevo,
        ]);
    }

    /** `POST /cliente/salir`. */
    public function salir(Request $request): JsonResponse
    {
        $request->user('cliente-token')->currentAccessToken()->delete();

        return response()->json(status: 204);
    }

    /**
     * RN-15: cliente nuevo con su correo verificado. Nunca se une solo a un cliente existente, aunque
     * el teléfono coincida (D23): si coincide, el teléfono pasa a revisión.
     *
     * @param  array<string, mixed>  $data
     */
    private function registrar(string $email, array $data): Cliente
    {
        $telefono = TelefonoMexicano::nacional($data['telefono'] ?? null);

        if (empty($data['nombre']) || $telefono === null) {
            throw ValidationException::withMessages([
                'nombre' => 'Para crear tu cuenta escribe tu nombre y tu celular.',
            ]);
        }

        try {
            $cliente = DB::transaction(function () use ($email, $data, $telefono) {
                $cliente = Cliente::create([
                    'nombre' => $data['nombre'],
                    'telefono' => $telefono,
                    'estado' => 'Activo',
                    'referencia' => 'Registrado en la app',
                ]);
                $cliente->forceFill([
                    'email_acceso' => $email,
                    'email_verificado_en' => now(),
                    'origen' => Cliente::ORIGEN_APP,
                ])->save();

                return $cliente;
            });
        } catch (UniqueConstraintViolationException) {
            // Dos verificaciones simultáneas del mismo correo: gana la primera.
            return Cliente::where('email_acceso', $email)->firstOrFail();
        }

        $this->conciliacion->asignarTelefono($cliente);

        return $cliente;
    }

    /**
     * RN-10: formato válido y, fuera de pruebas, un dominio que reciba correo.
     *
     * @return list<string>
     */
    private function reglasCorreo(): array
    {
        $formato = config('pide.codigo.verificar_dominio') ? 'email:rfc,dns' : 'email:rfc';

        return ['required', 'string', 'max:191', $formato];
    }

    private function normalizarCorreo(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
