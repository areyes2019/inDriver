<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo contacto desde motopanda.mx</title>
</head>
<body style="margin:0;padding:0;background:#f7f6fb;font-family:Arial,Helvetica,sans-serif;color:#1b1530;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f6fb;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:20px;overflow:hidden;">
                    <tr>
                        <td style="background:#5b21b6;padding:24px 28px;color:#ffffff;font-size:20px;font-weight:bold;">
                            Nuevo contacto desde motopanda.mx
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;font-size:15px;line-height:1.5;">
                            <p style="margin:0 0 6px;"><strong>Nombre:</strong> {{ $contacto->nombre }}</p>
                            <p style="margin:0 0 6px;"><strong>Flotilla o empresa:</strong> {{ $contacto->empresa ?: '—' }}</p>
                            <p style="margin:0 0 6px;"><strong>Teléfono:</strong> {{ $contacto->telefono }}</p>
                            <p style="margin:0 0 6px;"><strong>Correo:</strong> {{ $contacto->email }}</p>
                            <p style="margin:0 0 18px;"><strong>Motos:</strong> {{ $contacto->motos ?? '—' }}</p>
                            <p style="margin:0 0 6px;"><strong>Mensaje:</strong></p>
                            <p style="margin:0;white-space:pre-line;color:#3d3854;">{{ $contacto->mensaje ?: '—' }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 28px 24px;font-size:13px;color:#6b6780;">
                            Responde este correo para contestarle directamente.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
