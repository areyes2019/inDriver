<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Usuario;

/**
 * El AdminCliente con el que ADMIN_CENTRAL actúa dentro de un tenant: la sesión de espectador se
 * abre como él, y las ventas de viajes que hace central quedan a su nombre (la columna
 * `ventas_viajes_conductor.id_usuario` es obligatoria). Se toma el AdminCliente activo más antiguo,
 * que normalmente es el inicial que se creó con el tenant. Espera la tenencia ya inicializada.
 */
class AdminClienteDelTenant
{
    public static function principal(): ?Usuario
    {
        return Usuario::query()
            ->where('rol', 'AdminCliente')
            ->where('estado', 'Activo')
            ->orderBy('id_usuario')
            ->first();
    }
}
