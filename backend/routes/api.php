<?php

use App\Http\Controllers\Admin\AdminClienteCiudadController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ConductorTenantController;
use App\Http\Controllers\Admin\CreditoPaqueteController;
use App\Http\Controllers\Admin\EspectadorController;
use App\Http\Controllers\Admin\PaqueteViajeController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Cliente\AccesoController as ClienteAccesoController;
use App\Http\Controllers\Cliente\CotizacionController as ClienteCotizacionController;
use App\Http\Controllers\Cliente\DireccionController as ClienteDireccionController;
use App\Http\Controllers\Cliente\ManifiestoController as ClienteManifiestoController;
use App\Http\Controllers\Cliente\MarcaArchivoController as ClienteMarcaArchivoController;
use App\Http\Controllers\Cliente\MarcaController as ClienteMarcaController;
use App\Http\Controllers\Cliente\PedidoController as ClientePedidoController;
use App\Http\Controllers\Cliente\PerfilController as ClientePerfilController;
use App\Http\Controllers\Tenant\AuthController as TenantAuthController;
use App\Http\Controllers\Tenant\ClienteController;
use App\Http\Controllers\Tenant\ComisionController;
use App\Http\Controllers\Tenant\ConciliacionClienteController;
use App\Http\Controllers\Tenant\Conductor\AuthController as ConductorAuthController;
use App\Http\Controllers\Tenant\Conductor\DispositivoController as ConductorDispositivoController;
use App\Http\Controllers\Tenant\Conductor\EstadoController as ConductorEstadoController;
use App\Http\Controllers\Tenant\Conductor\GpsTokenController as ConductorGpsTokenController;
use App\Http\Controllers\Tenant\Conductor\PedidoController as ConductorPedidoController;
use App\Http\Controllers\Tenant\Conductor\SaldoController as ConductorSaldoController;
use App\Http\Controllers\Tenant\Conductor\SyncController as ConductorSyncController;
use App\Http\Controllers\Tenant\Conductor\UbicacionController as ConductorUbicacionController;
use App\Http\Controllers\Tenant\ConductorController;
use App\Http\Controllers\Tenant\ConfiguracionController;
use App\Http\Controllers\Tenant\DespachadorController;
use App\Http\Controllers\Tenant\DireccionClienteController;
use App\Http\Controllers\Tenant\MarcaAppController;
use App\Http\Controllers\Tenant\PedidoController;
use App\Http\Controllers\Tenant\SaldoConductorController;
use App\Http\Controllers\Tenant\UsuarioController;
use App\Http\Controllers\Tenant\VentaViajeConductorController;
use App\Http\Controllers\Tenant\WhatsappBandejaController;
use App\Http\Controllers\Tenant\ZonaCoberturaController;
use App\Http\Controllers\WhatsappWebhookController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

