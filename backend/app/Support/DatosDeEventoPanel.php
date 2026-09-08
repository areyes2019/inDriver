<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Resources\Tenant\ConductorActivoResource;
use App\Models\Tenant\Conductor;
use App\Models\Tenant\Pedido;

/**
 * Arma lo que los eventos del canal del tenant tienen que llevar para que el Panel pinte sin
 * volver a preguntar (spec tenant/027, §5).
 *
 * Hasta la 027 los eventos mandaban un `id_pedido` suelto y el Panel respondía recargando la lista
 * completa —tres componentes a la vez, contra un limitador de 20 peticiones por minuto—. Ahora la
 * carga trae la fila ya resuelta y el Panel la aplica en memoria; este es el único lugar que la
 * arma, para que el evento y el endpoint no puedan discrepar.
 *
 * Todas las consultas van `withoutGlobalScopes()` a propósito: un evento nace lo mismo en una
 * petición del Panel —donde `AmbienteScope` está encendido— que en una de la app del conductor,
 * donde no lo está (spec tenant/025). Sin quitarlo, el mismo evento tendría carga completa o vacía
 * según quién lo disparó. El filtrado por ambiente lo hace el Panel con el campo `ambiente` que
 * viaja en la propia carga (027, RN-28).
 */
class DatosDeEventoPanel
{
    /**
     * El pedido, sin el filtro de ambiente. `null` si ya no existe.
     */
    public static function pedido(int $idPedido): ?Pedido
    {
        return Pedido::withoutGlobalScopes()
            ->with('conductor.usuario')
            ->find($idPedido);
    }

    /**
     * Lo que una fila de "Viajes en turno" necesita para actualizarse: estado, a quién quedó
     * asignada, su línea en el mapa y el ambiente al que pertenece.
     *
     * @return array<string, mixed>
     */
    public static function delPedido(int $idPedido): array
    {
        $pedido = self::pedido($idPedido);

        if ($pedido === null) {
            return [];
        }

        return [
            'estado' => $pedido->estado,
            'ambiente' => $pedido->ambiente,
            'id_conductor' => $pedido->id_conductor,
            'conductor_nombre' => $pedido->conductor?->usuario !== null
                ? trim("{$pedido->conductor->usuario->nombre} {$pedido->conductor->usuario->apellido_paterno}")
                : null,
            'seguimiento' => SeguimientoEnvio::para($pedido),
        ];
    }

    /**
     * Viajes prepagados que le quedan al conductor. `null` cuando no hay conductor —el envío no
     * está asignado— para que el Panel distinga "no aplica" de "cero viajes" y no pise con un 0 el
     * saldo que ya tenía en pantalla.
     */
    public static function saldoDeConductor(?int $idConductor): ?int
    {
        if ($idConductor === null) {
            return null;
        }

        $conductor = Conductor::query()->find($idConductor);

        return $conductor === null ? null : SaldoViajes::para($conductor);
    }

    /**
     * La fila completa de la flotilla, idéntica a la que devuelve `GET /conductores/activos`.
     *
     * Va dentro de `conductor.disponibilidad-cambiada` porque insertar a alguien que se acaba de
     * conectar exige su nombre, su vehículo, su saldo, su color y su posición: con solo el id, el
     * Panel no tendría más remedio que recargar la lista, que es lo que la 027 elimina.
     *
     * @return array<string, mixed>|null
     */
    public static function conductorActivo(int $idConductor): ?array
    {
        $conductor = Conductor::query()
            ->whereKey($idConductor)
            ->conDatosDePanel()
            ->first();

        if ($conductor === null || $conductor->usuario === null) {
            return null;
        }

        return (new ConductorActivoResource($conductor))->resolve();
    }
}
