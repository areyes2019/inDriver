<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins_centrales', function (Blueprint $table) {
            $table->id('id_admin');

            $table->string('nombre');
            $table->string('apellido_paterno');
            $table->string('apellido_materno')->nullable();
            $table->string('email')->unique();
            $table->string('password');

            $table->enum('estado', ['Activo', 'Suspendido', 'Inactivo'])->default('Activo');
            $table->timestamp('ultimo_acceso')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins_centrales');
    }
};
