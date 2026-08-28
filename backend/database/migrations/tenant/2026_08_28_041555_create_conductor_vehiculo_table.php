<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conductor_vehiculo', function (Blueprint $table) {
            $table->id();

            $table->foreignId('id_conductor')->constrained('conductores', 'id_conductor')->cascadeOnDelete();
            $table->foreignId('id_vehiculo')->constrained('vehiculos', 'id_vehiculo')->cascadeOnDelete();

            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conductor_vehiculo');
    }
};
