<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `pedido_cambios` ya existía (2026_08_28) pensada como bitácora genérica campo por campo,
     * pero nunca se llegó a usar (sin modelo, sin referencias) — igual que `pedido_asignaciones`
     * para spec tenant/020. Se reemplaza por la bitácora de incidencias que pide spec tenant/022:
     * un registro por cambio (no por campo), con `actor_tipo` y un snapshot json de antes/después.
     */
    public function up(): void
    {
        Schema::dropIfExists('pedido_cambios');

        Schema::create('pedido_cambios', function (Blueprint $table) {
            $table->id('id_cambio');

            $table->foreignId('id_pedido')->constrained('pedidos', 'id_pedido')->cascadeOnDelete();
            $table->enum('tipo', ['CANCELADO', 'REPROGRAMADO', 'REUBICADO']);
            $table->json('valor_anterior')->nullable();
            $table->json('valor_nuevo')->nullable();
            $table->enum('actor_tipo', ['CLIENTE', 'ADMIN']);
            $table->foreignId('id_usuario')->nullable()->constrained('usuarios', 'id_usuario')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['id_pedido', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_cambios');

        Schema::create('pedido_cambios', function (Blueprint $table) {
            $table->id('id_cambio');

            $table->foreignId('id_pedido')->constrained('pedidos', 'id_pedido')->cascadeOnDelete();
            $table->foreignId('id_usuario')->nullable()->constrained('usuarios', 'id_usuario')->nullOnDelete();

            $table->enum('tipo', [
                'DIRECCION_RECOGIDA', 'DIRECCION_ENTREGA', 'HORARIO', 'FECHA_SERVICIO',
                'MODALIDAD_PAGO', 'IMPORTE', 'CANCELACION', 'OTRO',
            ]);
            $table->string('campo')->nullable();
            $table->string('valor_anterior')->nullable();
            $table->string('valor_nuevo')->nullable();
            $table->string('motivo')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }
};
