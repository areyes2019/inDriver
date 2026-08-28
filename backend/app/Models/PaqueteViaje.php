<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['codigo_paquete', 'nombre', 'descripcion', 'cantidad_viajes', 'precio', 'estado'])]
class PaqueteViaje extends Model
{
    use SoftDeletes;

    protected $table = 'paquetes_viajes';

    protected $primaryKey = 'id_paquete';
}
