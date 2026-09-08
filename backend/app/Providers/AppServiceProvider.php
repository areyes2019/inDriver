<?php

namespace App\Providers;

use App\Models\Tenant\Usuario;
use App\Support\ContextoAmbiente;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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

        ResetPassword::createUrlUsing(function (CanResetPassword $notifiable, string $token) {
            $email = urlencode($notifiable->getEmailForPasswordReset());
            $frontendUrl = rtrim(config('app.frontend_url'), '/');

            // La tenencia sigue activa aquí: el envío del correo (forgotPassword) ocurre de forma
            // síncrona dentro de la misma petición que la inicializó.
            if ($notifiable instanceof Usuario) {
                return "{$frontendUrl}/t/".tenant('slug')."/reset-password/{$token}?email={$email}";
            }

            return "{$frontendUrl}/admin/reset-password/{$token}?email={$email}";
        });
    }
}
