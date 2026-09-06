<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Libro de movimientos de saldo (spec tenant/022, SPEC-023). Solo inserción: un error se
     * corrige con un AJUSTE en sentido contrario, nunca editando o borrando una fila, para que el
     * historial siempre cuadre con `conductores.saldo`.
     */
    public function up(): void
    {
        Schema::create('movimientos_saldo', function (Blueprint $table) {
            $table->id('id_movimiento');

            $table->foreignId('id_conductor')->constrained('conductores', 'id_conductor')->cascadeOnDelete();
            $table->foreignId('id_pedido')->nullable()->constrained('pedidos', 'id_pedido')->nullOnDelete();
            $table->foreignId('id_usuario')->nullable()->constrained('usuarios', 'id_usuario')->nullOnDelete();

            $table->enum('tipo', ['CREDITO', 'COMISION', 'COMPENSACION', 'AJUSTE']);
            $table->decimal('monto', 10, 2);
            $table->decimal('saldo_resultante', 10, 2);
            $table->string('referencia', 120)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['id_conductor', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_saldo');
    }
};
