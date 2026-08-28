<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes', function (Blueprint $table) {
            $table->id('id_plan');

            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->decimal('precio', 10, 2);

            $table->unsignedInteger('limite_despachadores')->nullable();
            $table->unsignedInteger('limite_conductores')->nullable();
            $table->unsignedInteger('limite_pedidos')->nullable();

            $table->enum('estado', ['Activo', 'Inactivo'])->default('Activo');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planes');
    }
};
