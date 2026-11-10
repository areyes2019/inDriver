<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bandeja de conversaciones del Panel (spec tenant/031, etapa 7b).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversaciones', function (Blueprint $table) {
            // RN-59: el cliente pidió hablar con una persona y nadie le ha contestado.
            $table->timestamp('pide_persona_en')->nullable()->after('ultimo_mensaje_en');
            // RN-58: mientras no pase esta hora, el bot no contesta.
            $table->timestamp('bot_pausado_hasta')->nullable()->after('pide_persona_en');
        });

        Schema::table('whatsapp_mensajes', function (Blueprint $table) {
            // Quién del Panel escribió el mensaje; nulo cuando lo escribió el bot o el cliente.
            $table->foreignId('id_usuario')->nullable()->after('boton')->constrained('usuarios', 'id_usuario')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_mensajes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_usuario');
        });

        Schema::table('whatsapp_conversaciones', function (Blueprint $table) {
            $table->dropColumn(['pide_persona_en', 'bot_pausado_hasta']);
        });
    }
};
