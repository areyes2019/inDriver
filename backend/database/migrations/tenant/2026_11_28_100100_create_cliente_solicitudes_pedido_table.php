<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spec tenant/035, RN-31: la llave anti-duplicados de la app. El índice único hace que, de dos
 * confirmaciones con la misma llave, solo una cree el pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_solicitudes_pedido', function (Blueprint $table) {
            $table->id('id_solicitud');
            $table->foreignId('id_cliente')->constrained('clientes', 'id_cliente')->cascadeOnDelete();
            $table->uuid('llave');
            $table->string('huella', 64);
            $table->foreignId('id_pedido')->nullable()->constrained('pedidos', 'id_pedido')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->index();

            $table->unique(['id_cliente', 'llave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_solicitudes_pedido');
    }
};
