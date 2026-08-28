<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id('id_pago');

            $table->foreignId('id_pedido')->constrained('pedidos', 'id_pedido')->cascadeOnDelete();

            $table->enum('metodo_pago', ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA']);
            $table->decimal('monto', 10, 2);
            $table->string('referencia_transaccion')->nullable();
            $table->dateTime('fecha_pago');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
