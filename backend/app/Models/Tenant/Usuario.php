<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['nombre', 'apellido_paterno', 'apellido_materno', 'telefono', 'email', 'password', 'rol', 'estado'])]
#[Hidden(['password'])]
class Usuario extends Authenticatable
{
    use Notifiable;

    protected $table = 'usuarios';

    protected $primaryKey = 'id_usuario';

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'ultimo_acceso' => 'datetime',
        ];
    }

    public function ciudades(): BelongsToMany
    {
        return $this->belongsToMany(Ciudad::class, 'usuario_ciudades', 'id_usuario', 'id_ciudad');
    }
}
