<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['nombre', 'apellido_paterno', 'apellido_materno', 'email', 'password', 'estado', 'rol'])]
#[Hidden(['password', 'remember_token'])]
class AdminCentral extends Authenticatable
{
    use Notifiable;

    protected $table = 'admins_centrales';

    protected $primaryKey = 'id_admin';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'ultimo_acceso' => 'datetime',
        ];
    }
}
