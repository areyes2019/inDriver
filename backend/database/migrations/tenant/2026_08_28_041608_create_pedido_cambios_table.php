<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_cambios', function (Blueprint $table) {
            $table->id('id_cambio');

            $table->foreignId('id_pedido')->constrained('pedidos', 'id_pedido')->cascadeOnDelete();
            $table->foreignId('id_usuario')->nullable()->constrained('usuarios', 'id_usuario')->nullOnDelete();

            $table->enum('tipo', [
                'DIRECCION_RECOGIDA', 'DIRECCION_ENTREGA', 'HORARIO', 'FECHA_SERVICIO',
                'MODALIDAD_PAGO', 'IMPORTE', 'CANCELACION', 'OTRO',
            ]);
            $table->string('campo')->nullable();
            $table->string('valor_anterior')->nullable();
            $table->string('valor_nuevo')->nullable();
            $table->string('motivo')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_cambios');
    }
};
