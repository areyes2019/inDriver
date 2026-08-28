<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conductores', function (Blueprint $table) {
            $table->id('id_conductor');

            $table->foreignId('id_usuario')->constrained('usuarios', 'id_usuario')->cascadeOnDelete();

            $table->string('numero_licencia');
            $table->string('tipo_licencia')->nullable();
            $table->date('fecha_vencimiento_licencia')->nullable();
            $table->string('telefono_emergencia')->nullable();

            $table->enum('estado', ['ACTIVO', 'INACTIVO', 'BLOQUEADO'])->default('ACTIVO');
            $table->enum('disponibilidad', ['DISPONIBLE', 'OCUPADO', 'DESCANSO', 'FUERA_DE_SERVICIO'])
                ->default('FUERA_DE_SERVICIO');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conductores');
    }
};
