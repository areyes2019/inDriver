<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `pedido_asignaciones` se creó para spec tenant/020 pero nunca llegó a usarse (sin modelo, sin
     * referencias): se reemplaza por `pedido_ofertas`, con el enum y las columnas que esa spec
     * necesita — una oferta por (pedido, conductor), con su propia ventana de expiración.
     */
    public function up(): void
    {
        Schema::dropIfExists('pedido_asignaciones');

        Schema::create('pedido_ofertas', function (Blueprint $table) {
            $table->id('id_oferta');

            $table->foreignId('id_pedido')->constrained('pedidos', 'id_pedido')->cascadeOnDelete();
            $table->foreignId('id_conductor')->constrained('conductores', 'id_conductor')->cascadeOnDelete();

            $table->enum('estado', ['PENDIENTE', 'ACEPTADA', 'RECHAZADA', 'EXPIRADA', 'PERDIDA'])
                ->default('PENDIENTE');

            $table->dateTime('ofrecida_en');
            $table->dateTime('respondida_en')->nullable();
            $table->dateTime('expira_en');

            $table->timestamps();

            $table->unique(['id_pedido', 'id_conductor']);
            $table->index(['estado', 'expira_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_ofertas');

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
};
