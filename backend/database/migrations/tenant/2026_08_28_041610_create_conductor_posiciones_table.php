<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conductor_posiciones', function (Blueprint $table) {
            $table->id('id_posicion');

            $table->foreignId('id_conductor')->constrained('conductores', 'id_conductor')->cascadeOnDelete();

            $table->decimal('latitud', 10, 7);
            $table->decimal('longitud', 10, 7);
            $table->decimal('precision', 8, 2)->nullable();
            $table->decimal('velocidad', 6, 2)->nullable();
            $table->unsignedSmallInteger('rumbo')->nullable();
            $table->unsignedTinyInteger('bateria')->nullable();

            $table->dateTime('fecha_posicion');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conductor_posiciones');
    }
};
