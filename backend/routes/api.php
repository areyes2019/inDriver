<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\PaqueteViajeController;
use App\Http\Controllers\Admin\TenantController;
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

            Route::get('/paquetes-viajes', [PaqueteViajeController::class, 'index']);
            Route::post('/paquetes-viajes', [PaqueteViajeController::class, 'store']);
            Route::get('/paquetes-viajes/{paquete}', [PaqueteViajeController::class, 'show']);
            Route::put('/paquetes-viajes/{paquete}', [PaqueteViajeController::class, 'update']);
            Route::patch('/paquetes-viajes/{paquete}/estado', [PaqueteViajeController::class, 'cambiarEstado']);
            Route::delete('/paquetes-viajes/{paquete}', [PaqueteViajeController::class, 'destroy']);
        });
    });
});
