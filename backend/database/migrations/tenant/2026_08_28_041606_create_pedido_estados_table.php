<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_estados', function (Blueprint $table) {
            $table->id('id_estado');

            $table->foreignId('id_pedido')->constrained('pedidos', 'id_pedido')->cascadeOnDelete();
            $table->foreignId('id_usuario')->nullable()->constrained('usuarios', 'id_usuario')->nullOnDelete();

            $table->string('estado_anterior')->nullable();
            $table->string('estado_nuevo');
            $table->string('motivo')->nullable();
            $table->enum('origen', ['DESPACHADOR', 'CONDUCTOR', 'CLIENTE', 'SISTEMA', 'ADMIN_CLIENTE']);

            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_estados');
    }
};
