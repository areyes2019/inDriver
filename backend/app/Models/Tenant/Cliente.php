<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'telefono', 'email', 'referencia', 'estado'])]
class Cliente extends Model
{
    protected $table = 'clientes';

    protected $primaryKey = 'id_cliente';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'avisos_whatsapp' => 'boolean',
            'avisos_whatsapp_en' => 'datetime',
        ];
    }

    public function direcciones(): HasMany
    {
        return $this->hasMany(DireccionCliente::class, 'id_cliente', 'id_cliente');
    }

    /**
     * Guarda el permiso para avisos con su evidencia (spec tenant/031, D21). Fuera del `#[Fillable]`
     * a propósito: solo se cambia por aquí, para que nunca quede sin fecha ni sin quién.
     */
    public function cambiarAvisosWhatsapp(bool $acepta, string $quien): void
    {
        if ($this->avisos_whatsapp === $acepta) {
            return;
        }

        $this->avisos_whatsapp = $acepta;
        $this->avisos_whatsapp_en = now();
        $this->avisos_whatsapp_por = mb_substr($quien, 0, 255);
        $this->save();
    }
}
