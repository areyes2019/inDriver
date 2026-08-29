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
            $table->boolean('prepago_descontado')->default(false)->after('importe_cobro');
            $table->decimal('comision_calculada', 10, 2)->nullable()->after('prepago_descontado');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn(['prepago_descontado', 'comision_calculada']);
        });
    }
};
