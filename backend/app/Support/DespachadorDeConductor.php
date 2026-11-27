<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\ConfiguracionTenant;
use App\Models\Tenant\Despachador;
use Illuminate\Validation\ValidationException;

/**
 * A qué despachador queda asignado un conductor (spec tenant/011). Vivía en
 * `Tenant\ConductorController`; salió aquí al necesitarla también el alta de conductores desde
 * ADMIN_CENTRAL, para que las dos digan lo mismo. Espera la tenencia ya inicializada.
 */
class DespachadorDeConductor
{
    /**
     * El frontend envía `''` (no `null`) cuando el selector de despachador no aplica o no se ha
     * tocado — normaliza a `int|null` antes de pasarlo a un método con `strict_types`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function normalizar(array $data): ?int
    {
        $valor = $data['id_despachador'] ?? null;

        return ($valor === null || $valor === '') ? null : (int) $valor;
    }

    /**
     * Resuelve el `id_despachador` real a guardar, según cuántos despachadores `Activo` existen y
     * si el tenant usa despachadores: con 1 solo despachador activo, se ignora lo enviado y se
     * fuerza ese id; con 2+, es obligatorio solo si el conductor queda `ACTIVO`; con 0, no hay nada
     * que asignar. Con `usar_despachadores = No`, siempre se guarda `null`.
     */
    public static function resolver(?int $idDespachadorEnviado, string $estado): ?int
    {
        $usaDespachadores = ConfiguracionTenant::obtener(ConfiguracionTenant::USAR_DESPACHADORES, 'No') === 'Sí';

        if (! $usaDespachadores) {
            return null;
        }

        $despachadoresActivos = Despachador::where('estado', 'Activo')->pluck('id_despachador');

        if ($despachadoresActivos->count() === 1) {
            return $despachadoresActivos->first();
        }

        if ($despachadoresActivos->count() === 0) {
            return null;
        }

        if ($idDespachadorEnviado !== null && ! $despachadoresActivos->contains($idDespachadorEnviado)) {
            throw ValidationException::withMessages([
                'id_despachador' => 'El despachador seleccionado no existe o no está activo.',
            ]);
        }

        if ($estado === 'ACTIVO' && $idDespachadorEnviado === null) {
            throw ValidationException::withMessages([
                'id_despachador' => 'Selecciona el despachador responsable de este conductor.',
            ]);
        }

        return $idDespachadorEnviado;
    }
}
