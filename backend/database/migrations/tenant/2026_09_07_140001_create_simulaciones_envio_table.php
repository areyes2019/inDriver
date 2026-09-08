<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un tramo simulado de un envío TEST (spec tenant/025). Son dos por envío: el acercamiento
     * (donde está el conductor -> recogida) y la entrega (recogida -> entrega).
     *
     * `ruta` guarda la polilínea real ya decodificada y con la distancia acumulada en cada vértice
     * (`{lat, lng, m}`), para poder interpolar la posición por metros sin volver a pedirle nada a
     * Google. `iniciada_en` es todo el estado que hace falta: la posición del móvil se deduce del
     * reloj (RN-13), y `avanzada_hasta_m` solo recuerda hasta dónde ya se escribió para no repetir
     * puntos.
     */
    public function up(): void
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
            // El comando pregunta una vez por minuto "¿qué tramos siguen abiertos?".
            $table->index('terminada_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulaciones_envio');
    }
};
