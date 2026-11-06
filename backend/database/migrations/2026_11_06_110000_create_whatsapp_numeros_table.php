<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A qué flotilla pertenece cada número de WhatsApp (spec tenant/031). Vive en la base central porque
 * el webhook llega sin tenant: solo enruta, y el contenido de los mensajes va a la base de la flotilla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_numeros', function (Blueprint $table) {
            $table->id('id_numero');
            $table->string('phone_number_id')->unique();
            $table->foreignId('id_tenant')->constrained('tenants', 'id_tenant')->cascadeOnDelete();
            $table->string('telefono')->nullable();
            $table->text('access_token');
            $table->enum('estado', ['Activo', 'Inactivo'])->default('Activo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_numeros');
    }
};
