<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ambiente del envío (spec tenant/025, RN-03): se sella al crearlo copiando el interruptor
     * TEST/LIVE del tenant y no vuelve a cambiar nunca. Todo lo existente es `live`, que además es
     * la omisión: un tenant que jamás toque el interruptor se comporta exactamente como hoy.
     */
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->enum('ambiente', ['live', 'test'])->default('live')->after('estado')->index();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn('ambiente');
        });
    }
};
