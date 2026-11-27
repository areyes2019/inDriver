<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** spec tenant/035, RN-16 a RN-19: teléfonos que comparten varios clientes y esperan conciliación. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telefonos_en_revision', function (Blueprint $table) {
            $table->string('telefono_e164', 16)->primary();
            $table->json('clientes');
            $table->timestamp('detectado_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telefonos_en_revision');
    }
};
