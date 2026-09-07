<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retiro del "modo prueba" antes de salir a producción: ni los conductores virtuales del Panel ni
 * los pedidos simulados existen ya, así que la marca que los distinguía de los reales sobra.
 *
 * Se comprueba la existencia de la columna porque hay bases que nunca llegaron a correr las
 * migraciones que la agregaban.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('conductores', 'es_prueba')) {
            Schema::table('conductores', function (Blueprint $table) {
                $table->dropColumn('es_prueba');
            });
        }

        if (Schema::hasColumn('pedidos', 'es_prueba')) {
            Schema::table('pedidos', function (Blueprint $table) {
                $table->dropColumn('es_prueba');
            });
        }

        // El interruptor que vivía en `configuraciones_tenant`: ya no lo lee nadie, y dejarlo haría
        // creer que la opción sigue existiendo.
        DB::table('configuraciones_tenant')->where('clave', 'modo_prueba')->delete();
    }

    public function down(): void
    {
        Schema::table('conductores', function (Blueprint $table) {
            $table->boolean('es_prueba')->default(false)->after('disponibilidad');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->boolean('es_prueba')->default(false)->after('estado');
        });
    }
};
