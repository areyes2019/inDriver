<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Color con el que se dibuja un conductor —su marcador y su polilínea— en el Panel y en
 * panda_express (spec tenant/026, RN-17).
 *
 * No se guarda en `conductores`: se calcula siempre igual a partir del id. Una columna guardada
 * habría que sembrarla al alta, mantenerla al reasignar y sincronizarla entre las dos apps, y
 * ninguna de las tres cosas aporta algo que el módulo del id no dé gratis. El precio es que con más
 * de diez conductores activos los colores se repiten (RN-19), que es un precio aceptable.
 */
class ColorConductor
{
    /**
     * Diez tonos elegidos para distinguirse entre sí sobre el mapa claro de Google.
     *
     * @var array<int, string>
     */
    public const PALETA = [
        '#2563EB',
        '#DC2626',
        '#059669',
        '#D97706',
        '#7C3AED',
        '#DB2777',
        '#0891B2',
        '#65A30D',
        '#EA580C',
        '#4F46E5',
    ];

    public static function para(int $idConductor): string
    {
        return self::PALETA[$idConductor % count(self::PALETA)];
    }
}
