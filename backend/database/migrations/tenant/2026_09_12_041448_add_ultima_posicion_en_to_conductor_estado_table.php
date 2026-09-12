<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conductor_estado', function (Blueprint $table) {
            $table->dateTime('ultima_posicion_en')->nullable()->after('ultima_actualizacion');
        });

        // Las filas que ya existen arrancan con el valor que RN-03 venía usando: es lo más cercano
        // a la verdad que hay, y en la siguiente posición queda correcto.
        DB::table('conductor_estado')->update(['ultima_posicion_en' => DB::raw('ultima_actualizacion')]);
    }

    public function down(): void
    {
        Schema::table('conductor_estado', function (Blueprint $table) {
            $table->dropColumn('ultima_posicion_en');
        });
    }
};
