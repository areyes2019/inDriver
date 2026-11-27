<?php

declare(strict_types=1);

namespace App\Services;

/** Lo que devuelve `CotizacionPedidoService`: un precio, o por qué no lo hay. */
final class ResultadoCotizacion
{
    private function __construct(
        public readonly ?float $km,
        public readonly ?int $minutos,
        public readonly ?float $importeEnvio,
        public readonly ?string $motivo,
        /** Con `FUERA_DE_ZONA`: `recogida` o `entrega`. */
        public readonly ?string $punto,
    ) {}

    public static function lista(float $km, ?int $minutos, float $importeEnvio): self
    {
        return new self($km, $minutos, $importeEnvio, null, null);
    }

    public static function falla(string $motivo, ?string $punto = null): self
    {
        return new self(null, null, null, $motivo, $punto);
    }

    public function ok(): bool
    {
        return $this->motivo === null;
    }
}
