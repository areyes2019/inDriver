<?php

use App\Http\Controllers\Admin\AdminClienteCiudadController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CreditoPaqueteController;
use App\Http\Controllers\Admin\PaqueteViajeController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Tenant\AuthController as TenantAuthController;
use App\Http\Controllers\Tenant\ClienteController;
use App\Http\Controllers\Tenant\Conductor\AuthController as ConductorAuthController;
use App\Http\Controllers\Tenant\Conductor\DispositivoController as ConductorDispositivoController;
use App\Http\Controllers\Tenant\Conductor\EstadoController as ConductorEstadoController;
use App\Http\Controllers\Tenant\Conductor\PedidoController as ConductorPedidoController;
use App\Http\Controllers\Tenant\Conductor\SaldoController as ConductorSaldoController;
use App\Http\Controllers\Tenant\Conductor\SyncController as ConductorSyncController;
use App\Http\Controllers\Tenant\Conductor\UbicacionController as ConductorUbicacionController;
use App\Http\Controllers\Tenant\ConductorController;
use App\Http\Controllers\Tenant\ConfiguracionController;
use App\Http\Controllers\Tenant\DespachadorController;
use App\Http\Controllers\Tenant\DireccionClienteController;
use App\Http\Controllers\Tenant\PedidoController;
use App\Http\Controllers\Tenant\UsuarioController;
use App\Http\Controllers\Tenant\VentaViajeConductorController;
use App\Http\Controllers\Tenant\ZonaCoberturaController;
use Illuminate\Broadcasting\BroadcastController;
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

            Route::post('/tenants/{tenant}/creditos-paquetes', [CreditoPaqueteController::class, 'store']);

            Route::get('/tenants/{tenant}/admins-cliente', [AdminClienteCiudadController::class, 'index']);
            Route::put('/tenants/{tenant}/admins-cliente/{idUsuario}/ciudades', [AdminClienteCiudadController::class, 'update']);
        });
    });
});

