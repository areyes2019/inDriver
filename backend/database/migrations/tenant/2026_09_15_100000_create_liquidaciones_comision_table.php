<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Libreta de pagos de comisión del tenant a cada conductor (spec tenant/030). Solo inserción:
     * una liquidación registrada no se edita ni se borra, para que "pagado" siempre sea la suma de
     * estas filas y el historial cuadre. Es registro interno: el sistema no mueve dinero.
     *
     * Sin `ambiente` a propósito (spec tenant/030, supuesto 4): comisiones TEST y LIVE se liquidan
     * en la misma bolsa mientras el sistema está en pruebas.
     */
    public function up(): void
    {
        Schema::create('liquidaciones_comision', function (Blueprint $table) {
            $table->id('id_liquidacion');

            $table->foreignId('id_conductor')->constrained('conductores', 'id_conductor')->cascadeOnDelete();
            $table->foreignId('id_usuario')->nullable()->constrained('usuarios', 'id_usuario')->nullOnDelete();

            $table->decimal('monto', 10, 2);
            $table->string('referencia', 120)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['id_conductor', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidaciones_comision');
    }
};
