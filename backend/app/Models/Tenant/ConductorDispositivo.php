<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_conductor', 'fcm_token', 'updated_at'])]
class ConductorDispositivo extends Model
{
    use HasUlids;

    protected $table = 'conductor_dispositivos';

    public $timestamps = false;

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Conductor::class, 'id_conductor', 'id_conductor');
    }
}
