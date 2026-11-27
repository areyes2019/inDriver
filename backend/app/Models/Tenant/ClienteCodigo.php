<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Código de acceso a la app de clientes (spec tenant/035, RN-11). Se guarda el HMAC, nunca el código.
 */
#[Fillable(['email', 'codigo_hmac', 'intentos', 'expira_en', 'usado_en', 'ip'])]
class ClienteCodigo extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cliente_codigos';

    protected $primaryKey = 'id_codigo';

    /** El HMAC nunca sale en una respuesta ni en un volcado del modelo. */
    protected $hidden = ['codigo_hmac'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intentos' => 'integer',
            'expira_en' => 'datetime',
            'usado_en' => 'datetime',
        ];
    }

    public function vigente(): bool
    {
        return $this->usado_en === null && $this->expira_en->isFuture();
    }
}
