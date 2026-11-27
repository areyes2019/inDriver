<?php

declare(strict_types=1);

namespace App\Services\Cliente;

/**
 * Por dónde le llega el código de acceso al cliente (spec tenant/035, D6). Hoy solo correo; el código
 * por WhatsApp se agregará como otra implementación sin tocar lo demás (sección 16).
 */
interface CanalCodigo
{
    public function enviar(string $email, string $codigo, string $nombreFlotilla): void;
}
