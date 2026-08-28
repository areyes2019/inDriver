<?php

namespace Database\Seeders;

use App\Models\AdminCentral;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminCentralSeeder extends Seeder
{
    /**
     * Credenciales fijas para desarrollo (ver 004-auth-admin-central.md).
     * En producción, cambiar la contraseña a mano después de correr este seeder una vez.
     */
    public function run(): void
    {
        AdminCentral::updateOrCreate(
            ['email' => 'admin@indriver.local'],
            [
                'nombre' => 'Admin',
                'apellido_paterno' => 'Central',
                'apellido_materno' => null,
                'password' => Hash::make('password'),
                'estado' => 'Activo',
            ]
        );
    }
}