// spec tenant/031: una sola URL para todas las flotillas; el tenant sale del número. Sin `tenant.slug`
// ni sesión: lo que la protege es la firma de Meta.
Route::get('/webhooks/whatsapp', [WhatsappWebhookController::class, 'verificar']);
Route::post('/webhooks/whatsapp', [WhatsappWebhookController::class, 'recibir']);

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

            Route::get('/tenants/{tenant}/conductores', [ConductorTenantController::class, 'index']);
            Route::post('/tenants/{tenant}/conductores', [ConductorTenantController::class, 'store']);
            Route::post('/tenants/{tenant}/conductores/{idConductor}/vender-viajes', [ConductorTenantController::class, 'venderViajes'])
                ->whereNumber('idConductor');

            Route::post('/tenants/{tenant}/espectador', [EspectadorController::class, 'store']);
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

    Route::middleware(['auth:usuario', 'espectador'])->group(function () {
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
        // Las dos lecturas que alimentan el `/panel` van en `tenant-panel-lectura` y no en
        // `tenant-usuarios` (spec tenant/027): el bucket de 20/min está pensado para escrituras de
        // CRUD hechas a mano, y una pantalla de operación que se reconstruye sola no cabe ahí.
        Route::middleware(['throttle:tenant-panel-lectura', 'rol.tenant:AdminCliente,Despachador'])
            ->group(function () {
                Route::get('/conductores/activos', [ConductorController::class, 'activos']);
                // Antes que GET /pedidos/{pedido}, o "en-turno" se tomaría por un id.
                Route::get('/pedidos/en-turno', [PedidoController::class, 'enTurno']);

                // Bandeja de WhatsApp (spec tenant/031, etapa 7b): se relee sola con cada aviso del
                // socket, igual que el Panel, así que va en el mismo bucket de lectura.
                Route::get('/whatsapp/conversaciones', [WhatsappBandejaController::class, 'index']);
                Route::get('/whatsapp/conversaciones/esperando', [WhatsappBandejaController::class, 'esperando']);
                Route::get('/whatsapp/conversaciones/{conversacion}', [WhatsappBandejaController::class, 'show']);
            });

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

            Route::post('/conductores/{conductor}/saldo', [SaldoConductorController::class, 'store']);
            Route::get('/conductores/{conductor}/saldo', [SaldoConductorController::class, 'historial']);

            // Comisiones de conductores (spec tenant/030).
            Route::get('/comisiones/resumen', [ComisionController::class, 'resumen']);
            Route::get('/conductores/{conductor}/comisiones', [ComisionController::class, 'conductor']);
            Route::post('/conductores/{conductor}/liquidaciones', [ComisionController::class, 'liquidar']);

            Route::post('/clientes', [ClienteController::class, 'store']);
            Route::put('/clientes/{cliente}', [ClienteController::class, 'update']);
            Route::patch('/clientes/{cliente}/estado', [ClienteController::class, 'cambiarEstado']);

            // spec tenant/035, 6.8: teléfonos repetidos. Se registra antes que GET /clientes/{cliente}
            // (grupo de abajo) para que "por-revisar" no se tome por un id.
            Route::get('/clientes/por-revisar', [ConciliacionClienteController::class, 'index']);
            Route::post('/clientes/unificar', [ConciliacionClienteController::class, 'unificar']);

            Route::post('/clientes/{cliente}/direcciones', [DireccionClienteController::class, 'store']);
            Route::put('/clientes/{cliente}/direcciones/{direccion}', [DireccionClienteController::class, 'update']);
            Route::delete('/clientes/{cliente}/direcciones/{direccion}', [DireccionClienteController::class, 'destroy']);

            Route::put('/configuracion', [ConfiguracionController::class, 'update']);
            // spec tenant/034, D2: el modo automático, solo AdminCliente.
            Route::put('/configuracion/modo-automatico', [ConfiguracionController::class, 'modoAutomatico']);
            // spec tenant/035, D17: la app de clientes, solo AdminCliente.
            Route::put('/configuracion/app-clientes', [ConfiguracionController::class, 'appClientes']);
            // spec tenant/035, RN-60 y RN-61: logo y banner de la app de clientes.
            Route::post('/configuracion/app-clientes/logo', [MarcaAppController::class, 'subirLogo']);
            Route::delete('/configuracion/app-clientes/logo', [MarcaAppController::class, 'quitarLogo']);
            Route::post('/configuracion/app-clientes/banner', [MarcaAppController::class, 'subirBanner']);
            Route::delete('/configuracion/app-clientes/banner', [MarcaAppController::class, 'quitarBanner']);
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
            Route::get('/pedidos/{pedido}/recorrido', [PedidoController::class, 'recorrido']);
            Route::post('/pedidos/{pedido}/reubicacion/cotizar', [PedidoController::class, 'cotizarReubicacion']);
            Route::patch('/pedidos/{pedido}/destino', [PedidoController::class, 'aplicarReubicacion']);

            Route::get('/clientes', [ClienteController::class, 'index']);
            Route::get('/clientes/{cliente}', [ClienteController::class, 'show']);
            Route::get('/clientes/{cliente}/direcciones', [DireccionClienteController::class, 'index']);
            Route::get('/clientes/{cliente}/direcciones/{direccion}', [DireccionClienteController::class, 'show']);

            Route::get('/configuracion', [ConfiguracionController::class, 'show']);
            Route::get('/automatico/bitacora', [ConfiguracionController::class, 'bitacoraAutomatico']);

            Route::post('/whatsapp/conversaciones/{conversacion}/mensajes', [WhatsappBandejaController::class, 'responder']);
            Route::post('/whatsapp/conversaciones/{conversacion}/devolver-al-bot', [WhatsappBandejaController::class, 'devolverAlBot']);
        });
    });

    // App de clientes (spec tenant/035). Token propio (`cliente-token`), nunca la sesión del Panel.
    Route::prefix('cliente')->group(function () {
        Route::get('/marca', [ClienteMarcaController::class, 'show'])->middleware('throttle:cliente-publico');
        Route::get('/marca/archivos/{archivo}', [ClienteMarcaArchivoController::class, 'show'])
            ->middleware('throttle:cliente-marca')
            ->name('cliente.marca.archivo');
        Route::get('/manifest.webmanifest', [ClienteManifiestoController::class, 'show'])->middleware('throttle:cliente-publico');
        Route::post('/codigo', [ClienteAccesoController::class, 'codigo'])->middleware('throttle:cliente-publico');
        Route::post('/verificar', [ClienteAccesoController::class, 'verificar'])->middleware('throttle:cliente-publico');

        Route::middleware(['auth:cliente-token', 'cliente.app', 'throttle:cliente-app'])->group(function () {
            Route::post('/salir', [ClienteAccesoController::class, 'salir']);
            Route::get('/perfil', [ClientePerfilController::class, 'show']);
            Route::put('/perfil', [ClientePerfilController::class, 'update']);

            // Fase 4: zona, dirección de un punto, cotización y direcciones favoritas.
            Route::post('/zona', [ClienteCotizacionController::class, 'zona']);
            // Cada una cuesta una consulta a Google: límite propio, más bajo (RN-48).
            Route::post('/direccion', [ClienteCotizacionController::class, 'direccion'])->middleware('throttle:cliente-google');
            Route::post('/cotizar', [ClienteCotizacionController::class, 'cotizar'])->middleware('throttle:cliente-google');
            Route::get('/direcciones', [ClienteDireccionController::class, 'index']);
            Route::post('/direcciones', [ClienteDireccionController::class, 'store']);
            Route::put('/direcciones/{direccion}', [ClienteDireccionController::class, 'update'])->whereNumber('direccion');
            Route::delete('/direcciones/{direccion}', [ClienteDireccionController::class, 'destroy'])->whereNumber('direccion');

            // Fase 5: pedidos (creación con llave anti-duplicados, lista, detalle y cancelación).
            Route::get('/pedidos', [ClientePedidoController::class, 'index']);
            Route::post('/pedidos', [ClientePedidoController::class, 'store'])->middleware('throttle:cliente-google');
            Route::get('/pedidos/{pedido}', [ClientePedidoController::class, 'show'])->whereNumber('pedido');
            Route::post('/pedidos/{pedido}/cancelar', [ClientePedidoController::class, 'cancelar'])->whereNumber('pedido');
        });
    });

    Route::prefix('conductor')->middleware('auth:conductor-token')->group(function () {
        Route::post('/logout', [ConductorAuthController::class, 'logout']);
        Route::get('/me', [ConductorAuthController::class, 'me']);

        Route::post('/estado', [ConductorEstadoController::class, 'actualizar']);
        // spec tenant/028: permiso para mandar la posición al microservicio GPS. `/ubicacion` se
        // conserva como respaldo mientras dura la migración y para cuando el servicio no responde
        // (RN-19).
        Route::post('/gps-token', [ConductorGpsTokenController::class, 'emitir']);
        Route::post('/ubicacion', [ConductorUbicacionController::class, 'actualizar']);
        Route::post('/pedidos/{pedido}/ubicaciones/lote', [ConductorUbicacionController::class, 'lote']);

        Route::get('/pedidos/disponibles', [ConductorPedidoController::class, 'disponibles']);
        Route::get('/pedidos/activo', [ConductorPedidoController::class, 'activo']);
        Route::post('/pedidos/{pedido}/aceptar', [ConductorPedidoController::class, 'aceptar']);
        Route::post('/pedidos/{pedido}/rechazar', [ConductorPedidoController::class, 'rechazar']);
        Route::post('/pedidos/{pedido}/estado', [ConductorPedidoController::class, 'cambiarEstado']);
        Route::post('/pedidos/{pedido}/cancelar', [ConductorPedidoController::class, 'cancelar']);
        Route::post('/pedidos/{pedido}/notificado', [ConductorPedidoController::class, 'notificado']);

        Route::get('/saldo-viajes', [ConductorSaldoController::class, 'show']);
        Route::get('/saldo', [ConductorSaldoController::class, 'dinero']);
        Route::get('/movimientos-saldo', [ConductorSaldoController::class, 'movimientos']);
        Route::get('/monedero', [ConductorSaldoController::class, 'monedero']);

        // Registro del token de push (FCM, spec tenant/018) y endpoint de sincronización al
        // reconectar (RN-02/RN-07): junta pedido activo + pool + saldo en una sola respuesta.
        Route::post('/dispositivo', [ConductorDispositivoController::class, 'registrar']);
        Route::get('/sync', [ConductorSyncController::class, 'show']);

        // Autenticación del canal privado de tiempo real (Reverb, spec tenant/013), scoped al
        // tenant y al guard del conductor — el /broadcasting/auth global (guard "web") no aplica.
        Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate']);
    });
});
