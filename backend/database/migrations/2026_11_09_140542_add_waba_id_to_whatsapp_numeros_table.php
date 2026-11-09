<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La cuenta de WhatsApp Business (WABA) del número: las plantillas viven ahí y no en el número
 * (spec tenant/031, RN-53).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_numeros', function (Blueprint $table) {
            $table->string('waba_id')->nullable()->after('phone_number_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_numeros', function (Blueprint $table) {
            $table->dropColumn('waba_id');
        });
    }
};
