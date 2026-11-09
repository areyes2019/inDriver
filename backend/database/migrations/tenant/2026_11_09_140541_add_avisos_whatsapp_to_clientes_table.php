<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los avisos de WhatsApp (spec tenant/031, etapa 7a): el permiso del cliente con su evidencia
 * (D21, RN-50) y un registro de cada aviso, para no mandar el mismo dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            // `null` = nunca se le preguntó; `false` = no quiere avisos (incluye "BAJA").
            $table->boolean('avisos_whatsapp')->nullable()->after('estado');
            $table->timestamp('avisos_whatsapp_en')->nullable()->after('avisos_whatsapp');
            $table->string('avisos_whatsapp_por')->nullable()->after('avisos_whatsapp_en');
        });

        Schema::create('whatsapp_avisos', function (Blueprint $table) {
            $table->id('id_aviso');
            $table->foreignId('id_pedido')->constrained('pedidos', 'id_pedido')->cascadeOnDelete();
            $table->string('momento');
            $table->enum('estado', ['ENVIADO', 'OMITIDO', 'FALLIDO']);
            $table->string('motivo')->nullable();
            $table->string('wamid')->nullable();
            $table->timestamps();

            // Un cambio de estado se avisa una sola vez, aunque el job se reintente.
            $table->unique(['id_pedido', 'momento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_avisos');

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['avisos_whatsapp', 'avisos_whatsapp_en', 'avisos_whatsapp_por']);
        });
    }
};
