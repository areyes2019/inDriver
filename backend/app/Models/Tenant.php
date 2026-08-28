<?php

declare(strict_types=1);

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;

    protected $primaryKey = 'id_tenant';

    public function getTenantKeyName(): string
    {
        return 'id_tenant';
    }

    /**
     * Todas las columnas reales de la tabla (spec db/01) deben listarse aquí:
     * VirtualColumn redirige a la columna `data` (json) cualquier atributo que no aparezca en esta lista.
     */
    public static function getCustomColumns(): array
    {
        return [
            'id_tenant',
            'nombre_comercial',
            'razon_social',
            'rfc',
            'telefono',
            'email',
            'calle',
            'numero_int',
            'numero_ext',
            'colonia',
            'cp',
            'ciudad',
            'estado_direccion',
            'pais',
            'estado',
            'modo_estado',
            'fecha_inicio',
            'fecha_vencimiento',
            'database_nombre',
            'database_host',
            'database_puerto',
            'database_usuario',
            'database_password',
            'created_at',
            'updated_at',
        ];
    }
}
