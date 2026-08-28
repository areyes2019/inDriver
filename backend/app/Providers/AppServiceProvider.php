<?php

namespace App\Providers;

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

        ResetPassword::createUrlUsing(function (CanResetPassword $notifiable, string $token) {
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return rtrim(config('app.frontend_url'), '/')."/admin/reset-password/{$token}?email={$email}";
        });
    }
}
