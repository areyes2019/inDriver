<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes del formulario de contacto de la página pública (motopanda.mx). Se guardan además de
 * mandarse por correo, para que ninguno se pierda si el correo falla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contactos', function (Blueprint $table) {
            $table->id('id_contacto');
            $table->string('nombre');
            $table->string('empresa')->nullable();
            $table->string('telefono', 30);
            $table->string('email');
            $table->unsignedInteger('motos')->nullable();
            $table->text('mensaje')->nullable();
            $table->string('ip', 45)->nullable();
            $table->boolean('correo_enviado')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contactos');
    }
};
