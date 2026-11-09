<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La hoja del pedido que se arma por WhatsApp (spec tenant/031, D18), y lo que un mensaje trae
 * además del texto: una ubicación compartida o el botón que se pulsó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_solicitudes', function (Blueprint $table) {
            $table->id('id_solicitud');
            $table->foreignId('id_conversacion')->constrained('whatsapp_conversaciones', 'id_conversacion')->cascadeOnDelete();
            $table->enum('estado', ['ABIERTA', 'CONFIRMANDO', 'CONFIRMADA', 'CANCELADA', 'DESCARTADA'])->default('ABIERTA');
            // Lo último que preguntó el bot: así una ubicación o un "sí" se entienden como respuesta.
            $table->string('paso')->nullable();

            $table->string('destinatario_nombre')->nullable();
            $table->string('destinatario_telefono', 20)->nullable();

            // Lo que escribió el cliente y, si Google lo ubicó con precisión, la dirección y sus coordenadas (RN-38).
            $table->string('entrega_texto')->nullable();
            $table->string('entrega_direccion')->nullable();
            $table->decimal('entrega_lat', 10, 7)->nullable();
            $table->decimal('entrega_lng', 10, 7)->nullable();
            $table->string('recogida_texto')->nullable();
            $table->string('recogida_direccion')->nullable();
            $table->decimal('recogida_lat', 10, 7)->nullable();
            $table->decimal('recogida_lng', 10, 7)->nullable();
            // La dirección guardada que el bot ofreció como recogida (RN-40).
            $table->unsignedBigInteger('id_direccion_ofrecida')->nullable();

            $table->enum('quien_paga', ['remitente', 'destinatario'])->nullable();
            $table->enum('cobro_producto', ['si', 'no'])->nullable();
            $table->decimal('importe_producto', 10, 2)->nullable();
            $table->decimal('importe_envio', 10, 2)->nullable();
            $table->decimal('km', 8, 2)->nullable();
            // RN-13: solo cuando Meta no manda el teléfono de quien escribe.
            $table->string('cliente_telefono', 20)->nullable();

            // RN-20: una solicitud crea a lo más un pedido.
            $table->foreignId('id_pedido')->nullable()->unique()->constrained('pedidos', 'id_pedido')->nullOnDelete();
            $table->timestamps();

            $table->index(['id_conversacion', 'estado']);
        });

        Schema::table('whatsapp_mensajes', function (Blueprint $table) {
            $table->decimal('latitud', 10, 7)->nullable()->after('texto');
            $table->decimal('longitud', 10, 7)->nullable()->after('latitud');
            $table->string('boton')->nullable()->after('longitud');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_mensajes', function (Blueprint $table) {
            $table->dropColumn(['latitud', 'longitud', 'boton']);
        });

        Schema::dropIfExists('whatsapp_solicitudes');
    }
};
