<?php

namespace App\Providers;

use App\Models\Tenant\Usuario;
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
        //
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
