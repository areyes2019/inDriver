<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Tenant\SaldoAcreditado;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\VentaViajeConductor;
use App\Support\SaldoViajesTenant;
use Illuminate\Validation\ValidationException;

/**
 * Venta de viajes prepagados a un conductor (spec tenant/013 y tenant/015): el monto pagado se
 * convierte en viajes con el costo del viaje prepagado del tenant, con tope en el saldo del tenant
 * (RN-C4). La usan el Panel (`VentaViajeConductorController`) y ADMIN_CENTRAL desde el detalle del
 * tenant, para que las dos ventas sigan exactamente las mismas reglas.
 *
 * Espera la tenencia ya inicializada. La auditoría queda a cargo de quien llama: el Panel escribe
 * en la auditoría del tenant y ADMIN_CENTRAL en su bitácora central.
 */
class VentaViajesConductorService
{
    public function vender(Conductor $conductor, float $montoPagado, int $idUsuario): VentaViajeConductor
    {
        $costoViajePrepago = (float) (ConfiguracionTenant::obtener(ConfiguracionTenant::COSTO_VIAJE_PREPAGO) ?? 0);

        if ($costoViajePrepago <= 0) {
            throw ValidationException::withMessages([
                'monto_pagado' => 'El tenant debe configurar el costo del viaje prepagado antes de poder acreditar viajes por monto.',
            ]);
        }

        $cantidadViajes = (int) floor($montoPagado / $costoViajePrepago);

        if ($cantidadViajes < 1) {
            throw ValidationException::withMessages([
                'monto_pagado' => "El monto pagado no alcanza para acreditar al menos 1 viaje (costo del viaje: {$costoViajePrepago}).",
            ]);
        }

        // Tope contra el saldo del tenant (spec tenant/015, RN-C4). La venta ya no descuenta de ese
        // saldo —lo consumen los envíos entregados—, pero se decidió conservar el límite.
        $saldoTenant = SaldoViajesTenant::actual();

        if ($cantidadViajes > $saldoTenant) {
            throw ValidationException::withMessages([
                'monto_pagado' => "Ese monto equivale a {$cantidadViajes} viaje(s), pero el tenant solo tiene {$saldoTenant} viaje(s) disponible(s) para vender.",
            ]);
        }

        $venta = VentaViajeConductor::create([
            'id_conductor' => $conductor->id_conductor,
            'cantidad_viajes' => $cantidadViajes,
            'monto_pagado' => $montoPagado,
            'id_usuario' => $idUsuario,
            'fecha_venta' => now(),
        ]);

        // Tiempo real (spec tenant/018): avisa al conductor al instante, con respaldo de push si
        // su socket está caído (evento "crítico", RN-04).
        if ($slug = tenant()?->slug) {
            SaldoAcreditado::dispatch($conductor->id_conductor, $venta->cantidad_viajes, $slug);
        }

        return $venta;
    }
}
