<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las conversaciones y mensajes de WhatsApp de la flotilla (spec tenant/031).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversaciones', function (Blueprint $table) {
            $table->id('id_conversacion');
            // El BSUID de Meta. El teléfono puede no llegar (RN-12).
            $table->string('usuario_whatsapp')->unique();
            $table->string('telefono')->nullable();
            $table->string('nombre_perfil')->nullable();
            $table->foreignId('id_cliente')->nullable()->constrained('clientes', 'id_cliente')->nullOnDelete();
            $table->timestamp('ultimo_mensaje_en')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_mensajes', function (Blueprint $table) {
            $table->id('id_mensaje');
            $table->foreignId('id_conversacion')->constrained('whatsapp_conversaciones', 'id_conversacion')->cascadeOnDelete();
            // Un reintento de Meta trae el mismo id: el índice único impide procesarlo dos veces (RN-21).
            $table->string('wamid')->unique();
            $table->enum('direccion', ['ENTRANTE', 'SALIENTE']);
            $table->string('tipo');
            // Se borra a los 90 días (RN-30).
            $table->text('texto')->nullable();
            $table->enum('estado_envio', ['sent', 'delivered', 'read', 'failed'])->nullable();
            $table->timestamp('enviado_en');
            $table->timestamp('respondido_en')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_mensajes');
        Schema::dropIfExists('whatsapp_conversaciones');
    }
};
