<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\PaqueteViajeController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Tenant\AuthController as TenantAuthController;
use App\Http\Controllers\Tenant\ClienteController;
use App\Http\Controllers\Tenant\ConductorController;
use App\Http\Controllers\Tenant\ConductorVehiculoController;
use App\Http\Controllers\Tenant\DespachadorController;
use App\Http\Controllers\Tenant\DireccionClienteController;
use App\Http\Controllers\Tenant\PedidoController;
use App\Http\Controllers\Tenant\UsuarioController;
use App\Http\Controllers\Tenant\VehiculoController;
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

            Route::get('/despachadores', [DespachadorController::class, 'index']);
            Route::patch('/despachadores/{despachador}/estado', [DespachadorController::class, 'cambiarEstado']);

            Route::get('/conductores/usuarios-disponibles', [ConductorController::class, 'usuariosDisponibles']);
            Route::get('/conductores', [ConductorController::class, 'index']);
            Route::post('/conductores', [ConductorController::class, 'store']);
            Route::get('/conductores/{conductor}', [ConductorController::class, 'show']);
            Route::put('/conductores/{conductor}', [ConductorController::class, 'update']);

            Route::get('/vehiculos', [VehiculoController::class, 'index']);
            Route::post('/vehiculos', [VehiculoController::class, 'store']);
            Route::get('/vehiculos/{vehiculo}', [VehiculoController::class, 'show']);
            Route::put('/vehiculos/{vehiculo}', [VehiculoController::class, 'update']);

            Route::get('/conductor-vehiculo/disponibles', [ConductorVehiculoController::class, 'disponibles']);
            Route::get('/conductor-vehiculo', [ConductorVehiculoController::class, 'index']);
            Route::post('/conductor-vehiculo', [ConductorVehiculoController::class, 'store']);
            Route::patch('/conductor-vehiculo/{conductorVehiculo}/finalizar', [ConductorVehiculoController::class, 'finalizar']);

            Route::get('/clientes', [ClienteController::class, 'index']);
            Route::post('/clientes', [ClienteController::class, 'store']);
            Route::get('/clientes/{cliente}', [ClienteController::class, 'show']);
            Route::put('/clientes/{cliente}', [ClienteController::class, 'update']);
            Route::patch('/clientes/{cliente}/estado', [ClienteController::class, 'cambiarEstado']);

            Route::get('/clientes/{cliente}/direcciones', [DireccionClienteController::class, 'index']);
            Route::post('/clientes/{cliente}/direcciones', [DireccionClienteController::class, 'store']);
            Route::get('/clientes/{cliente}/direcciones/{direccion}', [DireccionClienteController::class, 'show']);
            Route::put('/clientes/{cliente}/direcciones/{direccion}', [DireccionClienteController::class, 'update']);
            Route::delete('/clientes/{cliente}/direcciones/{direccion}', [DireccionClienteController::class, 'destroy']);
        });

        Route::middleware(['throttle:tenant-usuarios', 'rol.tenant:AdminCliente,Despachador'])->group(function () {
            Route::get('/pedidos/recursos', [PedidoController::class, 'recursos']);
            Route::get('/pedidos', [PedidoController::class, 'index']);
            Route::post('/pedidos', [PedidoController::class, 'store']);
            Route::get('/pedidos/{pedido}', [PedidoController::class, 'show']);
            Route::put('/pedidos/{pedido}', [PedidoController::class, 'update']);
            Route::patch('/pedidos/{pedido}/estado', [PedidoController::class, 'cambiarEstado']);
        });
    });
});
