<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suscripciones', function (Blueprint $table) {
            $table->id('id_suscripcion');

            $table->foreignId('id_tenant')->constrained('tenants', 'id_tenant')->cascadeOnDelete();
            $table->foreignId('id_plan')->constrained('planes', 'id_plan');

            $table->date('fecha_inicio');
            $table->date('fecha_vencimiento');

            $table->enum('estado', ['ACTIVA', 'VENCIDA', 'SUSPENDIDA', 'CANCELADA'])->default('ACTIVA');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suscripciones');
    }
};
