<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['nombre', 'apellido_paterno', 'apellido_materno', 'telefono', 'email', 'password', 'rol', 'estado'])]
#[Hidden(['password'])]
class Usuario extends Authenticatable
{
    use HasApiTokens, Notifiable;

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

    /**
     * Perfil de conductor del usuario (solo aplica cuando `rol = 'Conductor'`), usado por la app
     * panda_express para resolver el conductor autenticado sin pedir su id explícitamente
     * (spec tenant/013).
     */
    public function conductor(): HasOne
    {
        return $this->hasOne(Conductor::class, 'id_usuario', 'id_usuario');
    }
}
