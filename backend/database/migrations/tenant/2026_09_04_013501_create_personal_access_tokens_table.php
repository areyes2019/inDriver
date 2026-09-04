<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `Usuario` (rol Conductor) autentica la app panda_express con un token de Sanctum
     * (spec tenant/013). Como `Usuario` es un modelo de tenant, esta tabla debe existir en la base
     * de cada tenant — la migración equivalente de Sanctum vive en la base central y no sirve aquí,
     * mismo motivo por el que `password_reset_tokens` también está duplicada en este directorio.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
