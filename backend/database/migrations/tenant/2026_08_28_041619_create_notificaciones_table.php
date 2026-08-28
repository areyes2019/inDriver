<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id('id_notificacion');

            $table->foreignId('id_usuario')->constrained('usuarios', 'id_usuario')->cascadeOnDelete();
            $table->foreignId('id_pedido')->nullable()->constrained('pedidos', 'id_pedido')->nullOnDelete();

            $table->enum('tipo', [
                'NUEVO_PEDIDO', 'PEDIDO_ASIGNADO', 'PEDIDO_CANCELADO', 'PEDIDO_ENTREGADO', 'NUEVA_ASIGNACION',
            ]);
            $table->string('titulo');
            $table->text('mensaje')->nullable();
            $table->boolean('leida')->default(false);
            $table->dateTime('fecha_lectura')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
