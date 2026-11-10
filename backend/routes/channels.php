<?php

declare(strict_types=1);

use App\Models\Tenant\Usuario;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Canal privado por tenant (spec tenant/013): un conductor conectado solo puede escuchar los
| avisos de pedidos de su propio tenant. `$usuario` lo resuelve el guard que autenticó la petición
| a /conductor/broadcasting/auth (`conductor-token`, ver routes/api.php); se compara contra el slug
| (no el id numérico) porque panda_express ya lo conoce en build-time y el tenant actual, ya
| inicializado por el middleware `tenant.slug` de esa misma petición, expone su slug directo.
*/
Broadcast::channel('tenant.{slug}.conductores', function (Usuario $usuario, string $slug) {
    return tenant()?->slug === $slug;
});

/*
| Canal del Panel (spec tenant/031, D22): la bandeja de WhatsApp. Un conductor también es un
| `Usuario`, así que aquí se filtra por rol: las conversaciones con clientes no llegan a la app.
*/
Broadcast::channel('tenant.{slug}.panel', function (Usuario $usuario, string $slug) {
    return tenant()?->slug === $slug && in_array($usuario->rol, ['AdminCliente', 'Despachador'], true);
});
