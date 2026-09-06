<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot de `ConfiguracionTenant::MODO_PRUEBA` al crear el pedido (no una lectura en vivo
     * del config): si el switch cambia a medio envío, el pedido conserva el modo con el que nació,
     * para que la liquidación al entregar y el tracking simulado sean consistentes de principio a
     * fin ("PWA agnóstica a LIVE/TEST").
     */
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->boolean('es_prueba')->default(false)->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn('es_prueba');
        });
    }
};
