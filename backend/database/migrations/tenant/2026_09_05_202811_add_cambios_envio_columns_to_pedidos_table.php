<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->enum('cancelado_por', ['CLIENTE', 'ADMIN'])->nullable()->after('fecha_cancelacion');
            $table->string('motivo_cancelacion', 120)->nullable()->after('cancelado_por');

            // Confirmación de la App al cambio más reciente (spec tenant/022): cancelación,
            // reprogramación o reubicación. Se limpia cada vez que hay un cambio nuevo que
            // confirmar; `AvisarSinConfirmar` revisa si sigue nula a los 60s (RN-03).
            $table->timestamp('notificado_conductor_en')->nullable()->after('motivo_cancelacion');

            $table->unsignedTinyInteger('conteo_reubicaciones')->default(0)->after('notificado_conductor_en');
            $table->decimal('cargo_extra', 10, 2)->default(0)->after('conteo_reubicaciones');
            $table->decimal('pago_extra', 10, 2)->default(0)->after('cargo_extra');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn([
                'cancelado_por',
                'motivo_cancelacion',
                'notificado_conductor_en',
                'conteo_reubicaciones',
                'cargo_extra',
                'pago_extra',
            ]);
        });
    }
};
