<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_asignaciones', function (Blueprint $table) {
            $table->id('id_asignacion');

            $table->foreignId('id_pedido')->constrained('pedidos', 'id_pedido')->cascadeOnDelete();
            $table->foreignId('id_despachador')->nullable()->constrained('despachadores', 'id_despachador')->nullOnDelete();
            $table->foreignId('id_conductor')->constrained('conductores', 'id_conductor')->cascadeOnDelete();
            $table->foreignId('id_vehiculo')->nullable()->constrained('vehiculos', 'id_vehiculo')->nullOnDelete();

            $table->dateTime('fecha_asignacion');
            $table->dateTime('fecha_respuesta')->nullable();

            $table->enum('estado', ['PENDIENTE', 'ACEPTADA', 'RECHAZADA', 'EXPIRADA', 'CANCELADA', 'FINALIZADA'])
                ->default('PENDIENTE');
            $table->string('motivo')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_asignaciones');
    }
};
