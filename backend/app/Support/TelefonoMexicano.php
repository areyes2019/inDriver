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

    /**
     * `+52` y 10 dígitos, o `null` (spec tenant/035, RN-10). Es la forma de comparar dos teléfonos
     * escritos distinto. Además de lo que acepta `diezDigitos`, quita los prefijos viejos `044` y
     * `045`.
     */
    public static function e164(?string $telefono): ?string
    {
        if ($telefono === null) {
            return null;
        }

        $digitos = preg_replace('/\D+/', '', $telefono) ?? '';
        if (preg_match('/^04[45](\d{10})$/', $digitos, $partes) === 1) {
            return '+52'.$partes[1];
        }

        $diez = self::diezDigitos($telefono);

        return $diez === null ? null : '+52'.$diez;
    }

    /** Los 10 dígitos de `e164()`, como se guardan en `clientes.telefono`. */
    public static function nacional(?string $telefono): ?string
    {
        $e164 = self::e164($telefono);

        return $e164 === null ? null : substr($e164, 3);
    }

    /** El formato internacional que pide Meta para escribirle a un número, sin `+`. */
    public static function paraWhatsapp(string $diezDigitos): string
    {
        return "52{$diezDigitos}";
    }
}
