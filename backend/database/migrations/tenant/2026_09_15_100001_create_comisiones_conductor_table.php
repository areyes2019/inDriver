<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comisión generada a favor del conductor por cada envío entregado en modalidad Comisión (spec
     * tenant/030). Gemela de `consumos_viajes_tenant`: una fila por envío, y el `UNIQUE` sobre
     * `id_pedido` es la garantía de "una sola vez" a nivel de base, no de código.
     *
     * Guarda el `importe_envio` y el `porcentaje_aplicado` del momento de entregar (RN-04): cambiar
     * después el porcentaje del tenant no recalcula nada. Sin `ambiente`: TEST y LIVE generan
     * comisión por igual (supuesto 4). Se crea vacía: no hay relleno retroactivo (RN-16).
     */
    public function up(): void
    {
        Schema::create('comisiones_conductor', function (Blueprint $table) {
            $table->id('id_comision');

            $table->foreignId('id_conductor')->constrained('conductores', 'id_conductor')->cascadeOnDelete();
            $table->foreignId('id_pedido')->unique()->constrained('pedidos', 'id_pedido')->cascadeOnDelete();
            $table->foreignId('id_liquidacion')->nullable()->constrained('liquidaciones_comision', 'id_liquidacion')->nullOnDelete();

            $table->decimal('importe_envio', 10, 2);
            $table->decimal('porcentaje_aplicado', 5, 2);
            $table->decimal('importe_comision', 10, 2);
            $table->enum('estado', ['PENDIENTE', 'PAGADA'])->default('PENDIENTE');
            $table->timestamp('pagada_en')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['id_conductor', 'estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comisiones_conductor');
    }
};
