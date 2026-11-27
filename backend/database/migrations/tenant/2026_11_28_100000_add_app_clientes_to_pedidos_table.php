<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spec tenant/035, sección 7: de dónde llegó el pedido y los datos que captura la app de clientes.
 * Solo agrega columnas nulas: los pedidos existentes quedan igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->enum('origen', ['PANEL', 'WHATSAPP', 'APP'])->nullable();
            $table->enum('tipo_servicio', ['ENVIAR', 'RECIBIR'])->nullable();
            $table->string('contacto_recogida_nombre', 120)->nullable();
            $table->string('contacto_recogida_telefono', 20)->nullable();
            $table->string('referencias_recogida', 255)->nullable();
            $table->string('referencias_entrega', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn([
                'origen',
                'tipo_servicio',
                'contacto_recogida_nombre',
                'contacto_recogida_telefono',
                'referencias_recogida',
                'referencias_entrega',
            ]);
        });
    }
};
