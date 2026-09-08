<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\ContextoAmbiente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * El Panel ve solo los envíos de su ambiente (spec tenant/025, RN-05), sin que ninguna consulta
 * tenga que acordarse de filtrar.
 *
 * Si nadie fijó el ambiente en `ContextoAmbiente`, este scope **no hace nada**. Es intencional:
 * fuera del Panel —el comando `simulacion:avanzar`, los jobs diferidos, la app del conductor, los
 * comandos de mantenimiento— no existe "el ambiente en el que estoy parado", y filtrar ahí dejaría
 * ciego al propio simulador sobre los envíos que tiene que mover.
 */
class AmbienteScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $contexto = app(ContextoAmbiente::class);

        if (! $contexto->hayAmbiente()) {
            return;
        }

        $builder->where($model->qualifyColumn('ambiente'), $contexto->actual());
    }
}
