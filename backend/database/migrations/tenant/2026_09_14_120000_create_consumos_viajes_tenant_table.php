<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora de viajes consumidos del saldo del tenant (spec tenant/015, corrección "el saldo del
     * tenant se consume por envío entregado"). Una fila por envío LIVE entregado; el `UNIQUE` sobre
     * `id_pedido` es la garantía de "una sola vez" a nivel de base. Sin `ambiente`: los envíos TEST
     * nunca escriben aquí (spec tenant/025, enmienda), así que las filas son LIVE por construcción.
     *
     * Se crea vacía a propósito: los envíos entregados antes de esta regla ya "pagaron" con la venta
     * que los cubrió (RN-C6), y contarlos castigaría dos veces a los tenants en Prepago.
     */
    public function up(): void
    {
        Schema::create('consumos_viajes_tenant', function (Blueprint $table) {
            $table->id('id_consumo');
            $table->foreignId('id_pedido')->unique()->constrained('pedidos', 'id_pedido')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumos_viajes_tenant');
    }
};
