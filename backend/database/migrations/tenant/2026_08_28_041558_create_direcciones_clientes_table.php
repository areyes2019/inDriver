<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direcciones_clientes', function (Blueprint $table) {
            $table->id('id_direccion');

            $table->foreignId('id_cliente')->constrained('clientes', 'id_cliente')->cascadeOnDelete();

            $table->string('alias')->nullable();
            $table->string('calle');
            $table->string('numero')->nullable();
            $table->string('colonia')->nullable();
            $table->string('cp', 10)->nullable();
            $table->string('ciudad')->nullable();
            $table->string('estado')->nullable();
            $table->string('referencia')->nullable();
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->text('instrucciones_entrega')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direcciones_clientes');
    }
};
