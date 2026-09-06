<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conductor virtual del "modo prueba" del Panel: un `Conductor` real (mismo modelo, mismas
     * reglas) que solo se distingue por esta bandera, para no duplicar la máquina de estados ni el
     * protocolo de tiempo real con un camino paralelo falso.
     */
    public function up(): void
    {
        Schema::table('conductores', function (Blueprint $table) {
            $table->boolean('es_prueba')->default(false)->after('disponibilidad');
        });
    }

    public function down(): void
    {
        Schema::table('conductores', function (Blueprint $table) {
            $table->dropColumn('es_prueba');
        });
    }
};
