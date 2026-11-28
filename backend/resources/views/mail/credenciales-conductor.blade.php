{{-- Correo de bienvenida del conductor (spec tenant/036, RN-11 a RN-15). Tablas y estilos en línea: Gmail y Outlook ignoran <style>. --}}
<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tu acceso como repartidor de {{ $flotilla }}</title>
</head>
<body style="margin:0;padding:0;background:#f7f6fb;font-family:Arial,Helvetica,sans-serif;color:#1b1530;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f6fb;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:20px;overflow:hidden;">
                    <tr>
                        <td align="center" style="background:#5b21b6;padding:28px 28px 22px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    @if ($logoUrl)
                                        <td align="center" valign="middle" width="96" height="96" style="width:96px;height:96px;background:#ffffff;border-radius:48px;">
                                            <img src="{{ $logoUrl }}" alt="{{ $flotilla }}" width="72" height="72" style="display:block;width:72px;height:72px;border:0;border-radius:36px;color:#5b21b6;font-size:14px;font-weight:bold;">
                                        </td>
                                    @else
                                        <td align="center" valign="middle" width="96" height="96" style="width:96px;height:96px;background:#facc15;border-radius:48px;color:#5b21b6;font-size:44px;font-weight:bold;line-height:96px;">{{ $inicial }}</td>
                                    @endif
                                </tr>
                            </table>
                            <p style="margin:14px 0 0;color:#ffffff;font-size:20px;font-weight:bold;">{{ $flotilla }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 12px;font-size:20px;font-weight:bold;color:#1b1530;">{{ $nombre ? "¡Bienvenido, {$nombre}!" : '¡Bienvenido!' }}</p>
                            <p style="margin:0 0 20px;font-size:15px;line-height:22px;color:#4a4560;">Ya se creó tu acceso como repartidor de <strong>{{ $flotilla }}</strong>.</p>

                            @if ($urlPlayStore)
                                <p style="margin:0 0 14px;font-size:15px;line-height:22px;color:#4a4560;">Descarga la app <strong>Panda Express</strong> en Google Play:</p>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                                    <tr>
                                        <td align="center">
                                            <a href="{{ $urlPlayStore }}" style="display:inline-block;background:#facc15;color:#1e0b4b;font-size:16px;font-weight:bold;text-decoration:none;padding:14px 28px;border-radius:12px;">Descargar en Google Play</a>
                                        </td>
                                    </tr>
                                </table>
                            @else
                                <p style="margin:0 0 20px;font-size:15px;line-height:22px;color:#4a4560;">Busca la app <strong>Panda Express</strong> en Google Play y descárgala.</p>
                            @endif

                            <p style="margin:0 0 10px;font-size:15px;line-height:22px;color:#4a4560;">Al abrirla, entra con estos datos:</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background:#f5f3ff;border-left:4px solid #facc15;border-radius:10px;">
                                <tr>
                                    <td style="padding:16px 18px;font-size:15px;line-height:24px;color:#1b1530;">
                                        <span style="color:#6b6780;">Código de flotilla:</span>
                                        <span data-campo="slug" style="font-family:'Courier New',Courier,monospace;font-weight:bold;color:#5b21b6;">{{ $slug }}</span><br>
                                        <span style="color:#6b6780;">Correo:</span>
                                        <span data-campo="email" style="color:#1b1530;text-decoration:none;">{{ $email }}</span><br>
                                        <span style="color:#6b6780;">Contraseña:</span>
                                        <span data-campo="password" style="font-family:'Courier New',Courier,monospace;font-weight:bold;color:#1b1530;">{{ $password }}</span>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 24px;font-size:14px;line-height:20px;color:#6b6780;">Te recomendamos cambiar tu contraseña después de entrar.</p>

                            <p style="margin:0;font-size:15px;line-height:22px;color:#4a4560;">Saludos,<br><strong style="color:#1b1530;">El equipo de {{ $flotilla }}</strong></p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:16px 28px;border-top:1px solid #e9e6f2;font-size:12px;color:#8a8699;">Enviado por Moto Panda · motopanda.mx</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
