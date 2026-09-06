<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sin esto, `conductor_posiciones` solo sabía de qué conductor era un punto, no de qué envío
     * (spec tenant/021): hace falta para poder consultar el recorrido de un pedido y para aplicar
     * la retención de 7 días por envío.
     */
    public function up(): void
    {
        Schema::table('conductor_posiciones', function (Blueprint $table) {
            $table->foreignId('id_pedido')->nullable()->after('id_conductor')
                ->constrained('pedidos', 'id_pedido')->nullOnDelete();

            $table->index(['id_pedido', 'fecha_posicion']);
        });
    }

    public function down(): void
    {
        Schema::table('conductor_posiciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_pedido');
        });
    }
};
