<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn([
                'latitud_recogida',
                'longitud_recogida',
                'latitud_entrega',
                'longitud_entrega',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->decimal('latitud_recogida', 10, 7)->nullable();
            $table->decimal('longitud_recogida', 10, 7)->nullable();
            $table->decimal('latitud_entrega', 10, 7)->nullable();
            $table->decimal('longitud_entrega', 10, 7)->nullable();
        });
    }
};
