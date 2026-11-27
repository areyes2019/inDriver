<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Se eliminó el modo TEST del Panel, y con él el simulador que movía los envíos de prueba por su
 * ruta (spec tenant/025). Esta tabla guardaba solo el avance de esos tramos simulados: nada que
 * conservar. `dropIfExists` porque hay tenants a los que nunca se les creó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('simulaciones_envio');
    }

    public function down(): void
    {
        Schema::create('simulaciones_envio', function (Blueprint $table) {
            $table->id('id_simulacion');
            $table->unsignedBigInteger('id_pedido');
            $table->enum('tramo', ['ACERCAMIENTO', 'ENTREGA']);
            $table->json('ruta');
            $table->decimal('distancia_m', 10, 2);
            $table->timestamp('iniciada_en');
            $table->decimal('avanzada_hasta_m', 10, 2)->default(0);
            $table->timestamp('terminada_en')->nullable();
            $table->timestamps();

            $table->foreign('id_pedido')->references('id_pedido')->on('pedidos')->cascadeOnDelete();
            $table->unique(['id_pedido', 'tramo']);
            $table->index('terminada_en');
        });
    }
};
