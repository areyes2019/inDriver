<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Un mensaje del formulario de contacto de la página pública. Vive en la base central: quien escribe
 * todavía no es una flotilla.
 */
#[Fillable(['nombre', 'empresa', 'telefono', 'email', 'motos', 'mensaje', 'ip', 'correo_enviado'])]
class Contacto extends Model
{
    protected $table = 'contactos';

    protected $primaryKey = 'id_contacto';

    protected function casts(): array
    {
        return [
            'motos' => 'integer',
            'correo_enviado' => 'boolean',
        ];
    }
}
