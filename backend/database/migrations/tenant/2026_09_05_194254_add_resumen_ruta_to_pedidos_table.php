<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lo que sobrevive a la purga de `conductor_posiciones` a los 7 días (spec tenant/021, RN-08):
     * un resumen barato para reclamaciones, calculado una sola vez al entregar.
     */
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->decimal('distancia_recorrida_km', 8, 2)->nullable()->after('fecha_entrega');
            $table->json('resumen_ruta')->nullable()->after('distancia_recorrida_km');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn(['distancia_recorrida_km', 'resumen_ruta']);
        });
    }
};
