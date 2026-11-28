{{-- Texto plano: sin escapar, para que la contraseña salga tal cual aunque traiga < o & (spec tenant/036, RN-15). --}}
{!! $nombre ? "¡Bienvenido, {$nombre}!" : '¡Bienvenido!' !!}

Ya se creó tu acceso como repartidor de {!! $flotilla !!}.

@if ($urlPlayStore)
Descarga la app Panda Express en Google Play:
{!! $urlPlayStore !!}
@else
Busca la app Panda Express en Google Play y descárgala.
@endif

Al abrirla, entra con estos datos:

Código de flotilla: {!! $slug !!}
Correo: {!! $email !!}
Contraseña: {!! $password !!}

Te recomendamos cambiar tu contraseña después de entrar.

Saludos,
El equipo de {!! $flotilla !!}

--
Enviado por Moto Panda · motopanda.mx
