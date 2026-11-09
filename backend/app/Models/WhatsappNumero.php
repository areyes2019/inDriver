<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Un número de WhatsApp Business y la flotilla a la que pertenece (spec tenant/031).
 */
#[Fillable(['phone_number_id', 'waba_id', 'id_tenant', 'telefono', 'access_token', 'estado'])]
#[Hidden(['access_token'])]
class WhatsappNumero extends Model
{
    use CentralConnection;

    protected $table = 'whatsapp_numeros';

    protected $primaryKey = 'id_numero';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'id_tenant', 'id_tenant');
    }
}
