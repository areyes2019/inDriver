<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras_paquetes', function (Blueprint $table) {
            $table->id('id_compra');

            $table->string('codigo_paquete');
            $table->unsignedInteger('cantidad_paquetes')->default(1);
            $table->unsignedInteger('cantidad_viajes');
            $table->decimal('precio_unitario', 10, 2);
            $table->decimal('importe_total', 10, 2);
            $table->string('forma_pago')->nullable();

            $table->string('estado');
            $table->dateTime('fecha_compra');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras_paquetes');
    }
};
