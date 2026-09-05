<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guarda el token FCM del dispositivo de cada conductor, para poder mandarle push cuando el
     * socket de Reverb está caído (spec tenant/018). Un solo registro por conductor: al iniciar
     * sesión en panda_express se sobreescribe el token anterior.
     */
    public function up(): void
    {
        Schema::create('conductor_dispositivos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('id_conductor')->unique()->constrained('conductores', 'id_conductor')->cascadeOnDelete();
            $table->string('fcm_token', 255);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conductor_dispositivos');
    }
};
