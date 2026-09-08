<?php

declare(strict_types=1);

namespace App\Support;

/**
 * En qué ambiente (TEST/LIVE) está mirando quien hace esta petición (spec tenant/025).
 *
 * Nace **vacío** y esa es la pieza clave del diseño: `AmbienteScope` no filtra nada mientras nadie
 * lo llene, así que el comando de simulación, los jobs diferidos y la app del conductor siguen
 * viendo todos los envíos sin necesidad de enumerar excepciones. El único que lo llena es
 * `AplicarAmbientePanel`, colgado del grupo `auth:usuario` — es decir, el Panel y solo el Panel.
 *
 * Se registra como singleton, así que su vida es la de la petición (o la del comando).
 */
class ContextoAmbiente
{
    public const LIVE = 'live';

    public const TEST = 'test';

    private ?string $ambiente = null;

    public function fijar(string $ambiente): void
    {
        $this->ambiente = $ambiente === self::TEST ? self::TEST : self::LIVE;
    }

    public function limpiar(): void
    {
        $this->ambiente = null;
    }

    public function actual(): ?string
    {
        return $this->ambiente;
    }

    public function hayAmbiente(): bool
    {
        return $this->ambiente !== null;
    }
}
