<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spec tenant/035, sección 7: acceso a la app de clientes. Solo agrega columnas nulas.
 *
 * `email_acceso` es aparte de `email` (texto libre, puede repetirse) para que el índice único no
 * choque con los datos que ya existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('email_acceso', 191)->nullable()->unique();
            $table->timestamp('email_verificado_en')->nullable();
            $table->string('telefono_e164', 16)->nullable()->unique();
            $table->timestamp('telefono_verificado_en')->nullable();
            $table->enum('origen', ['PANEL', 'WHATSAPP', 'APP'])->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropUnique(['email_acceso']);
            $table->dropUnique(['telefono_e164']);
            $table->dropColumn([
                'email_acceso',
                'email_verificado_en',
                'telefono_e164',
                'telefono_verificado_en',
                'origen',
            ]);
        });
    }
};
