<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant as TenantModel;
use App\Models\Tenant\Usuario;
use App\Notifications\CredencialesAdminCliente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class CrearAdminClienteInicial implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly TenantModel $tenant,
        private readonly string $nombre,
        private readonly string $apellidoPaterno,
        private readonly ?string $apellidoMaterno,
    ) {}

    public function handle(): void
    {
        try {
            tenancy()->initialize($this->tenant);

            $password = Str::password(16);

            $usuario = Usuario::create([
                'nombre' => $this->nombre,
                'apellido_paterno' => $this->apellidoPaterno,
                'apellido_materno' => $this->apellidoMaterno,
                'email' => $this->tenant->email,
                'password' => $password,
                'rol' => 'AdminCliente',
                'estado' => 'Activo',
            ]);

            Notification::route('mail', $usuario->email)
                ->notify(new CredencialesAdminCliente($this->tenant->nombre_comercial, $usuario->email, $password));
        } catch (\Throwable $e) {
            Log::error('No se pudo crear el AdminCliente inicial del tenant', [
                'id_tenant' => $this->tenant->id_tenant,
                'error' => $e->getMessage(),
            ]);
        } finally {
            tenancy()->end();
        }
    }
}
