<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** spec tenant/035, RN-11: códigos de acceso de un solo uso. El código nunca se guarda en claro. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_codigos', function (Blueprint $table) {
            $table->id('id_codigo');
            $table->string('email', 191)->index();
            $table->string('codigo_hmac', 64);
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->timestamp('expira_en');
            $table->timestamp('usado_en')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_codigos');
    }
};
