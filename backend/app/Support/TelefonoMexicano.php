<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Teléfonos de México como llegan de WhatsApp o se capturan en el Panel (spec tenant/031).
 */
final class TelefonoMexicano
{
    /**
     * Los 10 dígitos del número, o `null` si no es un teléfono mexicano válido. Acepta espacios,
     * guiones y paréntesis, y la lada de país `52` o el `521` de los móviles viejos.
     */
    public static function diezDigitos(string $telefono): ?string
    {
        $digitos = preg_replace('/\D+/', '', $telefono) ?? '';

        return preg_match('/^(?:521|52)?(\d{10})$/', $digitos, $partes) === 1 ? $partes[1] : null;
    }

    /** El formato internacional que pide Meta para escribirle a un número, sin `+`. */
    public static function paraWhatsapp(string $diezDigitos): string
    {
        return "52{$diezDigitos}";
    }
}
