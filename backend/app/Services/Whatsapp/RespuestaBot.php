<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

/**
 * Lo que el bot contesta: un texto y, si hace falta, botones de respuesta (RN-19).
 */
final readonly class RespuestaBot
{
    /**
     * @param  array<string, string>  $botones  id => título
     */
    public function __construct(
        public string $texto,
        public array $botones = [],
    ) {}
}
