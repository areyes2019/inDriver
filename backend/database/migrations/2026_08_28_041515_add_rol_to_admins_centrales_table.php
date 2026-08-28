<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins_centrales', function (Blueprint $table) {
            $table->string('rol')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('admins_centrales', function (Blueprint $table) {
            $table->dropColumn('rol');
        });
    }
};
