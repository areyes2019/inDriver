<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id('id_pedido');
            $table->string('numero_pedido')->unique();

            $table->foreignId('id_cliente')->nullable()->constrained('clientes', 'id_cliente')->nullOnDelete();

            $table->string('nombre_solicitante');
            $table->string('telefono_solicitante');

            $table->string('direccion_recogida');
            $table->decimal('latitud_recogida', 10, 7)->nullable();
            $table->decimal('longitud_recogida', 10, 7)->nullable();

            $table->string('direccion_entrega');
            $table->decimal('latitud_entrega', 10, 7)->nullable();
            $table->decimal('longitud_entrega', 10, 7)->nullable();

            $table->date('fecha_servicio');
            $table->time('hora_desde')->nullable();
            $table->time('hora_hasta')->nullable();
            $table->boolean('lo_antes_posible')->default(false);

            $table->enum('modalidad_pago', [
                'RECEPTOR_PAGA_ENVIO',
                'REMITENTE_PAGA_ENVIO',
                'RECEPTOR_PAGA_ENVIO_PRODUCTOS',
            ]);
            $table->decimal('importe_envio', 10, 2)->default(0);
            $table->decimal('importe_cobro', 10, 2)->default(0);

            $table->foreignId('id_despachador')->nullable()->constrained('despachadores', 'id_despachador')->nullOnDelete();
            $table->foreignId('id_conductor')->nullable()->constrained('conductores', 'id_conductor')->nullOnDelete();
            $table->foreignId('id_vehiculo')->nullable()->constrained('vehiculos', 'id_vehiculo')->nullOnDelete();

            $table->enum('estado', [
                'PENDIENTE', 'PUBLICADO', 'TOMADO', 'ARRIBADO', 'EN_CAMINO',
                'ARRIBADO_A_ENTREGA', 'ENTREGADO', 'RECHAZADO', 'CANCELADO',
            ])->default('PENDIENTE');

            $table->dateTime('fecha_publicacion')->nullable();
            $table->dateTime('fecha_asignacion')->nullable();
            $table->dateTime('fecha_entrega')->nullable();
            $table->dateTime('fecha_cancelacion')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
