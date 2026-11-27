<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Un teléfono que comparten varios clientes y espera que el AdminCliente los unifique (spec
 * tenant/035, D20 y RN-16 a RN-19).
 */
#[Fillable(['telefono_e164', 'clientes', 'detectado_en'])]
class TelefonoEnRevision extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'telefonos_en_revision';

    protected $primaryKey = 'telefono_e164';

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clientes' => 'array',
            'detectado_en' => 'datetime',
        ];
    }
}
