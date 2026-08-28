<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conductor_estado', function (Blueprint $table) {
            $table->id();

            $table->foreignId('id_conductor')->unique()->constrained('conductores', 'id_conductor')->cascadeOnDelete();

            $table->enum('estado', ['ONLINE', 'OFFLINE', 'DISPONIBLE', 'OCUPADO', 'DESCANSO', 'FUERA_DE_SERVICIO'])
                ->default('OFFLINE');

            $table->dateTime('ultima_conexion')->nullable();
            $table->dateTime('ultima_desconexion')->nullable();
            $table->decimal('ultima_latitud', 10, 7)->nullable();
            $table->decimal('ultima_longitud', 10, 7)->nullable();
            $table->dateTime('ultima_actualizacion')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conductor_estado');
    }
};
