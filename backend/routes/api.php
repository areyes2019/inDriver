<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\PaqueteViajeController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Tenant\AuthController as TenantAuthController;
use App\Http\Controllers\Tenant\ClienteController;
use App\Http\Controllers\Tenant\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:admin-login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:admin')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::middleware('throttle:admin-tenants')->group(function () {
            Route::get('/tenants', [TenantController::class, 'index']);
            Route::post('/tenants', [TenantController::class, 'store']);
            Route::get('/tenants/{tenant}', [TenantController::class, 'show']);
            Route::put('/tenants/{tenant}', [TenantController::class, 'update']);
            Route::patch('/tenants/{tenant}/estado', [TenantController::class, 'cambiarEstado']);
            Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy']);

            Route::get('/paquetes-viajes', [PaqueteViajeController::class, 'index']);
            Route::post('/paquetes-viajes', [PaqueteViajeController::class, 'store']);
            Route::get('/paquetes-viajes/{paquete}', [PaqueteViajeController::class, 'show']);
            Route::put('/paquetes-viajes/{paquete}', [PaqueteViajeController::class, 'update']);
            Route::patch('/paquetes-viajes/{paquete}/estado', [PaqueteViajeController::class, 'cambiarEstado']);
            Route::delete('/paquetes-viajes/{paquete}', [PaqueteViajeController::class, 'destroy']);
        });
    });
});

Route::prefix('t/{slug}')->middleware('tenant.slug')->group(function () {
    Route::post('/login', [TenantAuthController::class, 'login'])->middleware('throttle:tenant-login');
    Route::post('/forgot-password', [TenantAuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [TenantAuthController::class, 'resetPassword']);

    Route::middleware('auth:usuario')->group(function () {
        Route::post('/logout', [TenantAuthController::class, 'logout']);
        Route::get('/me', [TenantAuthController::class, 'me']);

        Route::middleware(['throttle:tenant-usuarios', 'rol.tenant:AdminCliente'])->group(function () {
            Route::get('/usuarios', [UsuarioController::class, 'index']);
            Route::post('/usuarios', [UsuarioController::class, 'store']);
            Route::get('/usuarios/{id}', [UsuarioController::class, 'show']);
            Route::put('/usuarios/{id}', [UsuarioController::class, 'update']);
            Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy']);

            Route::get('/clientes', [ClienteController::class, 'index']);
            Route::post('/clientes', [ClienteController::class, 'store']);
            Route::get('/clientes/{cliente}', [ClienteController::class, 'show']);
            Route::put('/clientes/{cliente}', [ClienteController::class, 'update']);
            Route::patch('/clientes/{cliente}/estado', [ClienteController::class, 'cambiarEstado']);
        });
    });
});
