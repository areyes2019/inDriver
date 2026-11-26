<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modo automático (spec tenant/034, §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            // RN-05: la primera vez que cayó a PENDIENTE por agotar sus rondas.
            $table->timestamp('sin_conductor_desde')->nullable()->after('veces_ofertado');
            // RN-09.
            $table->timestamp('ultima_reoferta_automatica')->nullable()->after('sin_conductor_desde');
            // RN-15: no hay historial de estados, así que la última transición se guarda aquí.
            $table->timestamp('estado_cambiado_en')->nullable()->after('ultima_reoferta_automatica');
            // RN-16: evita anotar dos veces el mismo pedido atorado.
            $table->timestamp('aviso_atorado_en')->nullable()->after('estado_cambiado_en');
        });

        // Agregar un valor no cambia el significado de los que ya existen (§6).
        Schema::table('pedidos', function (Blueprint $table) {
            $table->enum('cancelado_por', ['CLIENTE', 'ADMIN', 'SISTEMA'])->nullable()->change();
        });

        Schema::create('automatico_bitacora', function (Blueprint $table) {
            $table->id('id_bitacora');
            $table->foreignId('id_pedido')->nullable()->index()->constrained('pedidos', 'id_pedido')->nullOnDelete();
            $table->enum('accion', ['REOFERTA', 'CANCELACION', 'PAUSA', 'PERSONA', 'ATORADO']);
            $table->string('detalle', 255);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automatico_bitacora');

        Schema::table('pedidos', function (Blueprint $table) {
            $table->enum('cancelado_por', ['CLIENTE', 'ADMIN'])->nullable()->change();
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn(['sin_conductor_desde', 'ultima_reoferta_automatica', 'estado_cambiado_en', 'aviso_atorado_en']);
        });
    }
};
