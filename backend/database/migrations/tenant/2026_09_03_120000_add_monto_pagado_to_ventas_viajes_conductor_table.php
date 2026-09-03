<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas_viajes_conductor', function (Blueprint $table) {
            $table->decimal('monto_pagado', 10, 2)->nullable()->after('cantidad_viajes');
        });
    }

    public function down(): void
    {
        Schema::table('ventas_viajes_conductor', function (Blueprint $table) {
            $table->dropColumn('monto_pagado');
        });
    }
};
