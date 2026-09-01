<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuario_ciudades', function (Blueprint $table) {
            $table->id();

            $table->foreignId('id_usuario')->constrained('usuarios', 'id_usuario')->cascadeOnDelete();
            $table->foreignId('id_ciudad')->constrained('ciudades', 'id_ciudad')->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['id_usuario', 'id_ciudad']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_ciudades');
    }
};
