<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * Cliente final. Desde la spec tenant/035 también entra a la app de clientes con un token de Sanctum
 * (guard `cliente-token`); por eso es `Authenticatable`, aunque no tiene contraseña.
 *
 * `email_acceso`, `telefono_e164` y `origen` quedan fuera del `#[Fillable]` a propósito: solo los
 * escriben el acceso a la app y la conciliación de teléfonos, nunca un `update()` con datos de una
 * petición.
 */
#[Fillable(['nombre', 'telefono', 'email', 'referencia', 'estado'])]
class Cliente extends Model implements AuthenticatableContract
{
    use Authenticatable, HasApiTokens;

    public const ORIGEN_PANEL = 'PANEL';

    public const ORIGEN_WHATSAPP = 'WHATSAPP';

    public const ORIGEN_APP = 'APP';

    /** Habilidad del token de la app (spec tenant/035, RN-14). */
    public const HABILIDAD_APP = 'cliente';

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
            'email_verificado_en' => 'datetime',
            'telefono_verificado_en' => 'datetime',
        ];
    }

    /**
     * Ojo: `Pedido` oculta los envíos de prueba antiguos (`OcultarEnviosDePrueba`).
     */
    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'id_cliente', 'id_cliente');
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
