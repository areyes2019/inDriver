<?php

declare(strict_types=1);

namespace App\Services\Cliente;

use App\Mail\CodigoAccesoMail;
use Illuminate\Support\Facades\Mail;

/**
 * Manda el código por correo, en el momento y no por la cola: el cliente está mirando la pantalla, y
 * si Resend falla la app tiene que enterarse para decírselo.
 */
class CanalCodigoCorreo implements CanalCodigo
{
    public function enviar(string $email, string $codigo, string $nombreFlotilla): void
    {
        Mail::to($email)->send(new CodigoAccesoMail($codigo, $nombreFlotilla));
    }
}
