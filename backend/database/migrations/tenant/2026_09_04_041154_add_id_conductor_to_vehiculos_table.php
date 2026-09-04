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
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->foreignId('id_conductor')->nullable()->unique()->after('id_vehiculo')
                ->constrained('conductores', 'id_conductor')->cascadeOnDelete();
        });

        // Cada conductor tenía, cuando mucho, una fila `activo = true` en `conductor_vehiculo`.
        // Ese es el vehículo que conserva; el resto (vehículos históricos ya reemplazados) se
        // descarta, según lo acordado en `tenant/004-vehiculo-del-conductor.md`.
        DB::table('conductor_vehiculo')
            ->where('activo', true)
            ->get(['id_conductor', 'id_vehiculo'])
            ->each(function ($fila) {
                DB::table('vehiculos')
                    ->where('id_vehiculo', $fila->id_vehiculo)
                    ->update(['id_conductor' => $fila->id_conductor]);
            });

        DB::table('vehiculos')->whereNull('id_conductor')->delete();

        Schema::dropIfExists('conductor_vehiculo');
    }

    public function down(): void
    {
        Schema::create('conductor_vehiculo', function (Blueprint $table) {
            $table->id();

            $table->foreignId('id_conductor')->constrained('conductores', 'id_conductor')->cascadeOnDelete();
            $table->foreignId('id_vehiculo')->constrained('vehiculos', 'id_vehiculo')->cascadeOnDelete();

            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();
        });

        Schema::table('vehiculos', function (Blueprint $table) {
            $table->dropUnique(['id_conductor']);
            $table->dropConstrainedForeignId('id_conductor');
        });
    }
};
