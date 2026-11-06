<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Máquina de estados de la spec tenant/006: `INCIDENCIA` reemplaza a `RECHAZADO`.
 *
 * Nada dispara `RECHAZADO` desde la interfaz, pero la API lo aceptaba, así que una fila con ese
 * estado no es imposible. Se convierte en `CANCELADO`, el otro cierre sin entrega, para que el
 * enum nuevo no la deje fuera.
 */
return new class extends Migration
{
    private const BASE = ['PENDIENTE', 'PUBLICADO', 'TOMADO', 'ARRIBADO', 'EN_CAMINO', 'ARRIBADO_A_ENTREGA', 'ENTREGADO', 'CANCELADO'];

    public function up(): void
    {
        $this->cambiarEnum([...self::BASE, 'RECHAZADO', 'INCIDENCIA']);

        DB::table('pedidos')->where('estado', 'RECHAZADO')->update([
            'estado' => 'CANCELADO',
            'fecha_cancelacion' => DB::raw('COALESCE(fecha_cancelacion, updated_at)'),
        ]);

        $this->cambiarEnum([...self::BASE, 'INCIDENCIA']);
    }

    public function down(): void
    {
        $this->cambiarEnum([...self::BASE, 'RECHAZADO', 'INCIDENCIA']);

        DB::table('pedidos')->where('estado', 'INCIDENCIA')->update(['estado' => 'CANCELADO']);

        $this->cambiarEnum([...self::BASE, 'RECHAZADO']);
    }

    /**
     * @param  array<int, string>  $estados
     */
    private function cambiarEnum(array $estados): void
    {
        Schema::table('pedidos', function (Blueprint $table) use ($estados) {
            $table->enum('estado', $estados)->default('PENDIENTE')->change();
        });
    }
};
