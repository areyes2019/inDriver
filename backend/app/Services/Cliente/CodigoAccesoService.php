<?php

declare(strict_types=1);

namespace App\Services\Cliente;

use App\Models\Tenant\Cliente;
use App\Models\Tenant\ClienteCodigo;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;

/**
 * Códigos de acceso a la app de clientes (spec tenant/035, RN-10 a RN-13).
 *
 * - El código es de 6 dígitos, dura 10 minutos, es de un solo uso y pedir otro invalida el anterior.
 * - Se guarda como HMAC con una llave derivada de `APP_KEY`; nunca en claro ni en logs.
 * - Los límites usan el `RateLimiter` de la aplicación, el mismo de `throttle`. Se resuelve en el
 *   arranque (`RateLimiter::for` en `AppServiceProvider`), antes de cualquier tenancy, así que su
 *   caché es la central y sin etiqueta por tenant: el tope diario es uno para todo el sistema. Las
 *   llaves por correo, IP y flotilla llevan el id del tenant.
 */
class CodigoAccesoService
{
    public const ENVIADO = 'ENVIADO';

    public const NO_ENCONTRADO = 'NO_ENCONTRADO';

    public const LIMITE = 'LIMITE';

    public const CORRECTO = 'CORRECTO';

    public const INCORRECTO = 'CODIGO_INCORRECTO';

    public const VENCIDO = 'CODIGO_VENCIDO';

    public const BLOQUEADO = 'BLOQUEADO';

    public function __construct(private readonly CanalCodigo $canal) {}

    /**
     * Manda un código. En "entrar" (`$registro = false`) solo si el correo ya es de un cliente
     * (RN-15): a un correo desconocido no se le manda nada.
     */
    public function solicitar(string $email, bool $registro, string $ip, string $nombreFlotilla): string
    {
        if (! $registro && ! Cliente::where('email_acceso', $email)->exists()) {
            return self::NO_ENCONTRADO;
        }

        $limites = $this->limitesDeEnvio($email, $ip);
        if ($this->bloqueado($email) || $this->alguno($limites)) {
            return self::LIMITE;
        }

        $codigo = $this->generar();

        DB::transaction(function () use ($email, $codigo, $ip) {
            ClienteCodigo::where('email', $email)
                ->whereNull('usado_en')
                ->where('expira_en', '>', now())
                ->update(['expira_en' => now()]);

            ClienteCodigo::create([
                'email' => $email,
                'codigo_hmac' => $this->hmac($email, $codigo),
                'expira_en' => now()->addMinutes((int) config('pide.codigo.vigencia_minutos')),
                'ip' => $ip,
            ]);
        });

        $this->canal->enviar($email, $codigo, $nombreFlotilla);

        foreach ($limites as [$llave, , $segundos]) {
            $this->limitador()->hit($llave, $segundos);
        }

        return self::ENVIADO;
    }

    /** Revisa el código. Un acierto lo consume; cinco fallos lo invalidan (RN-11, RN-12). */
    public function verificar(string $email, string $codigo): string
    {
        if ($this->bloqueado($email)) {
            return self::BLOQUEADO;
        }

        $registro = ClienteCodigo::where('email', $email)->latest('id_codigo')->first();

        if ($registro === null || $registro->usado_en !== null) {
            $this->fallo($email);

            return self::INCORRECTO;
        }

        if (! $registro->vigente() || $registro->intentos >= (int) config('pide.codigo.intentos_por_codigo')) {
            return self::VENCIDO;
        }

        if (! hash_equals($registro->codigo_hmac, $this->hmac($email, $codigo))) {
            $registro->increment('intentos');
            $this->fallo($email);

            return self::INCORRECTO;
        }

        // Un solo uso aunque lleguen dos verificaciones a la vez.
        $consumido = ClienteCodigo::whereKey($registro->id_codigo)
            ->whereNull('usado_en')
            ->update(['usado_en' => now()]);

        if ($consumido === 0) {
            return self::INCORRECTO;
        }

        $this->limitador()->clear($this->llave('fallos', $email));

        return self::CORRECTO;
    }

    private function generar(): string
    {
        $digitos = (int) config('pide.codigo.digitos');

        return str_pad((string) random_int(0, (10 ** $digitos) - 1), $digitos, '0', STR_PAD_LEFT);
    }

    private function hmac(string $email, string $codigo): string
    {
        $llave = hash_hmac('sha256', 'pide-codigo-acceso', (string) config('app.key'));

        return hash_hmac('sha256', tenant()?->getTenantKey().'|'.$email.'|'.$codigo, $llave);
    }

    /**
     * RN-12. Cada límite: [llave, máximo, segundos].
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function limitesDeEnvio(string $email, string $ip): array
    {
        $l = config('pide.codigo.limites');

        return [
            [$this->llave('minuto', $email), (int) $l['por_minuto_correo'], 60],
            [$this->llave('hora', $email), (int) $l['por_hora_correo'], 3600],
            [$this->llave('dia', $email), (int) $l['por_dia_correo'], 86400],
            [$this->llave('ip', $ip), (int) $l['por_hora_ip'], 3600],
            [$this->llave('flotilla', ''), (int) $l['por_hora_flotilla'], 3600],
            ['pide:codigo:global:dia', (int) $l['por_dia_global'], 86400],
        ];
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int}>  $limites
     */
    private function alguno(array $limites): bool
    {
        foreach ($limites as [$llave, $maximo]) {
            if ($this->limitador()->tooManyAttempts($llave, $maximo)) {
                return true;
            }
        }

        return false;
    }

    private function bloqueado(string $email): bool
    {
        return $this->limitador()->tooManyAttempts(
            $this->llave('fallos', $email),
            (int) config('pide.codigo.limites.fallos_por_hora'),
        );
    }

    private function fallo(string $email): void
    {
        $this->limitador()->hit($this->llave('fallos', $email), 3600);
    }

    /** El correo va con hash: la caché no guarda datos personales. */
    private function llave(string $tipo, string $valor): string
    {
        return sprintf('pide:codigo:%s:%s:%s', tenant()?->getTenantKey(), $tipo, sha1($valor));
    }

    private function limitador(): RateLimiter
    {
        return app(RateLimiter::class);
    }
}
