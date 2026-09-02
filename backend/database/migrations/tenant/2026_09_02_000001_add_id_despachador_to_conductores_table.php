<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conductores', function (Blueprint $table) {
            $table->foreignId('id_despachador')->nullable()->after('id_usuario')
                ->constrained('despachadores', 'id_despachador')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conductores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_despachador');
        });
    }
};
