<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs_centrales', function (Blueprint $table) {
            $table->id('id_log');

            $table->foreignId('id_tenant')->nullable()->constrained('tenants', 'id_tenant')->nullOnDelete();
            $table->foreignId('id_admin')->nullable()->constrained('admins_centrales', 'id_admin')->nullOnDelete();

            $table->string('tipo');
            $table->string('accion');
            $table->text('descripcion')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_centrales');
    }
};
