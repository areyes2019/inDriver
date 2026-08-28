<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paquetes_viajes', function (Blueprint $table) {
            $table->id('id_paquete');

            $table->string('codigo_paquete')->unique();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->unsignedInteger('cantidad_viajes');
            $table->decimal('precio', 10, 2);

            $table->enum('estado', ['Activo', 'Inactivo'])->default('Activo');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paquetes_viajes');
    }
};
