<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\CompraPaquete;
use App\Models\Tenant\ConsumoViajeTenant;
use Illuminate\Support\Facades\Log;

/**
 * Viajes que le quedan al tenant frente a ADMIN_CENTRAL: acreditados menos consumidos por envíos
 * entregados (spec tenant/015, corrección "el saldo del tenant se consume por envío entregado").
 *
 * Antes la fórmula era acreditados menos vendidos a conductores, y estaba escrita dos veces
 * (`ConfiguracionController` y `VentaViajeConductorController`). Ahora vive aquí y la leen
 * Configuración, la validación de venta, el `meta` del Panel y el evento `saldo-tenant.cambiado`
 * (spec tenant/027 §12), para que ninguna pantalla pueda decir un número distinto.
 *
 * Puede ser negativo: nada se bloquea por saldo (RN-C5). Y no depende del ambiente: los envíos
 * TEST nunca escriben en `consumos_viajes_tenant` (spec tenant/025, enmienda), así que la cuenta
 * sale igual desde un Panel en TEST, en LIVE o desde el contexto de ADMIN_CENTRAL.
 */
class SaldoViajesTenant
{
    public static function actual(): int
    {
        return (int) CompraPaquete::sum('cantidad_viajes') - ConsumoViajeTenant::count();
    }

    /**
     * Para las pantallas que muestran el saldo de paso —Configuración, el `meta` del Panel—: si el
     * cálculo falla (la migración de tenant pendiente fue el caso real), se devuelve `null` y se
     * registra, en vez de tumbar toda la respuesta. Que se caiga el saldo, no las tarifas ni las
     * listas. Las decisiones de negocio (el tope de venta) siguen usando `actual()` y sí fallan.
     */
    public static function actualONull(): ?int
    {
        try {
            return self::actual();
        } catch (\Throwable $e) {
            Log::warning('No se pudo calcular el saldo de viajes del tenant.', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
