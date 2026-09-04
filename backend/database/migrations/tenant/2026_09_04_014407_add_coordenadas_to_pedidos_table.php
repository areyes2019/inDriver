<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Revierte parcialmente `2026_08_31_000003_remove_coordenadas_from_pedidos_table.php` (spec
     * tenant/006): se decidió entonces que las coordenadas de un pedido no alimentaban ningún
     * cálculo persistido. La spec tenant/013 (conexión con panda_express) sí las necesita: son la
     * referencia contra la que el conductor detecta por GPS que llegó al punto de recogida/entrega,
     * y lo que dibuja la ruta en su mapa. Se vuelven a agregar, nullables (un pedido puede quedar
     * con una dirección sin resolver por el autocompletado).
     */
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->decimal('latitud_recogida', 10, 7)->nullable()->after('direccion_recogida');
            $table->decimal('longitud_recogida', 10, 7)->nullable()->after('latitud_recogida');
            $table->decimal('latitud_entrega', 10, 7)->nullable()->after('direccion_entrega');
            $table->decimal('longitud_entrega', 10, 7)->nullable()->after('latitud_entrega');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn(['latitud_recogida', 'longitud_recogida', 'latitud_entrega', 'longitud_entrega']);
        });
    }
};
