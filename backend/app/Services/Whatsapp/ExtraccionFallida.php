<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use RuntimeException;

/**
 * La IA no devolvió algo utilizable: una negativa, una respuesta cortada, un JSON inválido o un
 * error de la API. Quien la atrapa contesta con una pregunta en lugar de callar (RN-24).
 */
class ExtraccionFallida extends RuntimeException {}
