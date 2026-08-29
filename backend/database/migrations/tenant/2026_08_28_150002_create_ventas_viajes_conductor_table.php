<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas_viajes_conductor', function (Blueprint $table) {
            $table->id('id_venta');

            $table->foreignId('id_conductor')->constrained('conductores', 'id_conductor')->cascadeOnDelete();
            $table->unsignedInteger('cantidad_viajes');
            $table->foreignId('id_usuario')->constrained('usuarios', 'id_usuario')->cascadeOnDelete();
            $table->dateTime('fecha_venta');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas_viajes_conductor');
    }
};
