<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehiculos', function (Blueprint $table) {
            $table->id('id_vehiculo');

            $table->string('placa')->unique();
            $table->string('marca')->nullable();
            $table->string('modelo')->nullable();
            $table->unsignedSmallInteger('anio')->nullable();
            $table->string('color')->nullable();
            $table->string('tipo')->nullable();
            $table->string('numero_economico')->nullable();

            $table->enum('estado', ['ACTIVO', 'INACTIVO', 'MANTENIMIENTO'])->default('ACTIVO');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehiculos');
    }
};
