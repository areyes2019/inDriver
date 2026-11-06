<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regreso del paquete cuando el comprador no paga (spec tenant/031): dos estados nuevos y la tarifa
 * que se le cobra al vendedor, que se fija al iniciar el regreso.
 */
return new class extends Migration
{
    private const BASE = ['PENDIENTE', 'PUBLICADO', 'TOMADO', 'ARRIBADO', 'EN_CAMINO', 'ARRIBADO_A_ENTREGA', 'ENTREGADO', 'CANCELADO', 'INCIDENCIA'];

    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->enum('estado', [...self::BASE, 'EN_REGRESO', 'DEVUELTO'])->default('PENDIENTE')->change();
            $table->decimal('tarifa_regreso', 10, 2)->default(0)->after('pago_extra');
        });
    }

    public function down(): void
    {
        DB::table('pedidos')->where('estado', 'EN_REGRESO')->update([
            'estado' => 'INCIDENCIA',
            'fecha_cancelacion' => DB::raw('COALESCE(fecha_cancelacion, updated_at)'),
        ]);
        DB::table('pedidos')->where('estado', 'DEVUELTO')->update(['estado' => 'ENTREGADO']);

        Schema::table('pedidos', function (Blueprint $table) {
            $table->enum('estado', self::BASE)->default('PENDIENTE')->change();
            $table->dropColumn('tarifa_regreso');
        });
    }
};
