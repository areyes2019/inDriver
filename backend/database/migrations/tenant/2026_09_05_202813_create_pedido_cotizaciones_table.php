<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cotización de cambio de destino (spec tenant/022, RN-10/RN-11): un boleto con caducidad de
     * 5 minutos. `usada` evita aplicar la misma cotización dos veces.
     */
    public function up(): void
    {
        Schema::create('pedido_cotizaciones', function (Blueprint $table) {
            $table->id('id_cotizacion');

            $table->foreignId('id_pedido')->constrained('pedidos', 'id_pedido')->cascadeOnDelete();

            $table->string('direccion_nueva', 255);
            $table->decimal('latitud_nueva', 10, 7);
            $table->decimal('longitud_nueva', 10, 7);
            $table->decimal('distancia_extra_km', 8, 2);
            $table->decimal('cargo_extra', 10, 2);
            $table->decimal('pago_extra', 10, 2);

            $table->boolean('usada')->default(false);
            $table->timestamp('expira_en');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_cotizaciones');
    }
};
