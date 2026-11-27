<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\Tenant\Pedido;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * El modo TEST se eliminó: todo envío nuevo nace LIVE. Los envíos de prueba que quedaron en la base
 * no se borran, pero ya no los ve nadie —ni el Panel, ni la app del conductor, ni los jobs—.
 *
 * Quien necesite contarlos a propósito usa `withoutGlobalScope(self::class)`: el saldo prepagado
 * del conductor (esos viajes sí le descontaron dinero de verdad) y el detalle de comisiones.
 */
class OcultarEnviosDePrueba implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('ambiente'), '!=', Pedido::AMBIENTE_TEST);
    }
}
