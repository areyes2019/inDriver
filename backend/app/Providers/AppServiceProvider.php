<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Models\Tenant\Usuario;
use App\Services\Cliente\CanalCodigo;
use App\Services\Cliente\CanalCodigoCorreo;
use App\Services\Cliente\CanalCodigoLog;
use App\Support\ContextoAmbiente;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Ambiente TEST/LIVE de la petición en curso (spec tenant/025). Singleton para que el
        // middleware del Panel y el global scope de `Pedido` hablen de la misma instancia; nace
        // vacío, y vacío significa "no filtres nada".
        $this->app->singleton(ContextoAmbiente::class);

        // spec tenant/031, etapa 5. Un timeout corto y un solo reintento caben en el job de
        // WhatsApp; si la IA no contesta, el cliente recibe una pregunta en lugar de silencio (RN-24).
        $this->app->singleton(AnthropicClient::class, fn () => new AnthropicClient(
            apiKey: (string) config('services.anthropic.api_key'),
            requestOptions: ['timeout' => 15.0, 'maxRetries' => 1],
        ));

        // spec tenant/035, D6: el código de acceso sale por correo. `log` es solo para desarrollo.
        $this->app->bind(CanalCodigo::class, fn () => config('pide.codigo.canal') === 'log'
            ? new CanalCodigoLog
            : new CanalCodigoCorreo);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // spec tenant/035, RN-13: un código escrito en el log de producción es una puerta abierta.
        if (config('pide.codigo.canal') === 'log' && ! $this->app->environment(['local', 'testing'])) {
            throw new RuntimeException('OTP_CANAL=log solo se permite en desarrollo local.');
        }

        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinute(5)->by($request->string('email').'|'.$request->ip());
        });

        RateLimiter::for('admin-tenants', function (Request $request) {
            return Limit::perMinute(20)->by($request->user('admin')?->id_admin ?? $request->ip());
        });

        RateLimiter::for('tenant-login', function (Request $request) {
            return Limit::perMinute(5)->by($request->string('email').'|'.$request->ip());
        });

        RateLimiter::for('tenant-usuarios', function (Request $request) {
            return Limit::perMinute(20)->by($request->user('usuario')?->id_usuario ?? $request->ip());
        });

        // Lecturas del `/panel` (spec tenant/027). Separado de `tenant-usuarios` porque no es lo
        // mismo una persona tecleando un alta que una pantalla que se reconcilia sola: con el
        // bucket de 20/min compartido, un envío que se tomaba y se entregaba agotaba el cupo y los
        // dos paneles laterales quedaban en "No se pudo cargar".
        //
        // Con la 027 el Panel gasta dos peticiones al abrir y prácticamente ninguna después —el
        // resto llega por el canal—, así que 120 es techo de seguridad, no presupuesto de uso.
        RateLimiter::for('tenant-panel-lectura', function (Request $request) {
            return Limit::perMinute(120)->by($request->user('usuario')?->id_usuario ?? $request->ip());
        });

        // App de clientes (spec tenant/035). Las rutas públicas (marca, código, verificar) van por IP;
        // los límites finos del código (por correo, flotilla y global) los aplica
        // `CodigoAccesoService`. Las rutas con sesión, por cliente.
        RateLimiter::for('cliente-publico', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Dirección de un punto y cotización: cada una es una consulta pagada a Google (RN-48).
        RateLimiter::for('cliente-google', function (Request $request) {
            return Limit::perMinute(20)->by('cliente-google:'.($request->user('cliente-token')?->id_cliente ?? $request->ip()));
        });

        RateLimiter::for('cliente-app', function (Request $request) {
            return Limit::perMinute(120)->by('cliente:'.($request->user('cliente-token')?->id_cliente ?? $request->ip()));
        });

        ResetPassword::createUrlUsing(function (CanResetPassword $notifiable, string $token) {
            $email = urlencode($notifiable->getEmailForPasswordReset());
            $frontendUrl = rtrim(config('app.frontend_url'), '/');

            // La tenencia sigue activa aquí: el envío del correo (forgotPassword) ocurre de forma
            // síncrona dentro de la misma petición que la inicializó. La ruta va sin `/t/` desde
            // la enmienda "el slug va inmediatamente después del dominio" (spec tenant/001, RN-S6).
            if ($notifiable instanceof Usuario) {
                return "{$frontendUrl}/".tenant('slug')."/reset-password/{$token}?email={$email}";
            }

            return "{$frontendUrl}/admin/reset-password/{$token}?email={$email}";
        });
    }
}
