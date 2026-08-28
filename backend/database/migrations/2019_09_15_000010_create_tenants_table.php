<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id('id_tenant');

            $table->string('nombre_comercial');
            $table->string('razon_social');
            $table->string('rfc')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();

            $table->string('calle')->nullable();
            $table->string('numero_int')->nullable();
            $table->string('numero_ext')->nullable();
            $table->string('colonia')->nullable();
            $table->string('cp', 10)->nullable();
            $table->string('ciudad')->nullable();
            $table->string('estado_direccion')->nullable();
            $table->string('pais')->nullable();

            $table->enum('estado', ['Activo', 'Suspendido', 'Inactivo'])->default('Activo');
            $table->enum('modo_estado', ['AUTOMATICO', 'MANUAL'])->default('AUTOMATICO');

            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_vencimiento')->nullable();

            $table->string('database_nombre')->nullable();
            $table->string('database_host')->nullable();
            $table->unsignedInteger('database_puerto')->nullable();
            $table->string('database_usuario')->nullable();
            $table->string('database_password')->nullable();

            $table->timestamps();
            $table->json('data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
}