Route::prefix('t/{slug}')->middleware('tenant.slug')->group(function () {
    Route::post('/login', [TenantAuthController::class, 'login'])->middleware('throttle:tenant-login');
    Route::post('/forgot-password', [TenantAuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [TenantAuthController::class, 'resetPassword']);

    // App de conductor (panda_express, spec tenant/013): token de Sanctum, guard propio
    // "conductor-token", separado del guard de sesión "usuario" que usa el resto del panel.
    Route::post('/conductor/login', [ConductorAuthController::class, 'login'])
        ->middleware('throttle:tenant-login');

    Route::middleware('auth:usuario')->group(function () {
        Route::post('/logout', [TenantAuthController::class, 'logout']);
        Route::get('/me', [TenantAuthController::class, 'me']);
        Route::middleware('throttle:tenant-usuarios')->post('/cambiar-password', [TenantAuthController::class, 'changePassword']);

        // Autenticación del canal privado de tiempo real (Reverb, spec tenant/018) para el Panel
        // (AdminCliente/Despachador) — separada de la ruta homónima del conductor porque cada una
        // corre bajo un guard distinto ("usuario" por sesión aquí, "conductor-token" allá).
        Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate']);

        // Registrada antes que GET /conductores/{conductor} (ambas empiezan con /conductores/...)
        // para que "activos" no se interprete como un id de conductor. Va en el grupo de
        // AdminCliente+Despachador (mismo que /pedidos), no en el de AdminCliente exclusivo donde
        // vive el resto de ConductorController.
        Route::middleware(['throttle:tenant-usuarios', 'rol.tenant:AdminCliente,Despachador'])
            ->get('/conductores/activos', [ConductorController::class, 'activos']);

        Route::middleware(['throttle:tenant-usuarios', 'rol.tenant:AdminCliente'])->group(function () {
            Route::get('/usuarios', [UsuarioController::class, 'index']);
            Route::post('/usuarios', [UsuarioController::class, 'store']);
            Route::get('/usuarios/{id}', [UsuarioController::class, 'show']);
            Route::put('/usuarios/{id}', [UsuarioController::class, 'update']);
            Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy']);

            Route::get('/despachadores/activos', [DespachadorController::class, 'activos']);
            Route::get('/despachadores', [DespachadorController::class, 'index']);
            Route::patch('/despachadores/{despachador}/estado', [DespachadorController::class, 'cambiarEstado']);

            Route::get('/conductores/usuarios-disponibles', [ConductorController::class, 'usuariosDisponibles']);
            Route::get('/conductores', [ConductorController::class, 'index']);
            Route::post('/conductores', [ConductorController::class, 'store']);
            Route::get('/conductores/{conductor}', [ConductorController::class, 'show']);
            Route::put('/conductores/{conductor}', [ConductorController::class, 'update']);
            Route::get('/conductores/{conductor}/saldo-viajes', [ConductorController::class, 'saldoViajes']);
            Route::post('/conductores/{conductor}/vender-viajes', [VentaViajeConductorController::class, 'store']);
            Route::get('/conductores/{conductor}/historial-pagos', [VentaViajeConductorController::class, 'historialConductor']);
            Route::get('/reportes/pagos-conductores', [VentaViajeConductorController::class, 'reportePagos']);

            Route::post('/clientes', [ClienteController::class, 'store']);
            Route::put('/clientes/{cliente}', [ClienteController::class, 'update']);
            Route::patch('/clientes/{cliente}/estado', [ClienteController::class, 'cambiarEstado']);

            Route::post('/clientes/{cliente}/direcciones', [DireccionClienteController::class, 'store']);
            Route::put('/clientes/{cliente}/direcciones/{direccion}', [DireccionClienteController::class, 'update']);
            Route::delete('/clientes/{cliente}/direcciones/{direccion}', [DireccionClienteController::class, 'destroy']);

            Route::put('/configuracion', [ConfiguracionController::class, 'update']);

            Route::get('/zonas-cobertura', [ZonaCoberturaController::class, 'index']);
            Route::post('/zonas-cobertura', [ZonaCoberturaController::class, 'store']);
            Route::get('/zonas-cobertura/{zona}', [ZonaCoberturaController::class, 'show']);
            Route::put('/zonas-cobertura/{zona}', [ZonaCoberturaController::class, 'update']);
            Route::patch('/zonas-cobertura/{zona}/estado', [ZonaCoberturaController::class, 'cambiarEstado']);
            Route::delete('/zonas-cobertura/{zona}', [ZonaCoberturaController::class, 'destroy']);
        });

        Route::middleware(['throttle:tenant-usuarios', 'rol.tenant:AdminCliente,Despachador'])->group(function () {
            Route::post('/pedidos', [PedidoController::class, 'store']);
            Route::get('/pedidos', [PedidoController::class, 'index']);
            Route::get('/pedidos/{pedido}', [PedidoController::class, 'show']);
            Route::put('/pedidos/{pedido}', [PedidoController::class, 'update']);
            Route::patch('/pedidos/{pedido}/estado', [PedidoController::class, 'cambiarEstado']);

            Route::get('/clientes', [ClienteController::class, 'index']);
            Route::get('/clientes/{cliente}', [ClienteController::class, 'show']);
            Route::get('/clientes/{cliente}/direcciones', [DireccionClienteController::class, 'index']);
            Route::get('/clientes/{cliente}/direcciones/{direccion}', [DireccionClienteController::class, 'show']);

            Route::get('/configuracion', [ConfiguracionController::class, 'show']);
        });
    });

    Route::prefix('conductor')->middleware('auth:conductor-token')->group(function () {
        Route::post('/logout', [ConductorAuthController::class, 'logout']);
        Route::get('/me', [ConductorAuthController::class, 'me']);

        Route::post('/estado', [ConductorEstadoController::class, 'actualizar']);
        Route::post('/ubicacion', [ConductorUbicacionController::class, 'actualizar']);

        Route::get('/pedidos/disponibles', [ConductorPedidoController::class, 'disponibles']);
        Route::get('/pedidos/activo', [ConductorPedidoController::class, 'activo']);
        Route::post('/pedidos/{pedido}/aceptar', [ConductorPedidoController::class, 'aceptar']);
        Route::post('/pedidos/{pedido}/estado', [ConductorPedidoController::class, 'cambiarEstado']);
        Route::post('/pedidos/{pedido}/cancelar', [ConductorPedidoController::class, 'cancelar']);

        Route::get('/saldo-viajes', [ConductorSaldoController::class, 'show']);

        // Registro del token de push (FCM, spec tenant/018) y endpoint de sincronización al
        // reconectar (RN-02/RN-07): junta pedido activo + pool + saldo en una sola respuesta.
        Route::post('/dispositivo', [ConductorDispositivoController::class, 'registrar']);
        Route::get('/sync', [ConductorSyncController::class, 'show']);

        // Autenticación del canal privado de tiempo real (Reverb, spec tenant/013), scoped al
        // tenant y al guard del conductor — el /broadcasting/auth global (guard "web") no aplica.
        Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate']);
    });
});
